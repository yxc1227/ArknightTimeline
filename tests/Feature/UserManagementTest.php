<?php

namespace Tests\Feature;

use App\Enums\UserAction;
use App\Enums\UserRole;
use App\Exceptions\WriteDeniedException;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\UserManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 账号管理。
 *
 * 这个模块的功能本身不难，难的是**权限边界**：用户管理最典型的事故
 * 不是「按钮点不动」，而是「管理员把自己降级了，从此没人能管理账号」。
 * 因此不变量相关的一组用例是这份测试的重点。
 */
class UserManagementTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    private function manager(): UserManager
    {
        return app(UserManager::class);
    }

    /**
     * 一份合法的账号表单载荷。
     *
     * 登录名必须是 ASCII（见 User::HANDLE_PATTERN），昵称可以是中文 ——
     * 这份载荷刻意让两者不同，以便断言「两个字段确实是分开的」。
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'newcomer',
            'nickname' => '新同事',
            'email' => 'newcomer@example.test',
            'role' => UserRole::Editor->value,
            'strict_source_scope' => true,
            'is_active' => true,
            ...$overrides,
        ];
    }

    /* ------------------------------------------------------------ 访问控制 */

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_non_admin_roles_cannot_open_the_account_manager(): void
    {
        foreach ([UserRole::Viewer, UserRole::Editor, UserRole::Reviewer] as $role) {
            $user = $this->user($role, $role->value.'-deny@example.test');

            $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
            $this->actingAs($user)->postJson(route('admin.users.store'), $this->payload())->assertForbidden();
        }
    }

    public function test_admin_can_open_the_account_manager(): void
    {
        $this->actingAs($this->admin())->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('账号管理')
            ->assertSee('新建账号')
            ->assertSee('admin@example.test');
    }

    public function test_non_admin_cannot_open_the_detail_page(): void
    {
        $target = $this->user(UserRole::Viewer, 'target-detail@example.test');
        $editor = $this->user(UserRole::Editor, 'editor-detail@example.test');

        $this->actingAs($editor)->get(route('admin.users.show', $target))->assertForbidden();
    }

    /* ------------------------------------------------------------ 列表 / 搜索 / 筛选 */

    public function test_search_matches_handle_nickname_and_email(): void
    {
        $admin = $this->admin();

        // 登录名（ASCII）与昵称（中文）刻意不同，三个字段各查一次
        $this->user(UserRole::Reviewer, 'alpha@example.test', '考据员甲');
        $this->user(UserRole::Reviewer, 'beta@example.test', '考据员乙');

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'alpha']))
            ->assertOk()->assertSee('考据员甲')->assertDontSee('考据员乙');

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => '考据员乙']))
            ->assertOk()->assertSee('考据员乙')->assertDontSee('考据员甲');

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'beta@example']))
            ->assertOk()->assertSee('考据员乙')->assertDontSee('考据员甲');
    }

    /**
     * LIKE 通配符必须转义：否则在搜索框里敲一个 % 就会命中全部账号，
     * 用户会以为「搜索坏了」，而实际上是把 LIKE 语义漏了出去。
     */
    public function test_search_escapes_like_wildcards(): void
    {
        $admin = $this->admin();
        $this->user(UserRole::Viewer, 'wildcard@example.test');

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => '%']))
            ->assertOk()
            ->assertDontSee('wildcard@example.test');
    }

    public function test_filter_by_role_and_status(): void
    {
        /*
         * 两个易踩的坑，都写在这里以免下次重犯：
         *
         *  1. 操作者要用独立邮箱：当前登录者的邮箱会出现在页面的 window.APP.user 里，
         *     否则「页面上不该出现某邮箱」测到的是脚本变量而不是列表。
         *  2. 两个被断言的邮箱之间**不能有子串包含关系**：
         *     'inactive@example.test' 以 'active@example.test' 结尾，
         *     assertDontSee 会因此在正确的过滤结果上失败。
         */
        $admin = $this->admin('root@example.test');
        $this->user(UserRole::Editor, 'staff-one@example.test');
        $disabled = $this->user(UserRole::Viewer, 'muted-two@example.test');
        $disabled->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'editor']))
            ->assertOk()->assertSee('staff-one@example.test')->assertDontSee('muted-two@example.test');

        $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'disabled']))
            ->assertOk()->assertSee('muted-two@example.test')->assertDontSee('staff-one@example.test');

        $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'active']))
            ->assertOk()->assertSee('staff-one@example.test')->assertDontSee('muted-two@example.test');
    }

    public function test_soft_deleted_accounts_are_hidden_unless_requested(): void
    {
        $admin = $this->admin();
        $gone = $this->user(UserRole::Viewer, 'gone@example.test');
        $this->manager()->delete($gone, $admin);

        // 默认不出现
        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()->assertDontSee('gone@example.test');

        // 只看已删除
        $this->actingAs($admin)->get(route('admin.users.index', ['trashed' => 'only']))
            ->assertOk()->assertSee('gone@example.test');

        // 含已删除
        $this->actingAs($admin)->get(route('admin.users.index', ['trashed' => 'with']))
            ->assertOk()->assertSee('gone@example.test')->assertSee('admin@example.test');
    }

    /**
     * 排序列来自请求参数，必须走白名单。
     * 用一个不存在的列名验证：应当静默回落到默认排序，而不是抛 SQL 错误。
     */
    public function test_sorting_whitelist_rejects_unknown_columns(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.users.index', ['sort' => 'password', 'direction' => 'asc']))
            ->assertOk();

        $this->actingAs($admin)->get(route('admin.users.index', ['sort' => 'name', 'direction' => 'asc']))
            ->assertOk();
    }

    public function test_unknown_sort_direction_falls_back_to_desc(): void
    {
        $admin = $this->admin();

        // direction 只接受 asc / desc，其它值不得进入 SQL
        $this->actingAs($admin)->get(route('admin.users.index', ['direction' => 'asc; drop table users']))
            ->assertOk();
    }

    /* ------------------------------------------------------------ 新建 */

    public function test_admin_can_create_an_account_and_it_is_logged(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson(route('admin.users.store'), $this->payload());

        $response->assertCreated()->assertJsonPath('user.email', 'newcomer@example.test');

        $created = User::where('email', 'newcomer@example.test')->firstOrFail();
        $this->assertSame(UserRole::Editor, $created->role());
        $this->assertTrue($created->isActive());

        // 返回的密码能真正登录
        $this->assertNotEmpty($response->json('password'));
        $this->post(route('login.store'), [
            'identifier' => 'newcomer@example.test',
            'password' => $response->json('password'),
        ])->assertRedirect();

        // 登录名同样可以登录（两者都全服唯一，因此不存在歧义）
        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'identifier' => 'newcomer',
            'password' => $response->json('password'),
        ])->assertRedirect();

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $created->id,
            'actor_id' => $admin->id,
            'action' => UserAction::Created->value,
        ]);
    }

    public function test_blank_password_is_generated_and_reported_once(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson(route('admin.users.store'), $this->payload(['password' => null]));

        $response->assertCreated()->assertJsonPath('password_generated', true);
        $this->assertGreaterThanOrEqual(12, strlen($response->json('password')));
    }

    public function test_explicit_password_is_used_as_given(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson(
            route('admin.users.store'),
            $this->payload(['password' => 'chosen-password-42']),
        );

        $response->assertCreated()->assertJsonPath('password_generated', false);
        $this->assertSame('chosen-password-42', $response->json('password'));
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.users.store'), $this->payload(['email' => 'admin@example.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /**
     * 软删除的账号仍占用 email 唯一索引，因此校验也必须拦住，
     * 并且要给出可执行的下一步（先恢复那个账号）而不是一句「已存在」。
     */
    public function test_email_of_a_soft_deleted_account_is_rejected_with_a_helpful_message(): void
    {
        $admin = $this->admin();
        $gone = $this->user(UserRole::Viewer, 'reuse@example.test');
        $this->manager()->delete($gone, $admin);

        $response = $this->actingAs($admin)->postJson(
            route('admin.users.store'),
            $this->payload(['email' => 'reuse@example.test']),
        );

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertStringContainsString('恢复', $response->json('errors.email.0'));
    }

    public function test_invalid_role_and_short_password_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.users.store'), $this->payload(['role' => 'superuser']))
            ->assertStatus(422)->assertJsonValidationErrors('role');

        $this->actingAs($admin)->postJson(route('admin.users.store'), $this->payload(['password' => 'short']))
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /* ------------------------------------------------------------ 修改 */

    public function test_update_records_a_field_level_diff(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Viewer, 'promote@example.test');

        // 只改角色、其余字段保持原值，断言才能精确指向唯一一处变化
        $this->actingAs($admin)->putJson(route('admin.users.update', $target), [
            'name' => 'promote',
            'nickname' => 'promote',
            'email' => 'promote@example.test',
            'role' => UserRole::Reviewer->value,
            'strict_source_scope' => true,
            'is_active' => true,
        ])->assertOk();

        $this->assertSame(UserRole::Reviewer, $target->fresh()->role());

        $log = UserActivityLog::where('user_id', $target->id)
            ->where('action', UserAction::Updated->value)
            ->firstOrFail();

        // 角色标签采用《明日方舟》世界观的职级命名
        $this->assertSame('预备干员', $log->field_changes['role']['from']);
        $this->assertSame('精英干员', $log->field_changes['role']['to']);

        $rows = $log->changeRows();
        $this->assertCount(1, $rows);
        $this->assertSame('角色', $rows[0]['field']);
    }

    public function test_update_allows_keeping_its_own_email(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Viewer, 'keep@example.test');

        // 唯一性校验必须排除自身，否则「只改昵称」也会撞自己的索引
        $this->actingAs($admin)->putJson(route('admin.users.update', $target), $this->payload([
            'name' => 'keep',
            'email' => 'keep@example.test',
            'nickname' => '改了昵称',
        ]))->assertOk();

        $this->assertSame('改了昵称', $target->fresh()->displayLabel());
    }

    /* ------------------------------------------------------------ 不变量 */

    public function test_cannot_disable_your_own_account(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson(route('admin.users.toggle', $admin), ['active' => false]);
        $response->assertForbidden();

        // 拒绝必须带原因：空的 403 会让人以为是权限配错了
        $this->assertStringContainsString('自己', (string) $response->json('message'));
        $this->assertTrue($admin->fresh()->isActive());
    }

    public function test_cannot_delete_your_own_account(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->deleteJson(route('admin.users.destroy', $admin));
        $response->assertForbidden();

        $this->assertStringContainsString('自己', (string) $response->json('message'));
        $this->assertNull($admin->fresh()->deleted_at);
    }

    /**
     * 最关键的一条：最后一个启用中的管理员不能把自己降级，
     * 否则系统会进入「谁都进不了管理页」的死局。
     */
    public function test_last_active_admin_cannot_demote_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(route('admin.users.update', $admin), $this->payload([
            'email' => 'admin@example.test',
            'role' => UserRole::Editor->value,
        ]))->assertForbidden()->assertJsonPath('error', 'last_admin_denied');

        $this->assertSame(UserRole::Admin, $admin->fresh()->role());
    }

    /** 但只要有另一个启用中的管理员兜底，自我降级就是允许的。 */
    public function test_self_demotion_is_allowed_when_another_active_admin_exists(): void
    {
        $admin = $this->admin('admin-a@example.test');
        $this->admin('admin-b@example.test');

        $this->actingAs($admin)->putJson(route('admin.users.update', $admin), $this->payload([
            'email' => 'admin-a@example.test',
            'role' => UserRole::Editor->value,
        ]))->assertOk();

        $this->assertSame(UserRole::Editor, $admin->fresh()->role());
    }

    public function test_disabled_admin_does_not_count_as_a_fallback(): void
    {
        $admin = $this->admin('admin-a@example.test');
        $other = $this->admin('admin-b@example.test');
        $other->forceFill(['is_active' => false])->save();

        // 另一个管理员已被禁用（无法登录），因此不构成兜底
        $this->actingAs($admin)->putJson(route('admin.users.update', $admin), $this->payload([
            'email' => 'admin-a@example.test',
            'role' => UserRole::Editor->value,
        ]))->assertForbidden()->assertJsonPath('error', 'last_admin_denied');
    }

    public function test_admin_can_delete_and_disable_another_admin(): void
    {
        $admin = $this->admin('admin-a@example.test');
        $other = $this->admin('admin-b@example.test');

        $this->actingAs($admin)->postJson(route('admin.users.toggle', $other), ['active' => false])->assertOk();
        $this->assertFalse($other->fresh()->isActive());

        $this->actingAs($admin)->deleteJson(route('admin.users.destroy', $other))->assertOk();
        $this->assertNotNull($other->fresh()->deleted_at);
    }

    /* ------------------------------------------------------------ 重置密码 */

    public function test_reset_password_invalidates_the_old_one(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Editor, 'reset@example.test');
        $target->forceFill(['password' => 'original-password'])->save();

        $response = $this->actingAs($admin)->postJson(route('admin.users.password', $target), []);
        $response->assertOk();

        $newPassword = $response->json('password');
        $this->assertNotEmpty($newPassword);

        // 旧密码失效
        $this->post(route('login.store'), ['identifier' => 'reset@example.test', 'password' => 'original-password'])
            ->assertSessionHasErrors('identifier');

        // 新密码可用
        $this->post(route('login.store'), ['identifier' => 'reset@example.test', 'password' => $newPassword])
            ->assertRedirect();

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $target->id,
            'action' => UserAction::PasswordReset->value,
        ]);
    }

    public function test_reset_password_accepts_an_explicit_value(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Editor, 'reset-explicit@example.test');

        $this->actingAs($admin)->postJson(route('admin.users.password', $target), ['password' => 'assigned-password'])
            ->assertOk()
            ->assertJsonPath('password', 'assigned-password')
            ->assertJsonPath('password_generated', false);
    }

    /* ------------------------------------------------------------ 禁用与登录 */

    public function test_disabled_account_cannot_log_in(): void
    {
        $target = $this->user(UserRole::Editor, 'blocked@example.test');
        $target->forceFill(['password' => 'valid-password-1'])->save();

        // 先确认凭据本身是对的
        $this->post(route('login.store'), ['identifier' => 'blocked@example.test', 'password' => 'valid-password-1'])
            ->assertRedirect();
        $this->post(route('logout'));

        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('admin.users.toggle', $target), ['active' => false])->assertOk();

        $this->post(route('login.store'), ['identifier' => 'blocked@example.test', 'password' => 'valid-password-1'])
            ->assertSessionHasErrors('identifier');

        $this->assertStringContainsString('禁用', session('errors')->first('identifier'));
    }

    /**
     * 禁用必须立刻生效，而不是等既有会话自然过期 ——
     * Laravel 的 session guard 只在登录那一刻验凭据，所以需要 active 中间件兜住。
     */
    public function test_disabling_an_account_kills_its_existing_session(): void
    {
        $editor = $this->user(UserRole::Editor, 'session@example.test');
        $admin = $this->admin();

        // 会话生效
        $this->actingAs($editor)->get(route('proposals.index'))->assertOk();

        $this->actingAs($admin)->postJson(route('admin.users.toggle', $editor), ['active' => false])->assertOk();

        /*
         * 必须用 fresh()：actingAs 绑定的是内存里的那个实例，
         * 而真实请求每次都由 session guard 从数据库重新水合用户，
         * 因此只有 fresh() 才能复现「中间件看到的是已禁用的账号」。
         */
        $this->actingAs($editor->fresh())->get(route('proposals.index'))->assertRedirect(route('login'));

        $this->actingAs($editor->fresh())->postJson(route('events.store'), ['title' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('error', 'account_disabled');
    }

    public function test_enable_restores_login(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Editor, 'revive@example.test');
        $target->forceFill(['password' => 'valid-password-1'])->save();

        $this->actingAs($admin)->postJson(route('admin.users.toggle', $target), ['active' => false])->assertOk();
        $this->post(route('login.store'), ['identifier' => 'revive@example.test', 'password' => 'valid-password-1'])
            ->assertSessionHasErrors('identifier');

        $this->actingAs($admin)->postJson(route('admin.users.toggle', $target), ['active' => true])->assertOk();
        $this->post(route('login.store'), ['identifier' => 'revive@example.test', 'password' => 'valid-password-1'])
            ->assertRedirect();
    }

    public function test_toggle_is_logged_with_before_and_after(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Editor, 'toggle-log@example.test');

        $this->actingAs($admin)->postJson(route('admin.users.toggle', $target), ['active' => false])->assertOk();

        $log = UserActivityLog::where('user_id', $target->id)
            ->where('action', UserAction::Deactivated->value)
            ->firstOrFail();

        $this->assertSame('启用', $log->field_changes['is_active']['from']);
        $this->assertSame('已禁用', $log->field_changes['is_active']['to']);
    }

    /* ------------------------------------------------------------ 删除与恢复 */

    public function test_deleted_account_keeps_its_attribution_and_can_be_restored(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Editor, 'author@example.test');

        // 该账号创建一个条目，用于验证删除后归属仍在
        $event = $this->rawEvent(['created_by' => $target->id, 'updated_by' => $target->id]);

        $this->actingAs($admin)->deleteJson(route('admin.users.destroy', $target), ['reason' => '离职'])
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertSame($target->id, $event->fresh()->created_by);

        $this->actingAs($admin)->postJson(route('admin.users.restore', $target->id))->assertOk();
        $this->assertNull($target->fresh()->deleted_at);
    }

    public function test_delete_reason_is_written_into_the_log(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Viewer, 'reason@example.test');

        $this->actingAs($admin)->deleteJson(route('admin.users.destroy', $target), ['reason' => '重复账号'])->assertOk();

        $description = UserActivityLog::where('user_id', $target->id)
            ->where('action', UserAction::Deleted->value)
            ->value('description');

        $this->assertStringContainsString('重复账号', $description);
        // 软删除的可恢复性是承诺，日志里要写明白
        $this->assertStringContainsString('保留', $description);
    }

    /* ------------------------------------------------------------ 批量操作 */

    public function test_bulk_deactivate_affects_every_selected_account(): void
    {
        $admin = $this->admin();
        $a = $this->user(UserRole::Editor, 'bulk-a@example.test');
        $b = $this->user(UserRole::Editor, 'bulk-b@example.test');

        $this->actingAs($admin)->postJson(route('admin.users.bulk'), [
            'action' => 'deactivate',
            'ids' => [$a->id, $b->id],
        ])->assertOk()->assertJsonPath('affected', 2);

        $this->assertFalse($a->fresh()->isActive());
        $this->assertFalse($b->fresh()->isActive());
    }

    /**
     * 批量里混入自己时不能整批失败 —— 那会让人反复重试。
     * 逐条跳过并回报原因才是可用行为。
     */
    public function test_bulk_skips_self_and_reports_the_reason(): void
    {
        $admin = $this->admin();
        $other = $this->user(UserRole::Editor, 'bulk-other@example.test');

        $response = $this->actingAs($admin)->postJson(route('admin.users.bulk'), [
            'action' => 'deactivate',
            'ids' => [$admin->id, $other->id],
        ]);

        $response->assertOk()->assertJsonPath('affected', 1);

        $skipped = $response->json('skipped');
        $this->assertCount(1, $skipped);
        $this->assertSame($admin->id, $skipped[0]['id']);
        $this->assertStringContainsString('自己', $skipped[0]['reason']);

        // 自己没被改动，对方被改动了
        $this->assertTrue($admin->fresh()->isActive());
        $this->assertFalse($other->fresh()->isActive());
    }

    public function test_bulk_reports_unknown_ids_instead_of_failing_the_batch(): void
    {
        $admin = $this->admin();
        $real = $this->user(UserRole::Editor, 'bulk-real@example.test');

        $response = $this->actingAs($admin)->postJson(route('admin.users.bulk'), [
            'action' => 'deactivate',
            'ids' => [$real->id, 999999],
        ]);

        $response->assertOk()->assertJsonPath('affected', 1);

        $skipped = collect($response->json('skipped'));
        $this->assertTrue($skipped->contains(fn (array $row) => $row['id'] === 999999));
    }

    public function test_bulk_requires_at_least_one_selection_and_a_known_action(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.users.bulk'), ['action' => 'deactivate', 'ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('ids');

        $this->actingAs($admin)->postJson(route('admin.users.bulk'), ['action' => 'promote-all', 'ids' => [1]])
            ->assertStatus(422)->assertJsonValidationErrors('action');
    }

    /* ------------------------------------------------------------ 登录留痕 */

    public function test_login_records_last_login_and_a_log_entry(): void
    {
        $user = $this->user(UserRole::Editor, 'login-trace@example.test');

        $this->post(route('login.store'), ['identifier' => 'login-trace@example.test', 'password' => 'secret-password'])
            ->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_login_ip);
        $this->assertNotNull($user->last_seen_at);

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'actor_id' => $user->id,
            'action' => UserAction::LoggedIn->value,
        ]);
    }

    /* ------------------------------------------------------------ 详情页 */

    public function test_detail_page_shows_profile_and_activity_log(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Reviewer, 'detail@example.test');
        $this->manager()->logLogin($target);

        $this->actingAs($admin)->get(route('admin.users.show', $target))
            ->assertOk()
            ->assertSee('detail@example.test')
            ->assertSee('操作日志')
            ->assertSee('登录')
            ->assertSee('贡献与归属');
    }

    public function test_detail_page_exposes_the_restore_action_for_deleted_accounts(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Viewer, 'deleted-view@example.test');
        $this->manager()->delete($target, $admin);

        $this->actingAs($admin)->get(route('admin.users.show', $target->id))
            ->assertOk()
            ->assertSee('已删除')
            ->assertSee('恢复账号');
    }

    /* ------------------------------------------------------------ 服务层直接调用 */

    public function test_service_rejects_non_admin_actors(): void
    {
        $editor = $this->user(UserRole::Editor, 'service-editor@example.test');
        $target = $this->user(UserRole::Viewer, 'service-target@example.test');

        $this->expectException(WriteDeniedException::class);
        $this->manager()->setActive($target, false, $editor);
    }

    public function test_service_is_idempotent_when_setting_the_same_status(): void
    {
        $admin = $this->admin();
        $target = $this->user(UserRole::Editor, 'idempotent@example.test');

        $this->manager()->setActive($target, true, $admin);

        // 状态没变就不该写日志，否则审计表会被无意义记录塞满
        $this->assertSame(0, UserActivityLog::where('user_id', $target->id)->count());
    }
}
