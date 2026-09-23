<?php

namespace Tests\Feature;

use App\Enums\UserAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 网页自助注册。
 *
 * 这是全站唯一一条匿名用户能直接触发账号表写入的路径，因此用例分成两组：
 * 「能正常注册」只占少数，多数在盯边界 —— 重复命名、保留名、
 * 密码确认、开关关闭、限流。
 */
class SelfRegistrationTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    /** @var array<string, string> */
    private const FORM = [
        'name' => 'newcomer',
        'nickname' => '新来的考据员',
        'email' => 'newcomer@example.test',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ];

    public function test_guest_can_register_with_email_and_password(): void
    {
        $this->get(route('register'))->assertOk()->assertSee('注册');

        $this->post(route('register.store'), self::FORM)
            ->assertRedirect(route('timeline.index'))
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'newcomer@example.test')->firstOrFail();

        $this->assertSame('newcomer', $user->name);
        $this->assertSame('新来的考据员', $user->nickname);

        // 注册即登录
        $this->assertAuthenticatedAs($user);

        // 刚注册完就能用密码登录（密码是本人当场设的，因此是「可知」的）
        $this->assertTrue($user->hasUsablePassword());

        // 新账号的角色固定在最低档，且出处范围限制默认开启
        $this->assertSame(UserRole::Viewer, $user->role());
        $this->assertTrue($user->enforcesSourceScope());
        $this->assertTrue($user->isActive());

        // 没有邮件验证链路，因此不假称已验证
        $this->assertNull($user->email_verified_at);
    }

    public function test_registration_is_written_to_the_audit_log(): void
    {
        $this->post(route('register.store'), self::FORM)->assertRedirect();

        $user = User::where('email', 'newcomer@example.test')->firstOrFail();

        $registered = UserActivityLog::where('user_id', $user->id)
            ->where('action', UserAction::Registered->value)
            ->firstOrFail();

        // 自助注册没有第二个人参与，因此操作人就是本人
        $this->assertSame($user->id, $registered->actor_id);
        $this->assertStringContainsString('注册页', $registered->description);

        // 注册成功紧接着会记一条登录
        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'action' => UserAction::LoggedIn->value,
        ]);
    }

    public function test_registered_account_can_log_in_again_with_either_credential(): void
    {
        $this->post(route('register.store'), self::FORM);
        $this->post(route('logout'));
        $this->assertGuest();

        // 邮箱
        $this->post(route('login.store'), [
            'identifier' => 'newcomer@example.test',
            'password' => 'a-good-password',
        ])->assertRedirect();
        $this->post(route('logout'));

        // 登录名
        $this->post(route('login.store'), [
            'identifier' => 'newcomer',
            'password' => 'a-good-password',
        ])->assertRedirect();
        $this->assertAuthenticated();
    }

    /* ------------------------------------------------------------ 命名与唯一性 */

    public function test_registration_enforces_unique_handle_nickname_and_email(): void
    {
        $this->user(UserRole::Editor, 'taken@example.test', '已被占用的昵称');
        User::create([
            'name' => 'takenhandle', 'nickname' => '另一个人',
            'email' => 'other@example.test', 'password' => 'password-123',
        ]);

        $this->post(route('register.store'), [...self::FORM, 'name' => 'takenhandle'])
            ->assertSessionHasErrors('name');

        $this->post(route('register.store'), [...self::FORM, 'nickname' => '已被占用的昵称'])
            ->assertSessionHasErrors('nickname');

        $this->post(route('register.store'), [...self::FORM, 'email' => 'taken@example.test'])
            ->assertSessionHasErrors('email');

        // 三次都失败，不应留下任何账号
        $this->assertNull(User::where('email', 'newcomer@example.test')->first());
        $this->assertGuest();
    }

    /** 软删除的账号仍占用它的登录名 —— 自助注册者只能换一个，不该看到 500。 */
    public function test_soft_deleted_names_cannot_be_reused_by_new_registrations(): void
    {
        $victim = $this->user(UserRole::Editor, 'gone@example.test', '已经离开的人');

        $this->actingAs($this->admin())->deleteJson(route('admin.users.destroy', $victim))->assertOk();
        $this->post(route('logout'));

        $this->post(route('register.store'), [
            ...self::FORM,
            'name' => 'gone',
            'nickname' => '已经离开的人',
        ])->assertSessionHasErrors(['name', 'nickname']);
    }

    public function test_registration_rejects_reserved_names_and_non_ascii_handles(): void
    {
        // 「博士」是管理员的展示名，让它成为昵称等于给人一个可以自证身份的头衔
        $this->post(route('register.store'), [...self::FORM, 'nickname' => '博士'])
            ->assertSessionHasErrors('nickname');

        $this->post(route('register.store'), [...self::FORM, 'name' => '考据员'])
            ->assertSessionHasErrors('name');

        $this->post(route('register.store'), [...self::FORM, 'name' => '9abc'])
            ->assertSessionHasErrors('name');

        $this->assertGuest();
    }

    public function test_registration_requires_a_matching_password_confirmation(): void
    {
        $this->post(route('register.store'), [
            ...self::FORM,
            'password_confirmation' => 'another-password',
        ])->assertSessionHasErrors('password');

        $this->post(route('register.store'), [...self::FORM, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->assertNull(User::where('email', 'newcomer@example.test')->first());
    }

    public function test_registration_requires_all_fields(): void
    {
        $this->post(route('register.store'), [])
            ->assertSessionHasErrors(['name', 'nickname', 'email', 'password']);

        $this->assertSame(0, User::count());
    }

    /* ------------------------------------------------------------ 开关与限流 */

    public function test_registration_can_be_closed_by_configuration(): void
    {
        config(['identity.registration.enabled' => false]);

        // 关闭后注册页与提交入口都必须拒绝 —— 只藏链接不算关闭
        $this->get(route('register'))->assertRedirect(route('login'));
        $this->post(route('register.store'), self::FORM)->assertRedirect(route('login'));

        $this->assertNull(User::where('email', 'newcomer@example.test')->first());

        // 登录页也应如实说明，而不是留一个点了会报错的链接
        $this->get(route('login'))->assertOk()->assertSee('未开放自助注册');
    }

    public function test_registration_attempts_are_rate_limited_per_ip(): void
    {
        /*
         * 公开的注册入口若无限制，一个脚本就能以每秒几十个的速度建号。
         *
         * 这里刻意让每次提交都失败（昵称命中保留名）：否则第一次就会真的建号，
         * 而注册成功会自动登录 —— 限流中间件对已登录用户改用用户 ID 作为
         * 限流键，计数会从零开始，用例于是测不到任何东西。
         */
        $rejected = [...self::FORM, 'nickname' => '博士'];

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('register.store'), $rejected)->assertStatus(302);
        }

        $this->post(route('register.store'), $rejected)->assertStatus(429);

        // 限流是最后一道闸门，前面十次失败也不该留下任何账号
        $this->assertSame(0, User::count());
    }

    /* ------------------------------------------------------------ 已登录的人 */

    public function test_logged_in_user_is_sent_away_from_the_registration_page(): void
    {
        $user = $this->user(UserRole::Editor, 'already@example.test');

        $this->actingAs($user)->get(route('register'))->assertRedirect(route('timeline.index'));
        $this->actingAs($user)->post(route('register.store'), self::FORM)
            ->assertRedirect(route('timeline.index'));

        // 不应因此多出第二个账号
        $this->assertNull(User::where('email', 'newcomer@example.test')->first());
    }

    /** 注册出来的账号能立刻完成「改昵称 + 传头像」这套自服务动作。 */
    public function test_new_account_can_immediately_use_the_profile_settings(): void
    {
        $this->post(route('register.store'), self::FORM);
        $user = User::where('email', 'newcomer@example.test')->firstOrFail();

        $this->actingAs($user)->get(route('settings.profile'))->assertOk()->assertSee('账号设置');

        $this->actingAs($user)->put(route('settings.profile.update'), ['nickname' => '改过头像的人'])
            ->assertSessionHasNoErrors();

        $this->assertSame('改过头像的人', $user->fresh()->nickname);
    }
}
