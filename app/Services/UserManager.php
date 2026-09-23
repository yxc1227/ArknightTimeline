<?php

namespace App\Services;

use App\Enums\UserAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\WriteDeniedException;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 账号写入的唯一入口（与 EventWriter 同构：服务层持有不变量，控制器保持轻薄）。
 *
 * 用户管理最容易出的不是功能缺陷，而是**把自己锁在门外**。因此这里有三条硬约束，
 * 每条都对应一个真实事故场景：
 *
 *  1. **删除 / 禁用自己一律拒绝** —— 这两种操作会立刻让自己失去访问权，
 *     几乎只可能是误点，因此不给「先试试看」的机会。
 *  2. **「降级自己」只在会掏空管理员时拒绝** —— 由「系统必须始终保留至少一个
 *     启用中的管理员」这条不变量统一裁决。这让不变量唯一且可测，
 *     而不是散落成各方法里的特判。
 *  3. **一切变更留痕** —— 每次写入都追加一条 user_activity_logs，
 *     记录操作人、字段级前后值与来源 IP。「谁在什么时候改了什么」必须有答案。
 *
 * 所有守卫都在服务层而不是控制器里：命令行、队列、后续新增的入口
 * 只要走这里就自动受保护。
 *
 * 参数顺序统一为「目标 → 载荷 → 操作人」，操作人永远在最后。
 */
class UserManager
{
    /**
     * 账号资料的受管字段 → 展示名。
     *
     * @var array<string, string>
     */
    private const MANAGED_FIELDS = [
        'name' => '登录名',
        'display_name' => '显示名',
        'email' => '邮箱',
        'role' => '角色',
        'strict_source_scope' => '出处范围限制',
        'is_active' => '账号状态',
    ];

    /**
     * 新建账号。
     *
     * @param  array<string, mixed>  $data
     * @return array{user: User, password: string} 明文密码只在此处返回一次
     */
    public function create(array $data, User $actor): array
    {
        $this->assertAdmin($actor, '新建账号');

        $generated = ! $this->hasExplicitPassword($data['password'] ?? null);
        $password = $this->resolvePassword($data['password'] ?? null);

        $user = DB::transaction(function () use ($data, $actor, $password, $generated) {
            // 用 forceFill 而不是 create：email_verified_at 不在 fillable 白名单里
            // （普通注册流程不该能自行标记邮箱已验证），但管理员建号属于可信路径。
            $user = new User;
            $user->forceFill([
                'name' => trim((string) $data['name']),
                'display_name' => filled($data['display_name'] ?? null) ? trim((string) $data['display_name']) : null,
                'email' => mb_strtolower(trim((string) $data['email'])),
                'password' => $password,
                'role' => $data['role'] ?? UserRole::Viewer->value,
                'strict_source_scope' => $data['strict_source_scope'] ?? true,
                'is_active' => $data['is_active'] ?? true,
                'email_verified_at' => now(),
            ])->save();

            $this->write(
                $user,
                $actor,
                UserAction::Created,
                sprintf(
                    '创建账号「%s」，角色为%s，密码%s。',
                    $user->displayLabel(),
                    $user->role()->label(),
                    $generated ? '由系统随机生成' : '由管理员指定',
                ),
                [
                    'role' => ['label' => self::MANAGED_FIELDS['role'], 'from' => '—', 'to' => $user->role()->label()],
                    'is_active' => ['label' => self::MANAGED_FIELDS['is_active'], 'from' => '—', 'to' => $user->status()->label()],
                ],
            );

            return $user;
        });

        return ['user' => $user, 'password' => $password];
    }

    /**
     * 修改账号资料。角色降级会额外接受「保留管理员」不变量的裁决。
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $target, array $data, User $actor): User
    {
        $this->assertAdmin($actor, '修改账号');

        if (array_key_exists('role', $data) && $this->isDowngradeFromAdmin($target, (string) $data['role'])) {
            $this->assertNotLastActiveAdmin($target, '降级');
        }

        // 状态变更走 setActive 的语义（含自保护），资料更新只处理其余字段
        $activeChange = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null;
        unset($data['is_active'], $data['password']);

        DB::transaction(function () use ($target, $data, $actor) {
            $before = $target->replicate();

            if (array_key_exists('name', $data)) {
                $data['name'] = trim((string) $data['name']);
            }

            if (array_key_exists('display_name', $data)) {
                $data['display_name'] = filled($data['display_name']) ? trim((string) $data['display_name']) : null;
            }

            if (array_key_exists('email', $data)) {
                $data['email'] = mb_strtolower(trim((string) $data['email']));
            }

            $target->fill($data);
            $changes = $this->diff($before, $target);

            if ($target->isDirty()) {
                $target->save();
            }

            if ($changes !== []) {
                $this->write($target, $actor, UserAction::Updated, $this->describe($changes), $changes);
            }
        });

        if ($activeChange !== null && $activeChange !== $target->refresh()->isActive()) {
            $target = $this->setActive($target, $activeChange, $actor);
        }

        return $target->refresh();
    }

    /**
     * 启用 / 禁用账号。
     *
     * 禁用自己一律拒绝：那会立刻把自己踢出系统，且几乎不可能是本意。
     */
    public function setActive(User $target, bool $active, User $actor): User
    {
        $this->assertAdmin($actor, $active ? '启用账号' : '禁用账号');

        if (! $active) {
            $this->assertNotSelf($target, $actor, '禁用');
            $this->assertNotLastActiveAdmin($target, '禁用');
        }

        if ($target->isActive() === $active) {
            return $target;
        }

        DB::transaction(function () use ($target, $active, $actor) {
            $target->forceFill(['is_active' => $active])->save();

            $this->write(
                $target,
                $actor,
                $active ? UserAction::Activated : UserAction::Deactivated,
                $active ? '启用账号，恢复登录权限。' : '禁用账号，该账号将无法登录（历史归属保留）。',
                ['is_active' => [
                    'label' => self::MANAGED_FIELDS['is_active'],
                    'from' => $active ? UserStatus::Disabled->label() : UserStatus::Active->label(),
                    'to' => $active ? UserStatus::Active->label() : UserStatus::Disabled->label(),
                ]],
            );
        });

        return $target->refresh();
    }

    /** 软删除账号。历史归属与操作日志全部保留，因此删除是可回溯的。 */
    public function delete(User $target, User $actor, ?string $reason = null): void
    {
        $this->assertAdmin($actor, '删除账号');
        $this->assertNotSelf($target, $actor, '删除');
        $this->assertNotLastActiveAdmin($target, '删除');

        DB::transaction(function () use ($target, $actor, $reason) {
            $this->write(
                $target,
                $actor,
                UserAction::Deleted,
                '删除账号'.(filled($reason) ? '：'.$reason : '。').'条目与版本历史中的归属关系予以保留。',
                ['is_active' => [
                    'label' => self::MANAGED_FIELDS['is_active'],
                    'from' => $target->status()->label(),
                    'to' => '已删除',
                ]],
            );

            $target->delete();
        });
    }

    /** 恢复被软删除的账号。 */
    public function restore(int $userId, User $actor): User
    {
        $this->assertAdmin($actor, '恢复账号');

        $target = User::withTrashed()->findOrFail($userId);

        if (! $target->trashed()) {
            return $target;
        }

        DB::transaction(function () use ($target, $actor) {
            $target->restore();

            $this->write($target, $actor, UserAction::Restored, '恢复已删除的账号。');
        });

        return $target->refresh();
    }

    /**
     * 重置密码，返回明文，**只在此处返回一次**。
     *
     * 不允许管理员查看既有密码（哈希本就不可逆），只能重置成新的随机密码，
     * 再由管理员通过可信渠道转达 —— 这样「谁能看到密码」始终是明确的。
     */
    public function resetPassword(User $target, User $actor, ?string $password = null): string
    {
        $this->assertAdmin($actor, '重置密码');

        $plain = $this->resolvePassword($password);

        DB::transaction(function () use ($target, $actor, $plain) {
            $target->forceFill(['password' => $plain])->save();

            $this->write(
                $target,
                $actor,
                UserAction::PasswordReset,
                '重置登录密码。原密码立即失效，新密码仅向操作者展示一次。',
            );
        });

        return $plain;
    }

    /**
     * 批量操作。
     *
     * 刻意不是「全成功或全失败」：选中十条里有一条是自己时，
     * 直接整批报错会让人反复试。这里逐条应用守卫，跳过并回报原因。
     *
     * @param  list<int>  $ids
     * @return array{affected: int, skipped: list<array{id: int, name: string, reason: string}>}
     */
    public function bulk(string $action, array $ids, User $actor): array
    {
        $this->assertAdmin($actor, '批量操作');

        $affected = 0;
        $skipped = [];
        $targets = User::whereIn('id', $ids)->orderBy('id')->get();

        foreach ($targets as $target) {
            try {
                match ($action) {
                    'activate' => $this->setActive($target, true, $actor),
                    'deactivate' => $this->setActive($target, false, $actor),
                    'delete' => $this->delete($target, $actor, '批量操作'),
                    default => throw new WriteDeniedException('不支持的批量操作。', 'bulk_action_unknown'),
                };

                $affected++;
            } catch (WriteDeniedException $e) {
                $skipped[] = [
                    'id' => $target->id,
                    'name' => $target->displayLabel(),
                    'reason' => $e->getMessage(),
                ];
            }
        }

        // 未命中的 id（已被删除等）也要如实回报，否则「选中 5 个只动了 3 个」会让人困惑
        foreach (array_diff($ids, $targets->pluck('id')->all()) as $missingId) {
            $skipped[] = ['id' => (int) $missingId, 'name' => '#'.$missingId, 'reason' => '账号不存在或已被删除'];
        }

        return ['affected' => $affected, 'skipped' => $skipped];
    }

    /** 记录一次成功登录（供 LoginController 调用）。 */
    public function logLogin(User $user): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $this->ip(),
            'last_seen_at' => now(),
        ])->save();

        $this->write($user, $user, UserAction::LoggedIn, '登录系统。');
    }

    /* ------------------------------------------------------------------ 守卫 */

    private function assertAdmin(User $actor, string $intent): void
    {
        if (! $actor->isAdmin()) {
            throw new WriteDeniedException("只有管理员可以{$intent}。", 'user_manage_denied');
        }
    }

    /** 阻止「删除 / 禁用自己」这类会立刻让自己失去访问权的操作。 */
    private function assertNotSelf(User $target, User $actor, string $verb): void
    {
        if ($target->is($actor)) {
            throw new WriteDeniedException(
                "不能{$verb}自己的账号 —— 这会立刻让你失去访问权限。",
                'self_target_denied',
            );
        }
    }

    /**
     * 系统必须始终保留至少一个「启用中的管理员」。
     *
     * 这是管理员不会被掏空的唯一保证，因此用统一检查而不是各处特判。
     * 它同时覆盖「A 处理 B」与「A 处理自己」两种情况 ——
     * 后者正是「降级自己」被拦下的原因。
     */
    private function assertNotLastActiveAdmin(User $target, string $verb): void
    {
        if (! $target->isAdmin() || ! $target->isActive()) {
            return;
        }

        $others = User::query()
            ->whereKeyNot($target->getKey())
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->count();

        if ($others === 0) {
            throw new WriteDeniedException(
                "这是最后一个启用中的管理员，不能{$verb} —— 否则将没有人能再管理账号。",
                'last_admin_denied',
            );
        }
    }

    /* ------------------------------------------------------------------ 内部工具 */

    private function isDowngradeFromAdmin(User $target, string $newRole): bool
    {
        return $target->isAdmin() && $newRole !== UserRole::Admin->value;
    }

    /**
     * 字段级差异，值为**已渲染**的展示文本。
     *
     * 存渲染值而非原始值：这是一张面向人的审计表，若存 'editor'
     * 就意味着展示层要再判断一次「这是角色还是状态」才能翻译。
     *
     * @return array<string, array{label: string, from: string, to: string}>
     */
    private function diff(User $before, User $after): array
    {
        $beforeValues = [
            'name' => $before->name,
            'display_name' => $before->display_name ?? '—',
            'email' => $before->email,
            'role' => $before->role()->label(),
            'strict_source_scope' => $before->enforcesSourceScope() ? '是' : '否',
        ];

        $afterValues = [
            'name' => $after->name,
            'display_name' => $after->display_name ?? '—',
            'email' => $after->email,
            'role' => $after->role()->label(),
            'strict_source_scope' => $after->enforcesSourceScope() ? '是' : '否',
        ];

        $changes = [];

        foreach (array_keys($beforeValues) as $field) {
            $from = (string) $beforeValues[$field];
            $to = (string) $afterValues[$field];

            if ($from !== $to) {
                $changes[$field] = [
                    'label' => self::MANAGED_FIELDS[$field] ?? $field,
                    'from' => $from,
                    'to' => $to,
                ];
            }
        }

        return $changes;
    }

    /** @param array<string, array{label: string, from: string, to: string}> $changes */
    private function describe(array $changes): string
    {
        $parts = [];

        foreach ($changes as $delta) {
            $parts[] = sprintf('%s「%s」改为「%s」', $delta['label'], $delta['from'], $delta['to']);
        }

        return $parts === [] ? '提交了变更。' : implode('；', $parts).'。';
    }

    private function hasExplicitPassword(mixed $password): bool
    {
        return is_string($password) && trim($password) !== '';
    }

    /** 未提供密码时生成随机密码；关掉符号以免在即时通讯里转达时出错。 */
    private function resolvePassword(mixed $password): string
    {
        return $this->hasExplicitPassword($password)
            ? trim((string) $password)
            : Str::password(14, symbols: false);
    }

    /** @param array<string, array{label: string, from: string, to: string}>|null $changes */
    private function write(User $subject, ?User $actor, UserAction $action, string $description, ?array $changes = null): void
    {
        UserActivityLog::create([
            'user_id' => $subject->getKey(),
            'actor_id' => $actor?->getKey(),
            'action' => $action->value,
            'description' => $description,
            // 列名叫 field_changes，见 UserActivityLog 的类注释（changes 会撞 Eloquent 内部属性）
            'field_changes' => $changes,
            'ip_address' => $this->ip(),
        ]);
    }

    private function ip(): ?string
    {
        return request()?->ip();
    }
}
