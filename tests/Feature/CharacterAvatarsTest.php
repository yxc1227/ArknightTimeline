<?php

namespace Tests\Feature;

use App\Enums\World;
use App\Models\Character;
use App\Support\CharacterAvatars;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 人物头像。
 *
 * 头像是**可选装饰**：名单没给图、文件缺失、清单丢失，页面都必须照常完整 ——
 * 退回「首字方块」而不是渲染一张碎图。这一组测试守的就是这条退路，
 * 以及「清单 → 人物」的关联规则：按世界 + 人物名精确匹配，绝不模糊猜。
 */
class CharacterAvatarsTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------ 页面渲染 */

    public function test_a_character_with_an_avatar_renders_the_image_on_both_pages(): void
    {
        $character = Character::create([
            'name' => '阿米娅',
            'slug' => 'chr-amiya',
            'avatar' => 'assets/avatars/terra/阿米娅.png',
        ]);

        $this->get(route('operators.index'))
            ->assertOk()
            ->assertSee('operator-card__avatar', false)
            ->assertSee('阿米娅.png', false);

        $this->get(route('operators.show', $character))
            ->assertOk()
            ->assertSee('operator-mark--img', false);
    }

    public function test_a_character_without_an_avatar_falls_back_to_the_initial_block(): void
    {
        $character = Character::create(['name' => '塔露拉', 'slug' => 'chr-talulah']);

        $this->get(route('operators.index'))
            ->assertOk()
            ->assertDontSee('operator-card__avatar', false);

        $this->get(route('operators.show', $character))
            ->assertOk()
            ->assertSee('operator-mark"', false)
            ->assertDontSee('operator-mark--img', false);
    }

    /* ------------------------------------------------------------ 清单关联 */

    public function test_the_manifest_is_associated_by_world_and_name(): void
    {
        // 未指定世界的人物按泰拉处理（Character::world() 的既定语义），必须能被 prts 段找到
        $terra = Character::create(['name' => '阿米娅', 'slug' => 'chr-amiya']);
        // 泰拉与塔卫二是两套独立名单：同名也只是巧合，必须各回各的世界
        $talos = Character::create(['name' => '佩丽卡', 'slug' => 'chr-perlica', 'world' => World::Talos]);
        $noFile = Character::create(['name' => '凯尔希', 'slug' => 'chr-kelci']);
        // 管理员是名单归并的产物（管理员·男 入库、·女 跳过），头像跟同一裁决走
        $endmin = Character::create(['name' => '管理员', 'slug' => 'chr-endmin', 'world' => World::Talos]);

        $root = storage_path('framework/testing/avatars-'.uniqid());
        File::makeDirectory($root.'/terra', 0777, true, true);
        File::makeDirectory($root.'/talos', 0777, true, true);
        File::put($root.'/terra/阿米娅.png', 'png');
        File::put($root.'/talos/佩丽卡.webp', 'webp');
        File::put($root.'/talos/管理员·男.webp', 'webp');
        // 凯尔希在清单里、文件却没复制过来：不能写出一个 404 的路径

        $manifest = [
            'prts' => ['items' => [
                // 清单里的 file 是本地绝对路径：关联只取文件名，不关心它从哪来
                ['name' => '阿米娅', 'file' => '/somewhere/else/prts/阿米娅.png'],
                ['name' => '凯尔希', 'file' => '/somewhere/else/prts/凯尔希.png'],
                ['name' => '名单里没有的人', 'file' => '/somewhere/else/prts/名单里没有的人.png'],
            ]],
            'fz' => ['items' => [
                ['name' => '佩丽卡', 'file' => '/somewhere/else/fz/佩丽卡.webp'],
                ['name' => '管理员·男', 'file' => '/somewhere/else/fz/管理员·男.webp'],
            ]],
        ];
        $manifestPath = $root.'/manifest.json';
        File::put($manifestPath, (string) json_encode($manifest, JSON_UNESCAPED_UNICODE));

        $stats = CharacterAvatars::associate($manifestPath, $root);

        $this->assertSame(3, $stats['linked']);
        $this->assertSame(1, $stats['missing_file']);
        $this->assertSame(['prts:名单里没有的人'], $stats['unknown']);

        $this->assertSame('assets/avatars/terra/阿米娅.png', $terra->fresh()->avatar);
        $this->assertSame('assets/avatars/talos/佩丽卡.webp', $talos->fresh()->avatar);
        $this->assertSame('assets/avatars/talos/管理员·男.webp', $endmin->fresh()->avatar);
        // 文件缺失：路径不能落到库里，否则页面就是一张碎图
        $this->assertNull($noFile->fresh()->avatar);

        File::deleteDirectory($root);
    }

    public function test_a_missing_or_broken_manifest_is_reported_as_null(): void
    {
        $this->assertNull(CharacterAvatars::associate(
            storage_path('framework/testing/no-such-manifest.json'),
            storage_path('framework/testing'),
        ));

        $broken = storage_path('framework/testing/broken-manifest-'.uniqid().'.json');
        File::put($broken, '{not json');

        $this->assertNull(CharacterAvatars::associate($broken, storage_path('framework/testing')));

        File::delete($broken);
    }
}
