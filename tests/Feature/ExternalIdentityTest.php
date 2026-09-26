<?php

namespace Tests\Feature;

use App\Enums\IdentityProvider;
use App\Enums\IdentityStatus;
use App\Enums\UserAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserIdentity;
use App\Services\Identity\ExternalProfile;
use App\Services\Identity\IdentityManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\Support\FakeIdentityDriver;
use Tests\TestCase;

/**
 * 外部渠道的注册、登录与绑定。
 *
 * 这一块的风险集中在**时序与归属**，因此用例的重点不是「能跑通」，
 * 而是这些反例：state 能不能重放、回调会不会被换个登录的人截走、
 * 邮箱撞车时会不会静默并号、解绑会不会把人锁在门外。
 */
class ExternalIdentityTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    /** @var array<string, string> */
    private const REGISTRATION = [
        'name' => 'newoperator',
        'nickname' => '新干员',
        'email' => 'newoperator@example.test',
    ];

    /** 让 hypergryph 变成「已配置」，从而出现在可用渠道里。 */
    private function configureProvider(): void
    {
        config([
            'identity.providers.hypergryph.client_id' => 'test-client',
            'identity.providers.hypergryph.client_secret' => 'test-secret',
            'identity.providers.hypergryph.authorize_url' => 'https://auth.example.test/authorize',
            'identity.providers.hypergryph.token_url' => 'https://auth.example.test/token',
            'identity.providers.hypergryph.userinfo_url' => 'https://auth.example.test/userinfo',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function fakeProfile(array $overrides = []): void
    {
        $this->configureProvider();

        $provider = IdentityProvider::Hypergryph;

        app(IdentityManager::class)->extend($provider, new FakeIdentityDriver(
            $provider,
            new ExternalProfile(
                provider: $provider,
                providerUserId: $overrides['id'] ?? 'hg-10001',
                nickname: $overrides['nickname'] ?? '外部昵称',
                avatarUrl: $overrides['avatar'] ?? null,
                email: $overrides['email'] ?? null,
            ),
        ));
    }

    /**
     * 走完「发起授权 → 外部批准 → 跳回回调」，返回回调响应。
     *
     * 顺着 Location 真的跳一次，而不是直接构造回调 URL ——
     * 这样 state 的生成与消费都被真实覆盖。
     */
    private function authorize(string $startRoute)
    {
        $redirect = $this->get($startRoute)->assertRedirect();

        return $this->get((string) $redirect->headers->get('Location'));
    }

    /* ------------------------------------------------------------ 外部注册 */

    public function test_guest_can_register_through_an_external_provider(): void
    {
        $this->fakeProfile(['nickname' => '外部昵称', 'email' => 'external@example.test']);

        // 未绑定的身份 → 先去补全资料，而不是直接建号
        $this->authorize(route('identity.redirect', 'hypergryph'))
            ->assertRedirect(route('identity.register.form'));

        $this->get(route('identity.register.form'))->assertOk()->assertSee('完善账号资料');

        $this->post(route('identity.register.store'), self::REGISTRATION)
            ->assertRedirect(route('timeline.index'));

        $user = User::where('email', self::REGISTRATION['email'])->firstOrFail();

        // 自助注册的权限固定在最低档：不能自己决定角色与编辑范围
        $this->assertSame(UserRole::Viewer, $user->role());
        $this->assertTrue($user->enforcesSourceScope());

        // 外部渠道不证明邮箱归属，因此不能假称已验证
        $this->assertNull($user->email_verified_at);

        // 本人不知道密码（库里是随机占位值），这会决定他能否解绑
        $this->assertFalse($user->hasUsablePassword());

        $identity = UserIdentity::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(IdentityProvider::Hypergryph, $identity->provider());
        $this->assertSame('hg-10001', $identity->provider_user_id);
        $this->assertTrue($identity->isVerified(), '授权流程确认的身份应直接是已核验');

        // 注册即登录
        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'action' => UserAction::Registered->value,
        ]);
    }

    public function test_external_registration_requires_unique_handle_and_nickname(): void
    {
        $this->fakeProfile();
        $this->authorize(route('identity.redirect', 'hypergryph'));

        $this->user(UserRole::Viewer, 'taken@example.test', '已被占用的昵称');
        User::create([
            'name' => 'takenhandle', 'nickname' => '另一个昵称',
            'email' => 'other@example.test', 'password' => 'password-123',
        ]);

        $this->post(route('identity.register.store'), [
            ...self::REGISTRATION,
            'name' => 'takenhandle',
        ])->assertSessionHasErrors('name');

        $this->post(route('identity.register.store'), [
            ...self::REGISTRATION,
            'nickname' => '已被占用的昵称',
        ])->assertSessionHasErrors('nickname');

        // 两次都失败，因此不应留下任何账号或绑定
        $this->assertSame(0, UserIdentity::count());
        $this->assertNull(User::where('email', self::REGISTRATION['email'])->first());
    }

    public function test_registration_is_refused_without_a_pending_external_profile(): void
    {
        // 没有经过授权就直接提交表单：必须被挡下，否则等于开放了任意注册
        $this->post(route('identity.register.store'), self::REGISTRATION)
            ->assertRedirect(route('login'));

        $this->assertSame(0, User::where('email', self::REGISTRATION['email'])->count());
    }

    public function test_second_login_with_the_same_identity_logs_in_directly(): void
    {
        $this->fakeProfile(['email' => 'external@example.test']);

        $this->authorize(route('identity.redirect', 'hypergryph'));
        $this->post(route('identity.register.store'), self::REGISTRATION);

        $user = User::where('email', self::REGISTRATION['email'])->firstOrFail();

        $this->post(route('logout'));
        $this->assertGuest();

        // 第二次：身份已绑定，应直接进站而不是再走一次注册
        $this->authorize(route('identity.redirect', 'hypergryph'))
            ->assertRedirect(route('timeline.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, UserIdentity::count());
        $this->assertNotNull(UserIdentity::firstOrFail()->last_used_at);
    }

    /**
     * 外部渠道返回的邮箱命中已有账号时**不能自动并号**。
     *
     * 因为「外部渠道说这个邮箱是他的」并不等于「这个邮箱是他的」——
     * 自动并号意味着任何人只要在某个渠道上把邮箱填成受害者的，就能接管本地账号。
     */
    public function test_email_collision_refuses_to_merge_accounts_silently(): void
    {
        $victim = $this->user(UserRole::Admin, 'victim@example.test');

        $this->fakeProfile(['email' => 'victim@example.test', 'id' => 'attacker-account']);

        $this->authorize(route('identity.redirect', 'hypergryph'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('identity');

        $this->assertGuest();
        $this->assertSame(0, UserIdentity::count());
        $this->assertNull($victim->fresh()->identities()->first());
    }

    public function test_disabled_account_cannot_log_in_through_an_external_provider(): void
    {
        $this->fakeProfile();

        $this->authorize(route('identity.redirect', 'hypergryph'));
        $this->post(route('identity.register.store'), self::REGISTRATION);

        $user = User::where('email', self::REGISTRATION['email'])->firstOrFail();
        $this->post(route('logout'));

        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('admin.users.toggle', $user), ['active' => false])->assertOk();

        // 凭据本身是对的（渠道已授权、身份已绑定），但账号被禁用 → 不放行
        $this->authorize(route('identity.redirect', 'hypergryph'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('identity');

        // 会话里仍是禁用操作者本人，被禁用的账号没有被放进来
        // （这里不能用 assertGuest：上一步 actingAs 让管理员处于登录状态）
        $this->assertAuthenticatedAs($admin);
    }

    /** 绑定还在、账号已被删除：要给出可执行的下一步，而不是撞唯一索引报 500。 */
    public function test_identity_of_a_deleted_account_reports_a_clear_error(): void
    {
        $this->fakeProfile();

        $this->authorize(route('identity.redirect', 'hypergryph'));
        $this->post(route('identity.register.store'), self::REGISTRATION);

        $user = User::where('email', self::REGISTRATION['email'])->firstOrFail();
        $this->post(route('logout'));

        $this->actingAs($this->admin())->deleteJson(route('admin.users.destroy', $user))->assertOk();

        $this->authorize(route('identity.redirect', 'hypergryph'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('identity');
    }

    /* ------------------------------------------------------------ state 时序 */

    public function test_callback_rejects_a_forged_state(): void
    {
        $this->fakeProfile();

        $this->get(route('identity.callback', ['provider' => 'hypergryph', 'state' => 'not-a-real-state', 'code' => 'x']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('identity');

        $this->assertSame(0, UserIdentity::count());
    }

    public function test_state_is_single_use(): void
    {
        $this->fakeProfile(['email' => 'external@example.test']);

        $redirect = $this->get(route('identity.redirect', 'hypergryph'))->assertRedirect();
        $callbackUrl = (string) $redirect->headers->get('Location');

        // 第一次正常
        $this->get($callbackUrl)->assertRedirect(route('identity.register.form'));

        // 重放同一次授权（例如用户刷新回调页）必须被拒绝
        $this->get($callbackUrl)->assertRedirect(route('login'))->assertSessionHasErrors('identity');
    }

    public function test_previously_issued_states_are_capped(): void
    {
        $this->configureProvider();

        // 反复点击「登录」不该让会话无限膨胀
        for ($i = 0; $i < 12; $i++) {
            $this->get(route('identity.redirect', 'hypergryph'))->assertRedirect();
        }

        $this->assertLessThanOrEqual(5, count((array) session('identity.states')));
    }

    public function test_disabled_provider_cannot_start_an_authorization(): void
    {
        // 未配置凭据 → 渠道不可用，必须立刻拒绝而不是跳去一个假地址
        $this->get(route('identity.redirect', 'hypergryph'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('identity');
    }

    public function test_unknown_provider_returns_404(): void
    {
        $this->get(route('identity.redirect', 'nope'))->assertNotFound();
        $this->get(route('identity.callback', 'nope'))->assertNotFound();
    }

    /* ------------------------------------------------------------ 绑定 */

    public function test_logged_in_user_can_bind_a_provider(): void
    {
        $this->fakeProfile(['nickname' => '鹰角账号甲']);
        $user = $this->user(UserRole::Editor, 'binder@example.test');

        $this->actingAs($user)->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('外部账号绑定');

        $this->authorize(route('identity.bind', 'hypergryph'))
            ->assertRedirect(route('settings.profile'));

        $identity = UserIdentity::firstOrFail();
        $this->assertSame($user->id, $identity->user_id);
        $this->assertTrue($identity->isVerified());
        $this->assertSame($user->id, $identity->verified_by, '自助绑定时操作人就是本人');
    }

    public function test_bind_requires_login(): void
    {
        $this->configureProvider();

        $this->get(route('identity.bind', 'hypergryph'))->assertRedirect(route('login'));
    }

    /**
     * 发起绑定的人与回调时登录的人必须是同一个。
     *
     * 否则「A 发起绑定 → 同一浏览器上 B 登录 → 回调把外部身份绑给 B」会静默发生，
     * 而 A 的外部账号从此归 B 所有。
     */
    public function test_binding_is_refused_when_the_session_user_changed(): void
    {
        $this->fakeProfile();

        $userA = $this->user(UserRole::Editor, 'bind-a@example.test');
        $userB = $this->user(UserRole::Editor, 'bind-b@example.test');

        $redirect = $this->actingAs($userA)->get(route('identity.bind', 'hypergryph'))->assertRedirect();
        $callbackUrl = (string) $redirect->headers->get('Location');

        $this->actingAs($userB)->get($callbackUrl)
            ->assertRedirect(route('settings.profile'))
            ->assertSessionHasErrors('identity');

        $this->assertSame(0, UserIdentity::count());
    }

    public function test_an_identity_already_owned_by_someone_else_cannot_be_bound(): void
    {
        $this->fakeProfile(['id' => 'hg-taken']);

        $owner = $this->user(UserRole::Editor, 'owner@example.test');
        $this->actingAs($owner)->authorize(route('identity.bind', 'hypergryph'));

        $this->post(route('logout'));

        $other = $this->user(UserRole::Editor, 'other@example.test');
        $this->actingAs($other)->authorize(route('identity.bind', 'hypergryph'))
            ->assertRedirect(route('settings.profile'))
            ->assertSessionHasErrors('identity');

        $this->assertSame(1, UserIdentity::count());
        $this->assertSame($owner->id, UserIdentity::firstOrFail()->user_id);
    }

    public function test_binding_the_same_identity_twice_is_idempotent(): void
    {
        $this->fakeProfile();
        $user = $this->user(UserRole::Editor, 'idempotent@example.test');

        $this->actingAs($user)->authorize(route('identity.bind', 'hypergryph'));
        $this->actingAs($user)->authorize(route('identity.bind', 'hypergryph'));

        $this->assertSame(1, UserIdentity::count());

        // 而且不重复写日志：审计表不能被无意义的记录塞满
        $this->assertSame(
            1,
            UserActivityLog::where('action', UserAction::IdentityLinked->value)->count(),
        );
    }

    /* ------------------------------------------------------------ 解绑与锁死保护 */

    public function test_unlink_is_refused_when_it_is_the_only_login_method(): void
    {
        $this->fakeProfile();
        $user = $this->user(UserRole::Viewer, 'nolockout@example.test');
        $user->forceFill(['password_set_at' => null])->save();

        $this->actingAs($user)->authorize(route('identity.bind', 'hypergryph'));

        $this->actingAs($user)
            ->delete(route('identity.unlink', 'hypergryph'))
            ->assertRedirect(route('settings.profile'))
            ->assertSessionHasErrors('identity');

        $this->assertSame(1, UserIdentity::count(), '最后一个登录方式不允许被解绑');
    }

    public function test_unlink_succeeds_after_setting_a_password(): void
    {
        $this->fakeProfile();
        $user = $this->user(UserRole::Viewer, 'canunlink@example.test');
        $user->forceFill(['password_set_at' => null])->save();

        $this->actingAs($user)->authorize(route('identity.bind', 'hypergryph'));

        // 先设置密码，账号就不再只有一条命
        $this->actingAs($user)->put(route('settings.profile.password'), [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect();

        $this->actingAs($user)->delete(route('identity.unlink', 'hypergryph'))
            ->assertRedirect(route('settings.profile'))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, UserIdentity::count());
        $this->assertDatabaseHas('user_activity_logs', ['action' => UserAction::IdentityUnlinked->value]);
    }

    public function test_unlink_is_refused_for_a_provider_that_is_not_bound(): void
    {
        $user = $this->user(UserRole::Editor, 'notbound@example.test');

        $this->actingAs($user)->delete(route('identity.unlink', 'hypergryph'))
            ->assertRedirect(route('settings.profile'))
            ->assertSessionHasErrors('identity');
    }

    /* ------------------------------------------------------------ 手工登记与核验 */

    public function test_manual_claim_is_pending_until_an_admin_verifies_it(): void
    {
        $user = $this->user(UserRole::Editor, 'claimer@example.test');

        $this->actingAs($user)
            ->post(route('identity.claim', 'hypergryph'), ['provider_user_id' => '55667788'])
            ->assertRedirect(route('settings.profile'));

        $identity = UserIdentity::firstOrFail();

        // 自助声明 ≠ 认证
        $this->assertSame(IdentityStatus::Pending, $identity->status());
        $this->assertFalse($identity->isVerified());
        $this->assertNull($identity->verified_at);

        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.identities.verify', [
            'user' => $user->id,
            'identity' => $identity->id,
        ]))->assertOk();

        $identity->refresh();
        $this->assertTrue($identity->isVerified());
        $this->assertSame($admin->id, $identity->verified_by);
        $this->assertNotNull($identity->verified_at);
        $this->assertDatabaseHas('user_activity_logs', ['action' => UserAction::IdentityVerified->value]);
    }

    public function test_manual_claim_validates_the_identifier_format(): void
    {
        $user = $this->user(UserRole::Editor, 'badclaim@example.test');

        $this->actingAs($user)
            ->post(route('identity.claim', 'hypergryph'), ['provider_user_id' => 'ab'])
            ->assertSessionHasErrors('provider_user_id');

        $this->actingAs($user)
            ->post(route('identity.claim', 'hypergryph'), ['provider_user_id' => 'has space'])
            ->assertSessionHasErrors('provider_user_id');

        $this->assertSame(0, UserIdentity::count());
    }

    public function test_manual_claim_cannot_take_a_uid_owned_by_someone_else(): void
    {
        $first = $this->user(UserRole::Editor, 'first-claim@example.test');
        $second = $this->user(UserRole::Editor, 'second-claim@example.test');

        $this->actingAs($first)->post(route('identity.claim', 'hypergryph'), ['provider_user_id' => '11223344']);

        $this->actingAs($second)
            ->post(route('identity.claim', 'hypergryph'), ['provider_user_id' => '11223344'])
            ->assertSessionHasErrors('identity');

        $this->assertSame(1, UserIdentity::count());
    }

    public function test_rejecting_a_claim_deletes_it_and_frees_the_identifier(): void
    {
        $user = $this->user(UserRole::Editor, 'rejectee@example.test');

        $this->actingAs($user)->post(route('identity.claim', 'hypergryph'), ['provider_user_id' => '44556677']);
        $identity = UserIdentity::firstOrFail();

        $this->actingAs($this->admin())->postJson(route('admin.identities.reject', [
            'user' => $user->id,
            'identity' => $identity->id,
        ]), ['reason' => '无法核对']) // 带上原因
            ->assertOk();

        $this->assertSame(0, UserIdentity::count());
        $this->assertDatabaseHas('user_activity_logs', ['action' => UserAction::IdentityRejected->value]);

        // 释放后其他人可以重新登记同一个 ID
        $this->actingAs($user)->post(route('identity.claim', 'hypergryph'), ['provider_user_id' => '44556677']);
        $this->assertSame(1, UserIdentity::count());
    }

    public function test_non_admin_cannot_verify_or_reject(): void
    {
        $user = $this->user(UserRole::Editor, 'selfserve@example.test');

        $this->actingAs($user)->post(route('identity.claim', 'hypergryph'), ['provider_user_id' => '99887766']);
        $identity = UserIdentity::firstOrFail();

        $this->actingAs($user)->postJson(route('admin.identities.verify', [
            'user' => $user->id, 'identity' => $identity->id,
        ]))->assertForbidden();

        $this->assertFalse($identity->fresh()->isVerified());
    }

    /** 路由里的两个 ID 必须指向同一个账号，否则能在 A 的页面上核验 B 的绑定。 */
    public function test_verification_requires_the_identity_to_belong_to_the_routed_user(): void
    {
        $owner = $this->user(UserRole::Editor, 'owner-x@example.test');
        $other = $this->user(UserRole::Editor, 'other-x@example.test');

        $this->actingAs($owner)->post(route('identity.claim', 'hypergryph'), ['provider_user_id' => '22334455']);
        $identity = UserIdentity::firstOrFail();

        $this->actingAs($this->admin())->postJson(route('admin.identities.verify', [
            'user' => $other->id,
            'identity' => $identity->id,
        ]))->assertNotFound();
    }

    /* ------------------------------------------------------------ 渠道可用性 */

    public function test_stub_provider_is_only_enabled_in_local_environments(): void
    {
        // 演示渠道是一键登录通道，绝不能出现在生产环境：
        // enabled 与 APP_ENV 绑定，配置缓存时就会被固化成 false
        $this->assertTrue(config('identity.providers.stub.enabled'), 'testing 环境应启用演示渠道');
        $this->assertTrue(IdentityProvider::Stub->isEnabled());

        config(['identity.providers.stub.enabled' => false]);
        $this->assertFalse(IdentityProvider::Stub->isEnabled());
    }

    public function test_stub_provider_completes_the_flow_without_network_access(): void
    {
        // 演示渠道不需要任何凭据，也不发请求 —— 它让没有真实凭据的环境也能演示整条链路
        $this->assertTrue(IdentityProvider::Stub->isEnabled());

        $this->authorize(route('identity.redirect', 'stub'))
            ->assertRedirect(route('identity.register.form'));

        $this->post(route('identity.register.store'), [
            'name' => 'stubuser',
            'nickname' => '演示干员账号',
            'email' => 'stubuser@example.test',
        ])->assertRedirect(route('timeline.index'));

        $identity = UserIdentity::firstOrFail();
        $this->assertSame(IdentityProvider::Stub, $identity->provider());
        $this->assertSame('demo-operator', $identity->provider_user_id);
    }

    public function test_provider_avatar_is_used_when_no_local_upload_exists(): void
    {
        $this->fakeProfile(['avatar' => 'https://cdn.example.test/a.png']);
        $user = $this->user(UserRole::Editor, 'avatarbind@example.test');

        $this->actingAs($user)->authorize(route('identity.bind', 'hypergryph'));

        $this->assertSame('https://cdn.example.test/a.png', $user->fresh()->avatarUrl());
    }
}
