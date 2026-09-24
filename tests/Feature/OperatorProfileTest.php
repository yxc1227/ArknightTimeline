<?php

namespace Tests\Feature;

use App\Enums\World;
use App\Models\Character;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Race;
use App\Support\TerraDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 干员简介。
 *
 * 这一块的核心不是「页面能打开」，而是**边界说清楚了**：
 * 本仓库只写一行与时间线相关的简介，权威内容外链到 PRTS；
 * 没写过简介的人物必须如实标注，而不是由系统编一句出来。
 */
class OperatorProfileTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    /**
     * 造一个人物。
     *
     * 归属走枢轴（一个人可以同时属多个阵营），因此阵营不放在 `$overrides` 里，
     * 而是单独一个参数 —— 免得读的人以为那是「一列属性」。
     */
    private function character(string $name, array $overrides = [], ?Faction $faction = null): Character
    {
        $character = Character::create([
            'name' => $name,
            'slug' => 'chr-'.md5($name),
            'sort_order' => 0,
            ...$overrides,
        ]);

        if ($faction !== null) {
            $character->factions()->attach($faction->id, ['sort_order' => 0]);
        }

        return $character;
    }

    /** 种族已经是字典：先用名字取（或建）一条，再挂到人物上。 */
    private function race(string $name): int
    {
        return Race::firstOrCreate(['slug' => 'race-'.md5($name)], ['name' => $name])->id;
    }

    /* ------------------------------------------------------------ 列表 */

    public function test_the_operator_index_is_public_and_lists_characters(): void
    {
        $this->character('阿米娅', ['codename' => 'Amiya', 'race_id' => $this->race('卡特斯')]);
        $this->character('塔露拉');

        // 与时间线一样是公开页面：读者顺着条目里的名字点进来
        $this->get(route('operators.index'))
            ->assertOk()
            ->assertSee('人员简介')
            ->assertSee('阿米娅')
            ->assertSee('Amiya')
            // 种族走字典：挂上 race_id 之后列表里必须真的显示出种族名
            ->assertSee('卡特斯')
            ->assertSee('塔露拉');
    }

    public function test_the_index_supports_search_and_faction_filtering(): void
    {
        $rhodes = Faction::create(['name' => '罗德岛', 'slug' => 'fac-rhodes']);
        $reunion = Faction::create(['name' => '整合运动', 'slug' => 'fac-reunion']);

        $this->character('阿米娅', ['codename' => 'Amiya'], $rhodes);
        $this->character('塔露拉', [], $reunion);

        $this->get(route('operators.index', ['q' => 'Amiya']))
            ->assertOk()->assertSee('阿米娅')->assertDontSee('塔露拉');

        $this->get(route('operators.index', ['faction' => $reunion->id]))
            ->assertOk()->assertSee('塔露拉')->assertDontSee('阿米娅');
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $this->character('百分号人物', ['codename' => 'wild_card']);

        // `_` 是 LIKE 的单字符通配符，必须转义：否则搜一个下划线就命中所有人
        $this->get(route('operators.index', ['q' => '%']))
            ->assertOk()
            ->assertDontSee('百分号人物');
    }

    /* ------------------------------------------------------------ 详情与简介 */

    public function test_the_detail_page_shows_a_written_profile(): void
    {
        $character = $this->character('凯尔希', ['description' => '罗德岛的核心成员之一。']);

        $this->get(route('operators.show', $character))
            ->assertOk()
            ->assertSee('罗德岛的核心成员之一。')
            ->assertSee('本仓库撰写')
            // 没写过简介的提示不应出现
            ->assertDontSee('本仓库还没有为这位人物写过简介');
    }

    /**
     * 没有人工简介时，回落到结构化「事实卡」，并且明确标注它不是考据结论。
     *
     * 这一条是这套设计的底线：系统**不编**一句看起来像简介的话出来。
     */
    public function test_a_character_without_a_profile_falls_back_to_structured_facts(): void
    {
        $faction = Faction::create(['name' => '阿戈尔', 'slug' => 'fac-aegir']);
        $character = $this->character('无名干员', ['race_id' => $this->race('阿戈尔')], $faction)->load('factions');

        $text = $character->profileText();

        $this->assertStringContainsString('阿戈尔', $text);
        $this->assertStringContainsString('种族：阿戈尔', $text);
        $this->assertTrue($character->needsProfile());

        $this->get(route('operators.show', $character))
            ->assertOk()
            ->assertSee('本仓库还没有为这位人物写过简介')
            ->assertSee('不是考据结论', false);
    }

    /* ------------------------------------------------------------ 外链 */

    public function test_the_wiki_link_defaults_to_the_character_name_and_its_own_world(): void
    {
        // 默认行为必须可推导：绝大多数人物的维基页面标题就是他的名字；
        // 且链接指向**该世界**自己的维基，而不是一个固定的 PRTS
        $amiya = $this->character('阿米娅');
        $this->assertSame('https://prts.wiki/w/'.rawurlencode('阿米娅'), $amiya->wikiUrl());
        $this->assertSame('PRTS 维基', $amiya->wikiLabel());

        // 塔卫二人物的链接指向 fz.wiki 的 /wiki/干员/ 命名空间，而不是 PRTS
        $talos = $this->character('佩丽卡', ['world' => World::Talos->value]);
        $this->assertSame('https://www.fz.wiki/wiki/干员/'.rawurlencode('佩丽卡'), $talos->wikiUrl());
        $this->assertSame('终末地 WIKI', $talos->wikiLabel());
    }

    /**
     * 但默认值必须可覆盖 —— 对方的命名空间不归我们管。
     */
    public function test_the_wiki_slug_overrides_the_default_title_and_the_base_is_configurable(): void
    {
        $character = $this->character('需要消歧义的名字', ['wiki_slug' => '另一个页面']);

        $this->assertStringEndsWith('/'.rawurlencode('另一个页面'), $character->wikiUrl());

        config(['timeline.character.wikis.terra.base' => 'https://mirror.example.test/wiki/']);

        $this->assertSame(
            'https://mirror.example.test/wiki/'.rawurlencode('另一个页面'),
            $character->wikiUrl(),
        );
    }

    public function test_detail_page_renders_the_external_link_safely(): void
    {
        $character = $this->character('阿米娅');

        $html = $this->get(route('operators.show', $character))->assertOk()->getContent();

        // 外链一律 target=_blank + rel=noopener noreferrer：
        // 前者是功能，后者避免把本站地址泄给外部站点、也避免对方拿到 window.opener
        $this->assertStringContainsString('https://prts.wiki/w/', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    /**
     * 人物按世界隔离：泰拉的列表里不应出现塔卫二的人物，反之亦然。
     *
     * 这是这次改动的核心 —— 两份名单混在一起，读者没法判断某个名字该去哪边找资料。
     */
    public function test_characters_are_scoped_to_their_world_on_the_index(): void
    {
        $this->character('阿米娅');                                  // 默认泰拉
        $this->character('佩丽卡', ['world' => World::Talos->value]);

        $this->get(route('operators.index', ['world' => World::Terra->value]))
            ->assertOk()
            ->assertSee('阿米娅')
            ->assertDontSee('佩丽卡');

        $this->get(route('operators.index', ['world' => World::Talos->value]))
            ->assertOk()
            ->assertSee('佩丽卡')
            ->assertDontSee('阿米娅');
    }

    /* ------------------------------------------------------------ 关联条目 */

    /**
     * 相关条目按世界分组。
     *
     * 罗德岛这类人物同时存在于两个世界的年表里，而两套纪年不可比 ——
     * 混成一条序列会让读者以为它们的先后关系是有意义的。
     */
    public function test_related_events_are_grouped_by_world(): void
    {
        $character = $this->character('跨世界人物');

        $terra = $this->rawEvent(['title' => '泰拉侧的相关条目']);
        $talos = $this->rawEvent([
            'title' => '塔卫二侧的相关条目',
            'world' => World::Talos->value,
            'start_index' => TerraDate::toIndex(5),
            'end_index' => TerraDate::toIndex(5),
        ]);

        $character->events()->attach([$terra->id, $talos->id]);

        $this->get(route('operators.show', $character))
            ->assertOk()
            ->assertSee('泰拉侧的相关条目')
            ->assertSee('塔卫二侧的相关条目')
            ->assertSee('泰拉历')
            ->assertSee('塔罗斯历')
            ->assertSee('2 条相关条目');
    }

    public function test_a_character_without_events_says_so(): void
    {
        $character = $this->character('尚无条目的人物');

        $this->get(route('operators.show', $character))
            ->assertOk()
            ->assertSee('还没有收录与')
            ->assertSee('0 条相关条目');
    }

    /* ------------------------------------------------------------ 站名 */

    public function test_the_site_uses_its_new_name(): void
    {
        $html = $this->get(route('timeline.index'))->assertOk()->getContent();

        $this->assertStringContainsString('明日方舟时间线', $html);
        $this->assertStringNotContainsString('泰拉时间线', $html);
    }

    public function test_the_operator_list_is_reachable_from_the_navigation(): void
    {
        $this->assertSame(url('/operators'), route('operators.index'));

        // 导航里必须真的挂上了入口，否则这个模块等于藏起来
        $this->get(route('timeline.index'))->assertOk()->assertSee('干员简介');
    }

    public function test_an_unknown_character_returns_404(): void
    {
        $this->get(route('operators.show', 'chr-does-not-exist'))->assertNotFound();
    }
}
