<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 角色命名、昵称与登录名的分离，以及两者的全服唯一性。
 *
 * 「唯一」这件事看着简单，真正的坑在跨数据库的一致性上：
 * MySQL 的 utf8mb4_unicode_ci 不区分大小写、SQLite 区分，
 * 同一份数据在两边的校验结果会不同。因此这里的用例专门盯住
 * 「大小写」与「软删除行是否仍占用名字」这两种边界。
 */
class AccountNamingTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'medic-01',
            'nickname' => '医疗干员',
            'email' => 'medic@example.test',
            'role' => UserRole::Editor->value,
            'strict_source_scope' => true,
            'is_active' => true,
            ...$overrides,
        ];
    }

    /* ------------------------------------------------------------ 角色命名 */

    public function test_role_labels_follow_the_arknights_worldview(): void
    {
        // 对外名称用罗德岛的编制序列；内部值仍是与数据库绑定的英文标识，
        // 因此将来改叫法不需要动数据
        $this->assertSame('博士', UserRole::Admin->label());
        $this->assertSame('精英干员', UserRole::Reviewer->label());
        $this->assertSame('干员', UserRole::Editor->label());
        $this->assertSame('预备干员', UserRole::Viewer->label());

        // 等级顺序与权限判定不受命名影响
        $this->assertTrue(UserRole::Admin->atLeast(UserRole::Reviewer));
        $this->assertFalse(UserRole::Viewer->atLeast(UserRole::Editor));
        $this->assertTrue(UserRole::Admin->canAdminister());
        $this->assertFalse(UserRole::Reviewer->canAdminister());

        // 内部值保持英文，数据库与既有代码不受影响
        $this->assertSame('admin', UserRole::Admin->value);
    }

    public function test_every_role_carries_a_description_that_explains_its_powers(): void
    {
        $descriptions = [];

        foreach (UserRole::cases() as $role) {
            $description = $role->description();

            $this->assertNotSame('', trim($description), "{$role->value} 缺少权限说明");
            $descriptions[] = $description;
        }

        // 四个角色的说明必须互不相同，否则界面上会出现两段一模一样的话
        $this->assertSame(count($descriptions), count(array_unique($descriptions)));

        // 角色选择器需要带说明的选项
        $this->assertCount(4, UserRole::describedOptions());
        $this->assertSame('博士', UserRole::describedOptions()[3]['label']);
    }

    /* ------------------------------------------------------------ 登录名格式 */

    public function test_handle_must_start_with_a_letter_and_stay_ascii(): void
    {
        $admin = $this->admin();

        foreach (['1abc', '中文登录名', 'has space', 'has@sign', 'ab', '-leading'] as $invalid) {
            $this->actingAs($admin)
                ->postJson(route('admin.users.store'), $this->payload(['name' => $invalid]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('name');
        }

        // 合法样例（含大写，会被归一化成小写）
        $this->actingAs($admin)
            ->postJson(route('admin.users.store'), $this->payload(['name' => 'Medic_01-x']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['name' => 'medic_01-x']);
    }

    public function test_handle_is_normalised_to_lowercase_so_uniqueness_is_unambiguous(): void
    {
        $admin = $this->admin();

        // 全小写存储，是为了让「唯一」在 MySQL 与 SQLite 上含义一致：
        // MySQL 的排序规则不区分大小写、SQLite 区分，不归一化就会两边行为不同
        $target = $this->user(UserRole::Editor, 'lower@example.test');

        $this->actingAs($admin)->putJson(route('admin.users.update', $target), $this->payload([
            'name' => 'MixedCase',
            'email' => 'lower@example.test',
        ]))->assertOk();

        $this->assertSame('mixedcase', $target->fresh()->name);
    }

    /* ------------------------------------------------------------ 唯一性 */

    public function test_duplicate_handle_is_rejected(): void
    {
        $admin = $this->admin();
        $this->user(UserRole::Editor, 'existing@example.test', '已存在的人');

        $this->actingAs($admin)
            ->postJson(route('admin.users.store'), $this->payload(['name' => 'existing']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_duplicate_nickname_is_rejected_regardless_of_case(): void
    {
        $admin = $this->admin();
        $this->user(UserRole::Editor, 'nick-a@example.test', 'Researcher');

        // 大小写不同但看起来一样，允许共存等于给冒充留门
        $this->actingAs($admin)
            ->postJson(route('admin.users.store'), $this->payload(['nickname' => 'researcher']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('nickname');
    }

    public function test_names_are_normalised_so_visually_identical_ones_cannot_coexist(): void
    {
        $admin = $this->admin();
        $this->user(UserRole::Editor, 'space-a@example.test', '考据 员');

        // 「考据  员」（两个空格）在人眼里与「考据 员」完全一样，
        // 唯一索引本身挡不住这种重名
        $this->actingAs($admin)
            ->postJson(route('admin.users.store'), $this->payload(['nickname' => '考据   员']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('nickname');
    }

    /**
     * 软删除的账号仍然占用它的登录名与昵称。
     *
     * 这是刻意的：恢复账号时不必重新协调命名，也防止
     * 「删掉某人再用同名注册」这种身份冒用。
     * 因此校验必须包含软删除行 —— 否则会通过校验、然后在唯一索引上炸出 500。
     */
    public function test_soft_deleted_accounts_still_hold_their_names(): void
    {
        $admin = $this->admin();
        $victim = $this->user(UserRole::Editor, 'gone@example.test', '已经离开的人');

        $this->actingAs($admin)->deleteJson(route('admin.users.destroy', $victim))->assertOk();

        $this->actingAs($admin)
            ->postJson(route('admin.users.store'), $this->payload([
                'name' => 'gone',
                'nickname' => '已经离开的人',
                'email' => 'gone-again@example.test',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'nickname']);
    }

    public function test_reserved_names_are_rejected_for_both_handle_and_nickname(): void
    {
        $admin = $this->admin();

        // 昵称能出现在时间线的标注与版本记录旁边，因此绝不能让人叫「博士」或「官方」
        foreach (['博士', '官方', 'admin', 'ROOT'] as $reserved) {
            $this->actingAs($admin)
                ->postJson(route('admin.users.store'), $this->payload([
                    'name' => 'plainhandle',
                    'nickname' => $reserved,
                    'email' => 'reserved-'.md5($reserved).'@example.test',
                ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('nickname');
        }

        $this->actingAs($admin)
            ->postJson(route('admin.users.store'), $this->payload(['name' => 'admin', 'email' => 'admin2@example.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /* ------------------------------------------------------------ 分离 */

    public function test_nickname_and_handle_are_independent(): void
    {
        $admin = $this->admin();
        $this->user(UserRole::Editor, 'renamed@example.test', '旧昵称');

        $target = User::whereHandleIs('renamed')->firstOrFail();

        // 改昵称不影响登录名
        $this->actingAs($admin)->putJson(route('admin.users.update', $target), $this->payload([
            'name' => 'renamed',
            'nickname' => '全新昵称',
            'email' => 'renamed@example.test',
        ]))->assertOk();

        $fresh = $target->fresh();
        $this->assertSame('renamed', $fresh->name);
        $this->assertSame('全新昵称', $fresh->nickname);
        $this->assertSame('全新昵称', $fresh->displayLabel());
    }

    public function test_login_accepts_either_email_or_handle(): void
    {
        $user = $this->user(UserRole::Editor, 'dual@example.test', '双通道用户');
        $user->forceFill(['password' => 'dual-password'])->save();

        // 邮箱
        $this->post(route('login.store'), ['identifier' => 'dual@example.test', 'password' => 'dual-password'])
            ->assertRedirect();
        $this->post(route('logout'));

        // 登录名
        $this->post(route('login.store'), ['identifier' => 'dual', 'password' => 'dual-password'])
            ->assertRedirect();
        $this->post(route('logout'));

        // 大写登录名同样可用（登录名统一以小写存储，因此输入侧也做归一化）
        $this->post(route('login.store'), ['identifier' => 'DUAL', 'password' => 'dual-password'])
            ->assertRedirect();
    }

    public function test_unknown_identifier_reports_a_single_generic_error(): void
    {
        // 错误信息不能区分「账号不存在」与「密码错误」，否则就是一个账号枚举探针
        $this->post(route('login.store'), ['identifier' => 'nobody@example.test', 'password' => 'whatever-1'])
            ->assertSessionHasErrors('identifier');

        $this->assertSame(
            '邮箱（或登录名）与密码不正确。',
            session('errors')->first('identifier'),
        );
    }

    /* ------------------------------------------------------------ 列表 */

    public function test_account_list_exposes_both_names_and_can_sort_by_nickname(): void
    {
        $admin = $this->admin('root@example.test');
        $this->user(UserRole::Editor, 'aaa@example.test', '乙昵称');
        $this->user(UserRole::Editor, 'zzz@example.test', '甲昵称');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['sort' => 'nickname', 'direction' => 'asc']))
            ->assertOk()
            ->assertSee('aaa')      // 登录名
            ->assertSee('乙昵称')    // 昵称
            ->assertSee('甲昵称');

        // 两个名字都能作为排序键（白名单里同时有 name 与 nickname）
        $this->assertContains('nickname', User::SORTABLE);
        $this->assertContains('name', User::SORTABLE);
    }

    public function test_search_accepts_wildcards_but_does_not_treat_them_as_such(): void
    {
        $admin = $this->admin('root@example.test');
        $this->user(UserRole::Editor, 'wildcard_nick@example.test', '下划线昵称');

        // `_` 是 LIKE 的单字符通配符，必须被转义，否则搜一个 _ 会命中所有人
        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => '_']))
            ->assertOk()
            ->assertDontSee('wildcard_nick@example.test');
    }
}
