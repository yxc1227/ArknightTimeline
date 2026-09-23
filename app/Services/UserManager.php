<?php

namespace App\Services;

use App\Enums\UserAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\WriteDeniedException;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Identity\ExternalProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        'nickname' => '昵称',
        'email' => '邮箱',
        'role' => '角色',
        'strict_source_scope' => '出处范围限制',
        'is_active' => '账号状态',
        'avatar' => '头像',
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

        try {
            $user = DB::transaction(function () use ($data, $actor, $password, $generated) {
                // 用 forceFill 而不是 create：email_verified_at 不在 fillable 白名单里
                // （普通注册流程不该能自行标记邮箱已验证），但管理员建号属于可信路径。
                $user = new User;
                $user->forceFill([
                    'name' => $this->normalizeHandle($data['name']),
                    'nickname' => $this->normalizeNickname($data['nickname'] ?? $data['name']),
                    'email' => mb_strtolower(trim((string) $data['email'])),
                    'password' => $password,
                    // 管理员建号：密码由管理员掌握并负责转达，因此本人是「可知」的
                    'password_set_at' => now(),
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
                        'nickname' => ['label' => self::MANAGED_FIELDS['nickname'], 'from' => '—', 'to' => $user->nickname],
                        'role' => ['label' => self::MANAGED_FIELDS['role'], 'from' => '—', 'to' => $user->role()->label()],
                        'is_active' => ['label' => self::MANAGED_FIELDS['is_active'], 'from' => '—', 'to' => $user->status()->label()],
                    ],
                );

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages($this->describeUniqueConflict($data));
        }

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
                $data['name'] = $this->normalizeHandle($data['name']);
            }

            if (array_key_exists('nickname', $data)) {
                $data['nickname'] = $this->normalizeNickname($data['nickname']);
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
            $target->forceFill([
                'password' => $plain,
                // 管理员重置后会把新密码转达给本人，因此从此刻起本人是「知道密码」的。
                // 这条决定了对方能否解绑外部身份，不能漏。
                'password_set_at' => now(),
            ])->save();

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
     * 更新头像路径。
     *
     * 只写数据库列，不碰文件 —— 文件由 AvatarService 负责，
     * 这样「上传失败」与「数据库没更新」不会变成一个说不清的状态。
     */
    public function updateAvatar(User $user, ?string $path): User
    {
        $before = $user->avatar_path;

        if ($before === $path) {
            return $user;
        }

        DB::transaction(function () use ($user, $path, $before) {
            $user->forceFill(['avatar_path' => $path])->save();

            $this->write(
                $user,
                $user,
                $path === null ? UserAction::AvatarRemoved : UserAction::AvatarUpdated,
                $path === null ? '移除头像。' : '更新头像。',
                ['avatar' => [
                    'label' => self::MANAGED_FIELDS['avatar'],
                    'from' => $before === null ? '未设置' : '已设置',
                    'to' => $path === null ? '未设置' : '已设置',
                ]],
            );
        });

        return $user->refresh();
    }

    /**
     * 本人修改自己的资料。
     *
     * 只允许改昵称，而且是具体参数而不是数组：
     * 登录名与邮箱是账号凭据，改动它们等于改「谁能用这个账号登录」，
     * 在缺少邮箱验证流程的前提下只能走管理员通道。
     * 用具体参数还能顺带堵死「往数组里塞 role / is_active 自我提权」这条路。
     */
    public function updateOwnProfile(User $user, string $nickname): User
    {
        return DB::transaction(function () use ($user, $nickname) {
            $before = $user->replicate();

            $user->fill(['nickname' => $this->normalizeNickname($nickname)]);
            $changes = $this->diff($before, $user);

            if ($user->isDirty()) {
                $user->save();
            }

            if ($changes !== []) {
                $this->write($user, $user, UserAction::Updated, $this->describe($changes), $changes);
            }

            return $user->refresh();
        });
    }

    /**
     * 本人设置 / 修改密码。
     *
     * 「必须知道旧密码」这条判断不在这里，而在表单校验里（需要区分
     * 「已有可用密码」与「外部渠道注册、本人根本不知道密码」两种情形）。
     * 服务层只负责把状态改对：password_set_at 一旦写上，
     * 就表示本人从此掌握了这个账号的密码。
     */
    public function changeOwnPassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $firstTime = ! $user->hasUsablePassword();

            $user->forceFill([
                'password' => $password,
                'password_set_at' => now(),
            ])->save();

            $this->write(
                $user,
                $user,
                UserAction::PasswordChanged,
                $firstTime
                    ? '首次设置登录密码，账号从此可用密码登录。'
                    : '修改登录密码。',
            );
        });
    }

    /* ------------------------------------------------------------------ 外部渠道注册 */

    /**
     * 网页表单自助注册。
     *
     * 密码由本人当场设定，因此从一开始就是「本人可知」的。
     *
     * @param  array{name: string, nickname: string, email: string, password: string}  $data
     */
    public function register(array $data): User
    {
        return $this->createSelfServiceAccount($data, (string) $data['password'], provider: null);
    }

    /**
     * 通过外部渠道自助注册。
     *
     * 密码写入随机占位值并把 password_set_at 留空 —— 本人不知道这串密码，
     * 于是「解绑最后一个登录身份」的守卫会要求他先设置密码。
     *
     * @param  array{name: string, nickname: string, email: string}  $data
     */
    public function registerExternally(array $data, ExternalProfile $profile): User
    {
        return $this->createSelfServiceAccount($data, password: null, provider: $profile);
    }

    /**
     * 自助注册的公共实现。
     *
     * 两条入口（网页表单 / 外部渠道）的差别只有两点：密码从哪来、日志怎么写。
     * 权限、唯一性、留痕这些不变量必须完全一致 —— 拆成两份实现迟早会分叉，
     * 而分叉的那一侧几乎总是权限更松的那一份。
     *
     * 注意这里**没有 assertAdmin**：本人即操作人。这也是它与 create() 的根本区别，
     * 因此角色被硬编码为最低档，自助注册的账号不能自己决定权限。
     *
     * @param  array{name: string, nickname: string, email: string}  $data
     */
    private function createSelfServiceAccount(array $data, ?string $password, ?ExternalProfile $provider): User
    {
        try {
            return DB::transaction(function () use ($data, $password, $provider) {
                $user = new User;
                $user->forceFill([
                    'name' => $this->normalizeHandle($data['name']),
                    'nickname' => $this->normalizeNickname($data['nickname']),
                    'email' => mb_strtolower(trim((string) $data['email'])),
                    // 没有密码时写随机占位值：「库里有哈希」不等于「本人知道密码」，
                    // 这条区别决定了能不能解绑最后一个外部身份
                    'password' => $password ?? Str::random(40),
                    'password_set_at' => $password === null ? null : now(),
                    'role' => UserRole::Viewer->value,
                    'strict_source_scope' => true,
                    'is_active' => true,
                    // 无论是网页注册还是外部渠道，邮箱都只是本人填的，本站没有验证链路，
                    // 因此不假称它已验证
                    'email_verified_at' => null,
                ])->save();

                $this->write(
                    $user,
                    $user,
                    UserAction::Registered,
                    $provider === null
                        ? sprintf('通过注册页自助注册账号，角色为%s。', $user->role()->label())
                        : sprintf(
                            '通过%s「%s」注册账号，角色为%s。',
                            $provider->provider->label(),
                            $provider->displayAccount(),
                            $user->role()->label(),
                        ),
                    [
                        'nickname' => ['label' => self::MANAGED_FIELDS['nickname'], 'from' => '—', 'to' => $user->nickname],
                        'role' => ['label' => self::MANAGED_FIELDS['role'], 'from' => '—', 'to' => $user->role()->label()],
                    ],
                );

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages($this->describeUniqueConflict($data));
        }
    }

    /**
     * 记录一条身份变更日志。
     *
     * 由 IdentityManager 调用：日志的写入方式（操作人、IP、字段结构）
     * 只允许在这一处定义，否则迟早出现「某条路径忘了记 IP」。
     */
    public function logIdentityChange(User $subject, ?User $actor, UserAction $action, string $description): void
    {
        $this->write($subject, $actor, $action, $description);
    }

    /* ------------------------------------------------------------------ 命名建议 */

    /** 生成一个尚未被占用的登录名（外部注册表单预填用）。 */
    public function suggestHandle(string $seed): string
    {
        $base = mb_strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', $seed));
        $base = mb_substr($base, 0, 20);

        if (preg_match('/^[A-Za-z]/', $base) !== 1) {
            $base = 'user'.$base;
        }

        while (mb_strlen($base) < 3) {
            $base .= '0';
        }

        return $this->firstFree($base, fn (string $candidate) => User::withTrashed()->whereHandleIs($candidate)->exists());
    }

    /**
     * 生成一个尚未被占用的昵称。
     *
     * 昵称的唯一性判断用 lower() 而不是直接等值：MySQL 的排序规则不区分大小写、
     * SQLite 区分，只在应用层统一成「不区分」才能在两种环境下行为一致。
     */
    public function suggestNickname(string $seed): string
    {
        $base = $this->normalizeNickname($seed === '' ? '新干员' : $seed);
        $base = mb_substr($base, 0, 48);

        return $this->firstFree($base, fn (string $candidate) => User::withTrashed()->whereNicknameIs($candidate)->exists());
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

    /**
     * 归一化登录名。
     *
     * 统一转成小写存储，是为了让「唯一」这件事在两种数据库上含义一致：
     * MySQL 的 utf8mb4_unicode_ci 不区分大小写，SQLite 的 TEXT 区分，
     * 同样的数据在两边会得到不同结果 —— 那是最难查的一类问题。
     * 全部落成小写之后，'Admin' 与 'admin' 就是同一个字符串，讨论才成立。
     */
    private function normalizeHandle(mixed $value): string
    {
        $handle = mb_strtolower(trim((string) $value));

        if (! User::isValidHandle($handle)) {
            throw new WriteDeniedException(
                '登录名需以字母开头，只能包含字母、数字、下划线与连字符，长度 3-30 位。',
                'handle_invalid',
            );
        }

        return $handle;
    }

    /**
     * 归一化昵称。
     *
     * 内部连续空白折成一个空格：否则「考据 员」与「考據員」之外，
     * 连「考据  员」（两个空格）都会成为一条独立记录，
     * 而唯一索引挡不住这种「人眼分不出来」的重名。
     */
    private function normalizeNickname(mixed $value): string
    {
        $nickname = (string) preg_replace('/\s+/u', ' ', trim((string) $value));

        if ($nickname === '') {
            throw new WriteDeniedException('昵称不能为空。', 'nickname_required');
        }

        if (mb_strlen($nickname) > 60) {
            throw new WriteDeniedException('昵称不能超过 60 个字符。', 'nickname_too_long');
        }

        return $nickname;
    }

    /**
     * 在基础名之后追加 -2 / -3 …… 直到不再冲突。
     *
     * @param  callable(string): bool  $taken
     */
    private function firstFree(string $base, callable $taken): string
    {
        if (! $taken($base)) {
            return $base;
        }

        $suffix = 2;

        do {
            $candidate = $base.'-'.$suffix++;
        } while ($taken($candidate));

        return $candidate;
    }

    /**
     * 唯一索引冲突 → 字段级错误。
     *
     * 冲突发生在「校验通过」与「写入」之间（并发注册同一个登录名），
     * 此时数据已经稳定，重新查一次就能精确定位是哪个字段被占了。
     *
     * 刻意不解析驱动的报错文本：MySQL 报索引名、SQLite 报列名，格式并不统一，
     * 靠字符串匹配得来的精确度经不起一次数据库升级。
     *
     * 抛出 ValidationException 而不是自定义异常，是因为它在 Web 与 AJAX
     * 两条链路上都会自动变成「回到表单 + 字段级提示」，调用方不需要任何补丁代码。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function describeUniqueConflict(array $data): array
    {
        $errors = [];

        if (User::withTrashed()->whereHandleIs((string) ($data['name'] ?? ''))->exists()) {
            $errors['name'] = '该登录名刚刚被占用，请换一个。';
        }

        if (User::withTrashed()->whereNicknameIs((string) ($data['nickname'] ?? ''))->exists()) {
            $errors['nickname'] = '该昵称刚刚被占用，请换一个。';
        }

        if (User::withTrashed()->whereEmailIs((string) ($data['email'] ?? ''))->exists()) {
            $errors['email'] = '该邮箱刚刚被注册，请换一个。';
        }

        return $errors === []
            ? ['name' => '提交的登录名、昵称或邮箱刚刚被占用，请换一个再试。']
            : $errors;
    }

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
            'nickname' => $before->nickname ?? '—',
            'email' => $before->email,
            'role' => $before->role()->label(),
            'strict_source_scope' => $before->enforcesSourceScope() ? '是' : '否',
        ];

        $afterValues = [
            'name' => $after->name,
            'nickname' => $after->nickname ?? '—',
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
