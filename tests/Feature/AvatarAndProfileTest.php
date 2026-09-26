<?php

namespace Tests\Feature;

use App\Enums\UserAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 头像上传与个人账号设置。
 *
 * 头像这一块的重点是**上传的图片不会被当成攻击载荷**：
 * 原样落盘的文件可以同时是合法图片和别的东西，因此用例覆盖了
 * 「重新编码是否真的发生」「SVG 是否被挡下」「输出头是否受控」。
 */
class AvatarAndProfileTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 用假磁盘隔离：既不污染 storage，也让「旧文件是否被删掉」可以直接断言
        Storage::fake('public');
    }

    /** 一张真实的 PNG（内容必须是真图片，否则过不了 image 规则）。 */
    private function png(int $width = 400, int $height = 400, string $name = 'avatar.png'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 210, 40, 40));

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();

        imagedestroy($image);

        return UploadedFile::fake()->createWithContent($name, $binary);
    }

    private function upload(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post(route('settings.profile.avatar'), ['avatar' => $file]);
    }

    /* ------------------------------------------------------------ 上传 */

    public function test_uploading_an_avatar_stores_a_reencoded_square_jpeg(): void
    {
        $user = $this->user(UserRole::Editor, 'avatar@example.test');

        $this->upload($user, $this->png(400, 200))->assertRedirect()->assertSessionHasNoErrors();

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);

        $binary = Storage::disk('public')->get($path);

        // 输入是 400×200 的 PNG，输出必须是 256×256 的 JPEG：
        // 这条断言同时证明了「居中裁剪」与「重新编码」都发生了
        $this->assertSame("\xFF\xD8\xFF", substr($binary, 0, 3), '输出应当是 JPEG（重新编码过的）');
        $this->assertSame([256, 256], array_slice(getimagesizefromstring($binary), 0, 2));

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'action' => UserAction::AvatarUpdated->value,
        ]);
    }

    public function test_avatar_upload_rejects_svg(): void
    {
        $user = $this->user(UserRole::Editor, 'svg@example.test');

        // SVG 可以内嵌 <script>，被浏览器当图片加载时照样执行，
        // 因此它是白名单里唯一必须坚决排除的格式
        $svg = UploadedFile::fake()->createWithContent(
            'evil.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><script>alert(1)</script></svg>',
        );

        $this->upload($user, $svg)->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_avatar_upload_rejects_non_images_and_bad_dimensions(): void
    {
        $user = $this->user(UserRole::Editor, 'baddims@example.test');

        // 不是图片
        $this->upload($user, UploadedFile::fake()->create('notes.pdf', 20))
            ->assertSessionHasErrors('avatar');

        // 1×1 的图片即使合法也没有展示价值，下限同样是约束
        $this->upload($user, $this->png(16, 16, 'tiny.png'))
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_upload_rejects_files_over_the_size_limit(): void
    {
        $user = $this->user(UserRole::Editor, 'toobig@example.test');
        $max = (int) config('identity.avatar.max_kilobytes');

        $this->upload($user, $this->png(400, 400, 'big.png')->size($max + 1))
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_replacing_an_avatar_deletes_the_previous_file(): void
    {
        $user = $this->user(UserRole::Editor, 'replace@example.test');

        $this->upload($user, $this->png());
        $first = $user->fresh()->avatar_path;

        $this->upload($user, $this->png(300, 300, 'second.png'));
        $second = $user->fresh()->avatar_path;

        $this->assertNotSame($first, $second);

        // 旧文件必须删掉：留着只会让磁盘随着换头像次数无限增长
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_user_can_remove_their_avatar_and_falls_back_to_initials(): void
    {
        $user = $this->user(UserRole::Editor, 'remover@example.test', '有头像的人');

        $this->upload($user, $this->png());
        $path = $user->fresh()->avatar_path;

        $this->actingAs($user)->delete(route('settings.profile.avatar'))->assertRedirect();

        $fresh = $user->fresh();
        $this->assertNull($fresh->avatar_path);
        $this->assertNull($fresh->avatarUrl(), '没有头像时应当回落到首字方块');
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseHas('user_activity_logs', ['action' => UserAction::AvatarRemoved->value]);
    }

    public function test_guest_cannot_upload_an_avatar(): void
    {
        $this->post(route('settings.profile.avatar'), ['avatar' => $this->png()])
            ->assertRedirect(route('login'));

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /* ------------------------------------------------------------ 输出 */

    public function test_avatar_is_served_with_controlled_headers(): void
    {
        $user = $this->user(UserRole::Editor, 'serve@example.test');
        $this->upload($user, $this->png());

        $response = $this->get($user->fresh()->avatarUrl());

        $response->assertOk();
        // 类型不由文件内容决定，也不给浏览器任何嗅探空间
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('inline; filename="avatar.jpg"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function test_avatar_url_changes_when_the_image_changes(): void
    {
        $user = $this->user(UserRole::Editor, 'cachebust@example.test');

        $this->upload($user, $this->png());
        $first = $user->fresh()->avatarUrl();

        $this->upload($user, $this->png(320, 320, 'next.png'));
        $second = $user->fresh()->avatarUrl();

        // URL 里带内容指纹，才有可能给响应打 immutable 长缓存
        $this->assertNotSame($first, $second);
    }

    public function test_avatar_endpoint_returns_404_when_there_is_no_image(): void
    {
        $user = $this->user(UserRole::Editor, 'noavatar@example.test');

        $this->get(route('avatars.show', ['user' => $user->id, 'v' => 'deadbeef']))->assertNotFound();
        $this->get(route('avatars.show', ['user' => 999999]))->assertNotFound();
    }

    public function test_avatar_endpoint_404s_when_the_file_vanished(): void
    {
        $user = $this->user(UserRole::Editor, 'ghost@example.test');
        $this->upload($user, $this->png());

        // 数据库有记录、磁盘上没有（手工清理过）：应回落到 404 让前端显示首字方块，
        // 而不是抛异常或返回碎图
        Storage::disk('public')->delete($user->fresh()->avatar_path);

        $this->get($user->fresh()->avatarUrl())->assertNotFound();
    }

    /* ------------------------------------------------------------ 昵称 */

    public function test_user_can_change_their_own_nickname(): void
    {
        $user = $this->user(UserRole::Editor, 'nickname@example.test', '旧昵称');
        $admin = $this->user(UserRole::Admin, 'nickname-admin@example.test');

        $this->actingAs($user)->put(route('settings.profile.update'), ['nickname' => '  新   昵称  '])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // 连续空白被折成一个空格：否则「新 昵称」与「新  昵称」会是两条不同记录，
        // 而人眼完全分不出来
        $this->assertSame('新 昵称', $user->fresh()->nickname);

        // 改动要留痕，操作人就是本人
        $log = $user->activityLogs()->where('action', UserAction::Updated->value)->firstOrFail();
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame('旧昵称', $log->field_changes['nickname']['from']);

        $this->assertNotNull($admin);
    }

    public function test_nickname_must_remain_unique_when_changed(): void
    {
        $this->user(UserRole::Editor, 'taken-nick@example.test', '已被占用');
        $user = $this->user(UserRole::Editor, 'mine-nick@example.test', '我的昵称');

        $this->actingAs($user)->put(route('settings.profile.update'), ['nickname' => '已被占用'])
            ->assertSessionHasErrors('nickname');

        $this->assertSame('我的昵称', $user->fresh()->nickname);

        // 但改成自己原来的昵称要允许（唯一性校验必须排除自身）
        $this->actingAs($user)->put(route('settings.profile.update'), ['nickname' => '我的昵称'])
            ->assertSessionHasNoErrors();
    }

    public function test_nickname_cannot_be_a_reserved_name(): void
    {
        $user = $this->user(UserRole::Editor, 'reserved-nick@example.test');

        $this->actingAs($user)->put(route('settings.profile.update'), ['nickname' => '博士'])
            ->assertSessionHasErrors('nickname');
    }

    /**
     * 设置页只能改昵称。
     *
     * 多提交的字段必须被忽略 —— 这条守的是「自助注册的账号不能自己提权」，
     * 也顺带证明了服务层用的是具体参数而不是把数组直接 fill 进去。
     */
    public function test_profile_update_ignores_privilege_fields(): void
    {
        $user = $this->user(UserRole::Viewer, 'noscalate@example.test');

        $this->actingAs($user)->put(route('settings.profile.update'), [
            'nickname' => '守规矩的人',
            'role' => UserRole::Admin->value,
            'is_active' => false,
            'strict_source_scope' => false,
        ])->assertRedirect();

        $fresh = $user->fresh();
        $this->assertSame('守规矩的人', $fresh->nickname);
        $this->assertSame(UserRole::Viewer, $fresh->role(), '角色不应被自服务修改');
        $this->assertTrue($fresh->isActive());
        $this->assertTrue($fresh->enforcesSourceScope());
    }

    /* ------------------------------------------------------------ 密码 */

    public function test_external_account_sets_a_password_without_proving_the_old_one(): void
    {
        // 外部渠道注册的账号：库里有密码哈希，但本人不知道
        $user = $this->user(UserRole::Viewer, 'nopassword@example.test');
        $user->forceFill(['password_set_at' => null])->save();

        $this->assertFalse($user->fresh()->hasUsablePassword());

        // 因此不要求「当前密码」，否则这类账号永远设不了密码
        $this->actingAs($user)->put(route('settings.profile.password'), [
            'password' => 'first-real-password',
            'password_confirmation' => 'first-real-password',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasUsablePassword());
        $this->assertNotNull($fresh->password_set_at);

        // 新密码立刻可用
        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'identifier' => 'nopassword@example.test',
            'password' => 'first-real-password',
        ])->assertRedirect();
    }

    public function test_changing_an_existing_password_requires_the_current_one(): void
    {
        $user = $this->user(UserRole::Editor, 'changepw@example.test');
        $user->forceFill(['password' => 'old-password-1', 'password_set_at' => now()])->save();

        $this->actingAs($user)->put(route('settings.profile.password'), [
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->put(route('settings.profile.password'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->put(route('settings.profile.password'), [
            'current_password' => 'old-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('logout'));
        $this->post(route('login.store'), ['identifier' => 'changepw@example.test', 'password' => 'old-password-1'])
            ->assertSessionHasErrors('identifier');
        $this->post(route('login.store'), ['identifier' => 'changepw@example.test', 'password' => 'new-password-1'])
            ->assertRedirect();
    }

    public function test_password_confirmation_must_match(): void
    {
        $user = $this->user(UserRole::Editor, 'mismatch@example.test');

        $this->actingAs($user)->put(route('settings.profile.password'), [
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-2',
        ])->assertSessionHasErrors('password');
    }

    public function test_guest_cannot_reach_the_profile_page(): void
    {
        $this->get(route('settings.profile'))->assertRedirect(route('login'));
    }
}
