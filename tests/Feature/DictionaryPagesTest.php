<?php

namespace Tests\Feature;

use App\Enums\World;
use App\Models\Character;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Place;
use App\Models\Race;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 词典的四个大类：地名 / 组织 / 种族 / 词条。
 *
 * 它们各自成页（而不是挤在一页的标签里），因为回答的是四个不同的问题：
 * 在哪里 / 谁在做 / 哪些人 / 这词什么意思。
 * 这里守的不是排序，而是**能不能被查到、能不能被链到**：
 * 层级是不是真的成树、锚点是不是真的存在、该排除的有没有被排除。
 * 数据齐备但点不进去，等于没做。
 */
class DictionaryPagesTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    private function race(string $name = '德拉克', ?string $description = '测试用种族概要。'): Race
    {
        return Race::create([
            'name' => $name,
            'slug' => 'race-'.$name,
            'english' => 'Draco',
            'description' => $description,
        ]);
    }

    private function place(string $name, string $kind = 'settlement', ?Place $parent = null, string $world = 'terra'): Place
    {
        return Place::create([
            'name' => $name,
            'slug' => 'place-'.$name,
            'kind' => $kind,
            'parent_id' => $parent?->id,
            'world' => $world,
        ]);
    }

    private function term(string $name = '金律乐章', string $category = 'object', ?string $world = 'terra'): Term
    {
        return Term::create([
            'name' => $name,
            'slug' => 'term-'.$name,
            'category' => $category,
            'definition' => '测试用释义。',
            'origin' => '莱塔尼亚卷',
            // null 是有意义的值：两个世界通用的概念（如「源石」）
            'world' => $world,
        ]);
    }

    private function faction(string $name, string $kind, ?Faction $parent = null): Faction
    {
        return Faction::create([
            'name' => $name,
            'slug' => 'fac-fixture-'.md5($name),
            'kind' => $kind,
            'parent_id' => $parent?->id,
        ]);
    }

    /**
     * 造一个**有链接**的组织。
     *
     * 组织的世界归属是推导的（自身在本世界有条目或人物即属于本世界），
     * 因此光建一个阵营是看不见的 —— 必须先挂上一条条目。
     */
    private function organization(string $name, string $kind = 'enterprise', string $world = 'terra', ?Faction $parent = null): Faction
    {
        $faction = $this->faction($name, $kind, $parent);

        $this->rawEvent(['title' => $name.'的相关条目', 'world' => $world])
            ->factions()->attach($faction->id, ['role' => 'involved']);

        return $faction;
    }

    /** 四页各自公开可用，且只讲自己那一类。 */
    public function test_each_kind_has_its_own_page(): void
    {
        $race = $this->race();
        $this->place('测试城');
        $this->term();
        $this->organization('测试商会');

        $this->get(route('places.index'))->assertOk()->assertSee('测试城')->assertDontSee($race->name);
        $this->get(route('races.index'))->assertOk()->assertSee($race->name)->assertDontSee('测试城');
        $this->get(route('organizations.index'))->assertOk()->assertSee('测试商会')->assertDontSee('测试城');
        $this->get(route('terms.index'))->assertOk()->assertSee('金律乐章')->assertDontSee('测试城');
    }

    /**
     * 各页的侧栏检索（与时间线同形的那套）：命中只留命中项，
     * 地名树必须连祖先一起留下 —— 读者要看的是「它挂在树的哪个位置」，
     * 只给一行孤零零的匹配项，层级信息反而丢了。
     */
    public function test_keyword_and_type_filters_narrow_the_dictionary_pages(): void
    {
        $this->race('德拉克');
        $this->race('阿戈尔');

        $this->place('乌萨斯', 'nation');
        $parent = $this->place('维多利亚', 'nation');
        $this->place('伦蒂尼姆', 'settlement', $parent);
        $this->place('测试城');

        $this->term('源石技艺', 'term');
        // 第二个词条不用「天灾」这类词：它会撞上泰拉切换器的固定文案（「源石与天灾之下的诸国」）
        $this->term('圣愚', 'concept');

        $this->organization('莱茵生命', 'enterprise');
        $this->organization('测试商会', 'society');

        // 种族：按名称 / 英文名 / 说明匹配
        $this->get(route('races.index', ['q' => '德拉克']))
            ->assertOk()
            ->assertSee('德拉克')
            ->assertDontSee('阿戈尔');

        // 地名：命中子节点时父链必须保留
        $this->get(route('places.index', ['q' => '伦蒂尼姆']))
            ->assertOk()
            ->assertSee('伦蒂尼姆')
            ->assertSee('维多利亚')
            ->assertDontSee('测试城');

        // 地名：类型筛选同样只留命中项与其祖先
        $this->get(route('places.index', ['kind' => 'settlement']))
            ->assertOk()
            ->assertSee('伦蒂尼姆')
            ->assertDontSee('乌萨斯');

        // 词条：按分类收敛
        $this->get(route('terms.index', ['category' => 'term']))
            ->assertOk()
            ->assertSee('源石技艺')
            ->assertDontSee('圣愚');

        // 组织：按类型收敛（政体与地域本来就不在这一页）
        $this->get(route('organizations.index', ['kind' => 'society']))
            ->assertOk()
            ->assertSee('测试商会')
            ->assertDontSee('莱茵生命');
    }

    /**
     * 组织按世界分列。
     *
     * 泰拉与塔卫二各有一批自己的组织，混在一页里读者无从判断某个名字属于哪边的历史。
     */
    public function test_organizations_are_split_by_world(): void
    {
        $this->organization('泰拉商会', 'enterprise', 'terra');
        $this->organization('塔卫二商会', 'enterprise', 'talos');

        $this->get(route('organizations.index', ['world' => 'terra']))
            ->assertOk()
            ->assertSee('泰拉商会')
            ->assertDontSee('塔卫二商会');

        $this->get(route('organizations.index', ['world' => 'talos']))
            ->assertOk()
            ->assertSee('塔卫二商会')
            ->assertDontSee('泰拉商会');
    }

    /**
     * 自身还没有条目的组织（内部部门、委员会）**随上级**归到同一个世界。
     *
     * 少了这条规则，「精英干员」「医疗部」这类永远不会自己出现在条目里的组织
     * 会在两页里都不见 —— 一个组织凭空消失，比归类可疑更难发现。
     */
    public function test_organizations_without_links_inherit_their_parents_world(): void
    {
        $parent = $this->organization('泰拉母公司', 'enterprise', 'terra');
        $child = $this->faction('泰拉分公司', 'agency', $parent);

        // 分公司自身零条目、零人物
        $this->assertSame(0, $child->events()->count());

        $this->get(route('organizations.index', ['world' => 'terra']))
            ->assertOk()
            ->assertSee('泰拉母公司')
            ->assertSee('泰拉分公司');

        $this->get(route('organizations.index', ['world' => 'talos']))
            ->assertOk()
            ->assertDontSee('泰拉分公司');
    }

    /**
     * 每个组织都必须能归到**至少一个**世界。
     *
     * 推导归属有个必然的边界：自身零链接、上级又定不了世界的组织，会在两页里都不出现。
     * 这个测试把它变成构建期的错误，而不是页面上的一处空白。
     */
    public function test_every_organization_resolves_to_a_world(): void
    {
        $orphans = Faction::organizations()
            ->with('parent')
            ->get()
            ->reject(fn (Faction $faction) => $faction->belongsToWorld(World::Terra)
                || $faction->belongsToWorld(World::Talos))
            ->pluck('name');

        $this->assertSame([], $orphans->all(), '这些组织既没有条目/人物，上级也定不了世界 —— 它们会在两页里都消失');
    }

    /** 四个大类都要在导航里有自己的入口，否则「大类」等于藏起来。 */
    public function test_every_kind_is_reachable_from_the_navigation(): void
    {
        $html = $this->get(route('timeline.index'))->assertOk()->getContent();

        foreach ([route('places.index'), route('organizations.index'), route('races.index'), route('terms.index')] as $url) {
            $this->assertStringContainsString('href="'.$url.'"', $html);
        }

        // 旧的合并页不该再有入口
        $this->assertStringNotContainsString('href="/lexicon"', $html);
    }

    /** 旧的合并页要么 301 走人，不能 404 —— 站外引用与旧书签都指着它。 */
    public function test_legacy_lexicon_url_redirects(): void
    {
        $this->get('/lexicon')->assertRedirect(route('terms.index'));
    }

    /**
     * 锚点是别处深链的落点：干员卡的种族、条目页的发生地都靠 `#race-xxx` / `#place-xxx` 指过来。
     * 少了它，链接只会把人丢到页面顶端。
     */
    public function test_dictionary_entries_are_anchored_for_deep_linking(): void
    {
        $place = $this->place('测试城');
        $race = $this->race();
        $term = $this->term();
        $org = $this->organization('测试商会');

        $this->get(route('places.index'))->assertOk()->assertSee('id="place-'.$place->slug.'"', false);
        $this->get(route('races.index'))->assertOk()->assertSee('id="race-'.$race->slug.'"', false);
        $this->get(route('terms.index'))->assertOk()->assertSee('id="term-'.$term->slug.'"', false);
        $this->get(route('organizations.index'))->assertOk()->assertSee('id="org-'.$org->slug.'"', false);
    }

    /**
     * 深链落点必须**让开顶部导航**（`.topbar` 是 62px 的 sticky 头）。
     *
     * 上一条测试守着「锚点在页面上」，这一条守着「跳过去能看见」——
     * 少了它，落点会被导航栏盖住：位置其实到了，读者却看不到，只会以为跳错了地方。
     *
     * 只能静态校验 CSS：这里没有浏览器，跑不出真实的滚动位置。
     * 但它挡得住「又多了一类会被链过去的行，却忘了让它让开导航」——
     * 时间线的时代色带与词典页的行都各自踩过一次，说明这不是一次性的疏忽。
     */
    public function test_anchor_targets_clear_the_sticky_header(): void
    {
        $css = (string) file_get_contents(public_path('assets/app.css'));

        // 让位高度只定义一次：顶栏高度是唯一来源，落点偏移由它推导 ——
        // 否则改了导航栏高度就得满文件找（有快速导航条的页面还要再叠一条，
        // 那一条的高度由 JS 量进 --quick-nav-h，同样不该在这里写死）
        $this->assertMatchesRegularExpression(
            '/--topbar-h:\s*\d+px/',
            $css,
            '顶栏高度没有定义成 --topbar-h',
        );

        $this->assertMatchesRegularExpression(
            '/--anchor-offset:\s*calc\(\s*var\(--topbar-h\)/',
            $css,
            '落点偏移没有从 --topbar-h 推导 —— 顶栏一变高，落点就又被压住了',
        );

        foreach ([
            '.card[id]',
            '.operator-card[id]',
            'table.tbl td[id]',
            '.era-band',
            '.era-period',
            '.place-tree tbody tr[data-place-row]',
        ] as $selector) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($selector, '/').'\s*,?[^{]*\{[^}]*scroll-margin-top:\s*(?:calc\()?var\(--anchor-offset\)/s',
                $css,
                "深链落点 {$selector} 没有让开顶部导航，条目标题会被导航栏盖住",
            );
        }
    }

    public function test_places_are_scoped_to_the_selected_world(): void
    {
        $this->place('泰拉测试城');
        $this->place('塔卫二测试城', 'region', null, 'talos');

        // 地名天然分世界：两个世界的聚落名不该串台
        $this->get(route('places.index', ['world' => 'terra']))
            ->assertOk()
            ->assertSee('泰拉测试城')
            ->assertDontSee('塔卫二测试城');

        $this->get(route('places.index', ['world' => 'talos']))
            ->assertOk()
            ->assertSee('塔卫二测试城')
            ->assertDontSee('泰拉测试城');
    }

    /** 层级要先被读出来，才谈得上「可聚合」——因此父必须排在子之前。 */
    public function test_place_tree_renders_parents_before_children(): void
    {
        $parent = $this->place('测试王国', 'kingdom');
        $child = $this->place('测试都城', 'city', $parent);

        $html = $this->get(route('places.index'))->assertOk()->getContent();

        $parentAt = mb_strpos($html, 'id="place-'.$parent->slug.'"');
        $childAt = mb_strpos($html, 'id="place-'.$child->slug.'"');

        $this->assertNotFalse($parentAt);
        $this->assertNotFalse($childAt);
        $this->assertLessThan($childAt, $parentAt, '父地名应当排在子地名之前');
    }

    /**
     * 地名筛选必须带上**下辖**地名。
     *
     * 这是层级这一栏存在的全部意义：选了「维多利亚」却看不到挂在「伦蒂尼姆」下的条目，
     * 那这棵树就只是装饰 —— 与阵营筛选带子阵营是同一条规矩。
     */
    public function test_place_filter_includes_descendants(): void
    {
        $parent = $this->place('测试王国', 'kingdom');
        $child = $this->place('测试都城', 'city', $parent);
        $event = $this->rawEvent(['place_id' => $child->id]);

        $matched = Event::filter(['world' => World::Terra->value, 'place_id' => $parent->id])->pluck('id');

        $this->assertContains($event->id, $matched->all());

        // 自身 + 全部下辖，按「先自己、再子级」的顺序
        $this->assertSame([$parent->id, $child->id], $parent->selfAndDescendantIds());
    }

    /**
     * 「组织」页只收**非政体**。
     *
     * 政体（维多利亚）与地域（文明环带）在地名页各有一个节点，有疆域、有上下层级；
     * 把它们混进组织页，「维多利亚」与「莱茵生命」就会并列在一起，
     * 而读者无法判断这两者是不是同一类东西 —— 这正是分类型要解决的问题。
     */
    public function test_organization_page_excludes_polities_and_territories(): void
    {
        $this->organization('测试商会', 'enterprise');

        // 政体与地域**也要挂上链接**，否则它们只是因为「没链接、定不了世界」而不出现 ——
        // 那样这条断言就是空的，测不出「按类型排除」这件事
        $this->organization('测试王国', 'polity');
        $this->organization('测试聚居带', 'territory');

        $this->get(route('organizations.index'))
            ->assertOk()
            ->assertSee('测试商会')
            ->assertDontSee('测试王国')
            ->assertDontSee('测试聚居带');

        // 判据只有一处：模型作用域与页面用的是同一个
        $this->assertSame(
            ['enterprise'],
            Faction::organizations()
                ->whereIn('name', ['测试商会', '测试王国', '测试聚居带'])
                ->get()
                ->map(fn (Faction $faction) => $faction->kind->value)
                ->all(),
        );
    }

    /**
     * 词条按世界分列。
     *
     * 词条与地名、组织不同 —— 它是一批**带着视角的释义**：
     * 「金律乐章」是莱塔尼亚的立国宪章，「协议」是塔卫二上的失落之物。
     */
    public function test_terms_are_split_by_world(): void
    {
        $this->term('泰拉词条', 'object', 'terra');
        $this->term('塔卫二词条', 'object', 'talos');

        $this->get(route('terms.index', ['world' => 'terra']))
            ->assertOk()
            ->assertSee('泰拉词条')
            ->assertDontSee('塔卫二词条');

        $this->get(route('terms.index', ['world' => 'talos']))
            ->assertOk()
            ->assertSee('塔卫二词条')
            ->assertDontSee('泰拉词条');
    }

    /**
     * 通用词条在两页都要出现，并且**标出来**。
     *
     * 「源石」这类概念两个世界都成立，强行归给某一方反而错；
     * 而若只在某一页列出、又没有任何标记，读者会以为另一页漏了它。
     */
    public function test_shared_terms_appear_on_both_worlds_and_are_marked(): void
    {
        $this->term('通用词条', 'concept', null);

        foreach (['terra', 'talos'] as $world) {
            $this->get(route('terms.index', ['world' => $world]))
                ->assertOk()
                ->assertSee('通用词条')
                ->assertSee('通用');
        }

        $this->assertTrue(Term::where('name', '通用词条')->firstOrFail()->isShared());
    }

    /**
     * 种族**不分世界**（用户明确要求）。
     *
     * 它是一份共享的分类：同一种族本来就出现在两个世界的历史里，
     * 按世界切分只会把同一个概念拆成两份。这里把「不给它世界切换器」钉住 ——
     * 否则下一个人会顺手把它改成和组织、词条一样的形状。
     */
    public function test_races_stay_shared_across_worlds(): void
    {
        $this->race();

        $terra = $this->get(route('races.index', ['world' => 'terra']))->assertOk();
        $talos = $this->get(route('races.index', ['world' => 'talos']))->assertOk();

        // 没有世界切换器，两个世界看到的是同一份名单
        $terra->assertDontSee('world-switch__item');
        $talos->assertSee('德拉克');

        // 而地名/组织/词条都有切换器 —— 否则上一条会因为「三个页面都忘了加切换器」而假通过
        $this->get(route('places.index'))->assertOk()->assertSee('world-switch__item');
        $this->get(route('organizations.index'))->assertOk()->assertSee('world-switch__item');
        $this->get(route('terms.index'))->assertOk()->assertSee('world-switch__item');
    }

    /**
     * 历史人物要能被找到。
     *
     * 它们被刻意排除在干员名单之外（名单服务的是「这支队伍里有谁」），
     * 但如果没有任何入口，那这批数据就等于不存在 —— 因此页面上必须有一个明确的切换。
     */
    public function test_historical_figures_are_reachable_from_the_people_page(): void
    {
        $figure = Character::create([
            'name' => '测试大帝',
            'slug' => 'chr-fixture-emperor',
            'kind' => 'historical',
            'title' => '测试帝国皇帝 ·「试帝」',
            'reign_start_index' => 969 * 372,
            'reign_end_index' => 1077 * 372,
            'description' => '用于测试的历史人物。',
            'sort_order' => 0,
        ]);

        // 默认视图是干员名单：历史人物不进去
        $this->get(route('operators.index'))->assertOk()->assertDontSee($figure->name);

        // 切到历史人物：能看到，并且带着头衔与在位期
        $this->get(route('operators.index', ['kind' => 'historical']))
            ->assertOk()
            ->assertSee($figure->name)
            ->assertSee('测试帝国皇帝')
            ->assertSee('在位');
    }

    /**
     * 人物分三档，各档都有入口。
     *
     * 「剧情人物」是导入 PRTS 名单时才补出来的一档：非干员的现代人（组织创办者一类）。
     * 少了它，他们只能挤在「历史人物」里 —— 名不副实，却无处可去。
     */
    public function test_every_character_kind_is_reachable(): void
    {
        Character::create(['name' => '测试干员', 'slug' => 'chr-fixture-op', 'kind' => 'operator', 'codename' => 'Test', 'sort_order' => 0]);
        Character::create(['name' => '测试先帝', 'slug' => 'chr-fixture-his', 'kind' => 'historical', 'title' => '测试皇帝', 'description' => '测试。', 'sort_order' => 1]);
        Character::create(['name' => '测试店主', 'slug' => 'chr-fixture-npc', 'kind' => 'npc', 'title' => '测试店主', 'description' => '测试。', 'sort_order' => 2]);

        foreach (['operator' => '测试干员', 'historical' => '测试先帝', 'npc' => '测试店主'] as $kind => $name) {
            // 切换器三档都在，读者才知道还有别的可看
            $this->get(route('operators.index', ['kind' => $kind]))
                ->assertOk()
                ->assertSee('干员')
                ->assertSee('历史人物')
                ->assertSee('剧情人物')
                ->assertSee($name);
        }

        // 各档互不串台
        $this->get(route('operators.index'))
            ->assertOk()
            ->assertDontSee('测试先帝')
            ->assertDontSee('测试店主');

        $this->get(route('operators.index', ['kind' => 'historical']))
            ->assertOk()
            ->assertDontSee('测试干员');
    }

    /**
     * 人物详情页的出身地：原文 + 可点的地名节点，与条目的发生地同一条规矩。
     *
     * 对不上时**不猜**：来源写「未公开」「瓦伊凡」这类值，页面上如实显示原文，
     * 并说明它没对上字典 —— 硬塞一个地名比空着更糟。
     */
    public function test_birth_place_is_shown_with_a_link_when_it_matches(): void
    {
        $place = $this->place('测试城');

        $linked = Character::create([
            'name' => '测试人物甲', 'slug' => 'chr-fixture-bp-1', 'sort_order' => 0,
            'birth_place' => '测试城', 'birth_place_id' => $place->id,
        ]);

        $this->get(route('operators.show', $linked))
            ->assertOk()
            ->assertSee('出身地')
            ->assertSee(route('places.index').'#place-'.$place->slug);

        $unlinked = Character::create([
            'name' => '测试人物乙', 'slug' => 'chr-fixture-bp-2', 'sort_order' => 1,
            'birth_place' => '未公开',
        ]);

        $this->get(route('operators.show', $unlinked))
            ->assertOk()
            ->assertSee('未公开')
            ->assertDontSee(route('places.index').'#place-');
    }

    /**
     * 多归属要**都**列出来，且每个都能点着筛。
     *
     * 只显示第一条等于把改造前的行为原样搬了过来 —— 多记的那一条若看不见也点不动，
     * 读者读不出它的用处。
     */
    public function test_every_faction_of_a_character_is_listed(): void
    {
        $hunters = $this->faction('深海猎人', 'society');
        $aegir = $this->faction('阿戈尔', 'polity');

        $character = Character::create([
            'name' => '测试猎手', 'slug' => 'chr-fixture-multi', 'sort_order' => 0,
        ]);

        $character->factions()->sync([
            $hunters->id => ['sort_order' => 0],
            $aegir->id => ['sort_order' => 2],
        ]);

        $html = $this->get(route('operators.show', $character))
            ->assertOk()
            ->assertSee('深海猎人')
            ->assertSee('阿戈尔')
            ->getContent();

        // 两个阵营各自都能点着筛
        $this->assertStringContainsString('faction='.$hunters->id, $html);
        $this->assertStringContainsString('faction='.$aegir->id, $html);

        // 顺序：更具体的那条在前
        $this->assertLessThan(
            mb_strpos($html, '阿戈尔'),
            mb_strpos($html, '深海猎人'),
            '更具体的归属应当排在前面',
        );
    }

    /** 干员卡上的种族要能点进种族页，否则读者看到「菲林」仍然无处可查。 */
    public function test_race_chip_links_to_the_race_page(): void
    {
        $race = $this->race('菲林', null);

        Character::create([
            'name' => '测试干员',
            'slug' => 'chr-fixture-operator',
            'kind' => 'operator',
            'race_id' => $race->id,
            'sort_order' => 0,
        ]);

        $this->get(route('operators.index'))
            ->assertOk()
            ->assertSee(route('races.index').'#race-'.$race->slug);
    }
}
