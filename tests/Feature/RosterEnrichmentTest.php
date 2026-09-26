<?php

namespace Tests\Feature;

use App\Enums\World;
use App\Models\Character;
use Database\Seeders\TimelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 名单补全的边界：**只补不覆盖**。
 *
 * 名单接入后，`fillMissingFacts` 负责把来源里有、而库里空着的字段补上。
 * 这里的红线是：人工核过的字段（以及一切非空字段）永远不被批量导出冲掉 ——
 * 英文名（codename）是新加入这条规则的字段：空着才补来源写法，
 * 已有的一律不碰；名单里没有的人物保持空，绝不自己转写。
 */
class RosterEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    /** 反射调用种子的私有补全方法：直接对「一条 entry × 一个人物」验证边界。 */
    private function enrich(Character $character, array $entry): void
    {
        $method = new \ReflectionMethod(TimelineSeeder::class, 'fillMissingFacts');
        $method->invoke(new TimelineSeeder, $character, $entry, new Collection, new Collection);
    }

    /** 名单条目的最小形状（无归属 / 无种族 / 无出身地，聚焦代号本身）。 */
    private function entry(?string $codename): array
    {
        return ['affiliations' => [], 'race' => null, 'birth_place' => null, 'codename' => $codename];
    }

    public function test_a_blank_codename_is_filled_from_the_roster(): void
    {
        // 手工批次先建的人物（当初对社区转写存疑而留空），名单接入后照来源写法补上
        $character = Character::create(['name' => '管理员', 'slug' => 'chr-endmin', 'world' => World::Talos]);

        $this->enrich($character, $this->entry('Endministrator'));

        $this->assertSame('Endministrator', $character->fresh()->codename);
    }

    public function test_an_existing_codename_is_never_overwritten(): void
    {
        // 只补不覆盖是这条路径的立身之本：批量导出不能冲掉人工核过的字段
        $character = Character::create([
            'name' => '狼卫', 'slug' => 'chr-wulfgard', 'world' => World::Talos, 'codename' => 'Wulfgard',
        ]);

        $this->enrich($character, $this->entry('Someone Else'));

        $this->assertSame('Wulfgard', $character->fresh()->codename);
    }

    public function test_a_character_absent_from_the_roster_keeps_no_codename(): void
    {
        // 阿伯莉不在干员名单里（不是可玩干员）：没有来源就不写，绝不自己转写
        $character = Character::create(['name' => '阿伯莉', 'slug' => 'chr-aberi', 'world' => World::Talos]);

        $this->enrich($character, $this->entry(null));

        $this->assertNull($character->fresh()->codename);
    }
}
