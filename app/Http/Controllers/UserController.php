<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\BulkUserActionRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserIdentity;
use App\Services\Identity\IdentityManager;
use App\Services\UserManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * 账号管理（仅管理员）。
 *
 * 控制器只做三件事：取数、调服务、组装响应。
 * 所有不变量（自保护、保留最后一个管理员、留痕）都在 UserManager 里，
 * 因此这里没有一条 if 是在做权限之外的业务判断。
 */
class UserController extends Controller
{
    /** 每页条数。管理页不需要无限滚动，固定值即可。 */
    private const PER_PAGE = 15;

    public function __construct(
        private readonly UserManager $manager,
        private readonly IdentityManager $identities,
    ) {}

    /** 用户列表：搜索 + 筛选 + 排序 + 分页。 */
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->string('q')->value()) ?: null,
            'role' => $request->string('role')->value() ?: null,
            'status' => $request->string('status')->value() ?: null,
            'trashed' => $request->string('trashed')->value() ?: null,
        ];

        $sort = $request->string('sort')->value() ?: 'created_at';
        $direction = $request->string('direction')->value() ?: 'desc';

        $query = User::query()
            // 一并取出身份绑定：头像可能来自外部渠道，
            // 不预载的话列表每行都会多一次查询
            ->with('identities')
            ->search($filters['q'])
            ->ofRole($filters['role'])
            ->ofStatus($filters['status']);

        // 软删除的账号默认不出现在列表里，但管理员需要能翻出来恢复
        match ($filters['trashed']) {
            'only' => $query->onlyTrashed(),
            'with' => $query->withTrashed(),
            default => $query,
        };

        return view('admin.users.index', [
            'users' => $query->sorted($sort, $direction)->paginate(self::PER_PAGE)->withQueryString(),
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'roles' => UserRole::options(),
            'statuses' => UserStatus::options(),
            'counters' => $this->counters(),
            'currentUser' => $request->user(),
        ]);
    }

    /** 用户详情：基本资料 + 贡献统计 + 外部身份 + 操作日志。 */
    public function show(Request $request, User $user): View
    {
        return view('admin.users.show', [
            'user' => $user,
            'counters' => $this->counters(),
            'currentUser' => $request->user(),
            'stats' => [
                'events' => $user->events()->count(),
                'revisions' => $user->revisions()->count(),
                'annotations' => $user->annotations()->count(),
                'logs' => $user->activityLogs()->count(),
            ],
            'ownedSources' => $user->sources()->orderBy('name')->get(),
            // 身份绑定：管理员要在这里核验用户的自助登记
            'identities' => $user->identities()->with('verifier')->get(),
            'pendingIdentities' => $user->identities()->pending()->count(),
            'logs' => $user->activityLogs()
                ->with('actor')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'logs_page')
                ->withQueryString(),
        ]);
    }

    /* ------------------------------------------------------------------ 外部身份核验 */

    /** 核验通过用户自助登记的外部账号。 */
    public function verifyIdentity(Request $request, User $user, UserIdentity $identity): JsonResponse
    {
        Gate::authorize('verify', $identity);

        $this->assertIdentityBelongsTo($user, $identity);

        $verified = $this->identities->verify($identity, $request->user());

        return response()->json([
            'message' => sprintf('%s「%s」已核验。', $verified->label(), $verified->displayAccount()),
            'identity' => $verified->toApiArray(),
        ]);
    }

    /** 驳回自助登记（该绑定会被删除，操作记入日志）。 */
    public function rejectIdentity(Request $request, User $user, UserIdentity $identity): JsonResponse
    {
        Gate::authorize('reject', $identity);

        $this->assertIdentityBelongsTo($user, $identity);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $label = $identity->label();
        $account = $identity->displayAccount();

        $this->identities->reject($identity, $request->user(), $validated['reason'] ?? null);

        return response()->json([
            'message' => sprintf('已驳回 %s「%s」的自助登记。', $label, $account),
        ]);
    }

    /**
     * 确认这条绑定确实属于路径里的账号。
     *
     * 路由是 /admin/users/{user}/identities/{identity}/...，
     * 两个参数各自独立解析，若不做这一步，改一下 URL 里的 user 就能在
     * A 的详情页上核验 B 的绑定 —— 权限判断本身是对的，但作用对象错了。
     */
    private function assertIdentityBelongsTo(User $user, UserIdentity $identity): void
    {
        abort_unless($identity->user_id === $user->getKey(), 404);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $result = $this->manager->create($request->validated(), $request->user());

        return response()->json([
            'message' => sprintf('账号「%s」已创建。', $result['user']->displayLabel()),
            'user' => $result['user']->toApiArray(),
            // 明文密码只在这里返回一次；界面必须明确告知用户「关闭后无法再查看」
            'password' => $result['password'],
            'password_generated' => ! $request->filled('password'),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->manager->update($user, $request->validated(), $request->user());

        return response()->json([
            'message' => sprintf('账号「%s」已保存。', $updated->displayLabel()),
            'user' => $updated->toApiArray(),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $this->manager->delete($user, $request->user(), $validated['reason'] ?? null);

        return response()->json([
            'message' => sprintf('账号「%s」已删除（其历史归属予以保留）。', $user->displayLabel()),
        ]);
    }

    /** 启用 / 禁用。 */
    public function toggleActive(Request $request, User $user): JsonResponse
    {
        Gate::authorize('toggleActive', $user);

        $active = $request->boolean('active');
        $updated = $this->manager->setActive($user, $active, $request->user());

        return response()->json([
            'message' => sprintf(
                '账号「%s」%s。',
                $updated->displayLabel(),
                $active ? '已启用' : '已禁用，该账号将无法登录',
            ),
            'user' => $updated->toApiArray(),
        ]);
    }

    /** 重置密码：明文只在本次响应里出现一次。 */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        Gate::authorize('resetPassword', $user);

        $validated = $request->validate([
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
        ]);

        $plain = $this->manager->resetPassword($user, $request->user(), $validated['password'] ?? null);

        return response()->json([
            'message' => sprintf('账号「%s」的密码已重置。', $user->displayLabel()),
            'password' => $plain,
            'password_generated' => ! filled($validated['password'] ?? null),
        ]);
    }

    /** 从回收状态恢复账号。 */
    public function restore(Request $request, int $id): JsonResponse
    {
        Gate::authorize('restore', User::class);

        $user = $this->manager->restore($id, $request->user());

        return response()->json([
            'message' => sprintf('账号「%s」已恢复。', $user->displayLabel()),
            'user' => $user->toApiArray(),
        ]);
    }

    /** 批量启用 / 禁用 / 删除。逐条回报跳过的原因，而不是整批失败。 */
    public function bulk(BulkUserActionRequest $request): JsonResponse
    {
        Gate::authorize('bulk', User::class);

        $result = $this->manager->bulk(
            $request->validated('action'),
            $request->validated('ids'),
            $request->user(),
        );

        return response()->json([
            'message' => $result['affected'] > 0
                ? sprintf('已处理 %d 个账号。', $result['affected'])
                : '没有任何账号被修改。',
            'affected' => $result['affected'],
            'skipped' => $result['skipped'],
        ]);
    }

    /**
     * 顶部计数。
     *
     * 拆成几条简单查询而不是一条条件聚合：管理页不在热路径上，
     * 可读性比省下 4 次 count 更值钱，而且这样在任何驱动上行为都一致。
     *
     * @return array<string, int>
     */
    private function counters(): array
    {
        return [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'disabled' => User::where('is_active', false)->count(),
            'admins' => User::where('role', UserRole::Admin->value)->where('is_active', true)->count(),
            'trashed' => User::onlyTrashed()->count(),
            'logins_today' => UserActivityLog::whereDate('created_at', today())->count(),
        ];
    }
}
