<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AiProposal;
use App\Models\Tag;
use App\Models\TimelineAnomaly;
use App\Services\Ai\AiEventSynthesizer;
use App\Services\TimelineConsistencyChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 页面级冒烟测试。
 *
 * 这类测试的价值不在覆盖率，而在于保证「迁移刚跑完、一条数据都没有」时页面依然可用 ——
 * 协作类系统最常见的线上事故就是空数据下某个统计查询炸掉整页。
 */
class TimelinePageTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    public function test_homepage_renders_with_an_empty_database(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('明日方舟时间线')
            ->assertSee('timeline-host', false);
    }

    public function test_homepage_renders_with_seeded_events(): void
    {
        $era = $this->era('切尔诺伯格事变与龙门危机', 1096, 1097);
        $this->rawEvent(['title' => '切尔诺伯格事变爆发', 'era_id' => $era->id]);

        $this->get('/')->assertOk()->assertSee($era->name);
    }

    public function test_timeline_feed_works_without_authentication(): void
    {
        $this->getJson(route('timeline.feed'))->assertOk()->assertJsonPath('meta.total', 0);
    }

    /**
     * 筛选栏是「头部 + 滚动区 + 底部」三段式，而不是整栏滚动。
     *
     * 这条结构是有意锁住的：整栏滚动时「重置」与「新增条目」会随内容滚走，
     * 而且面板的四角刻度（绝对定位）会跟着内容漂移。
     */
    public function test_filter_sidebar_uses_a_three_part_layout(): void
    {
        $editor = $this->user(UserRole::Editor, 'editor-sidebar@example.test');

        $this->actingAs($editor)->get('/')
            ->assertOk()
            ->assertSee('sidebar__head', false)
            ->assertSee('sidebar__scroll', false)
            ->assertSee('sidebar__foot', false)
            ->assertSee('新增事件条目');
    }

    public function test_guest_sidebar_has_no_footer_section(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('sidebar__head', false)
            ->assertSee('sidebar__scroll', false)
            // 访客没有新增入口，因此底部区不渲染
            ->assertDontSee('sidebar__foot', false);
    }

    /**
     * 标签筛选不再自带滚动条：滚动容器里再套一个滚动容器，
     * 滚轮停在标签上时外层的筛选组就滚不动了。
     */
    public function test_tag_picker_does_not_create_a_nested_scroll_area(): void
    {
        Tag::create(['name' => '战役', 'slug' => 'battle']);

        $this->get('/')->assertOk()->assertSee('class="tag-picker"', false);
    }

    public function test_guest_only_sees_read_only_navigation(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('READ ONLY')
            ->assertDontSee('AI 审核台');
    }

    public function test_editor_sees_authoring_navigation_and_counts(): void
    {
        $editor = $this->user(UserRole::Editor);

        $proposal = AiProposal::create([
            'status' => 'pending',
            'title' => '待审提案',
            'summary' => '摘要',
            'date_display' => '泰拉历1097年',
            'date_precision' => 'year',
            'date_confidence' => 'inferred',
        ]);

        $this->actingAs($editor)->get('/')
            ->assertOk()
            ->assertSee('AI 审核台')
            ->assertSee('一致性收件箱')
            ->assertSee('新增事件条目')
            ->assertSee('1'); // 待审提案角标

        $this->assertNotNull($proposal->id);
    }

    public function test_proposals_page_requires_authentication(): void
    {
        $this->get(route('proposals.index'))->assertRedirect(route('login'));
    }

    public function test_proposals_page_renders_for_editor(): void
    {
        $editor = $this->user(UserRole::Editor, 'editor-page@example.test');

        $this->actingAs($editor)->get(route('proposals.index'))
            ->assertOk()
            ->assertSee('AI 梳理')
            ->assertSee('产出仅为提案，需人工放行');
    }

    public function test_proposals_page_hides_review_actions_from_editor(): void
    {
        $this->synthesizeOneProposal();
        $editor = $this->user(UserRole::Editor, 'editor-nobutton@example.test');

        $this->actingAs($editor)->get(route('proposals.index'))
            ->assertOk()
            ->assertDontSee('采纳为新建条目');
    }

    public function test_proposals_page_shows_review_actions_to_reviewer(): void
    {
        $this->synthesizeOneProposal();
        $reviewer = $this->user(UserRole::Reviewer, 'reviewer-page@example.test');

        $this->actingAs($reviewer)->get(route('proposals.index'))
            ->assertOk()
            ->assertSee('采纳为新建条目');
    }

    public function test_anomalies_page_renders_and_lists_open_issues(): void
    {
        $era = $this->era('未来纪', 1200, 1210);
        $event = $this->rawEvent([
            'title' => '时代错位条目',
            'era_id' => $era->id,
        ]);

        app(TimelineConsistencyChecker::class)->checkEvent($event);

        $reviewer = $this->user(UserRole::Reviewer, 'reviewer-inbox@example.test');

        $this->actingAs($reviewer)->get(route('anomalies.index'))
            ->assertOk()
            ->assertSee('时代错位')
            ->assertSee('时代错位条目');

        $this->assertSame(1, TimelineAnomaly::where('status', 'open')->count());
    }

    public function test_sources_page_lists_corpus_status(): void
    {
        $this->source('已录入原文的出处', 'with-text', '泰拉历1097年，某事件。');
        $this->source('缺原文的出处', 'without-text');

        $editor = $this->user(UserRole::Editor, 'editor-sources@example.test');

        $this->actingAs($editor)->get(route('sources.index'))
            ->assertOk()
            ->assertSee('已录入原文的出处')
            ->assertSee('缺原文的出处')
            ->assertSee('已录入');
    }

    public function test_source_detail_page_exposes_the_synthesize_entry(): void
    {
        $source = $this->source('主线 · 序章', 'prologue', '泰拉历1096年12月23日，切尔诺伯格事变爆发。');
        $editor = $this->user(UserRole::Editor, 'editor-source-detail@example.test');

        $this->actingAs($editor)->get(route('sources.show', $source))
            ->assertOk()
            ->assertSee('开始梳理')
            ->assertSee('切尔诺伯格事变爆发');
    }

    /**
     * 出处详情对访客开放：时间线的可信度取决于出处，
     * 因此读者必须能顺着引文一路点到原文，而不是被登录墙拦在出处列表页。
     */
    public function test_source_detail_page_is_readable_without_authentication(): void
    {
        $source = $this->source('公开出处', 'public-source', '泰拉历1097年，某段可公开查阅的原文。');

        $this->get(route('sources.show', $source))
            ->assertOk()
            ->assertSee('公开出处')
            ->assertSee('泰拉历1097年，某段可公开查阅的原文。')
            // 只读：访客看不到编辑与梳理入口
            ->assertDontSee('开始梳理');
    }

    public function test_event_detail_endpoint_exposes_edit_permissions(): void
    {
        $event = $this->rawEvent();

        // 访客：可读不可写
        $this->getJson(route('events.show', $event))
            ->assertOk()
            ->assertJsonPath('permissions.update', false)
            ->assertJsonPath('permissions.annotate', true);

        // 编辑者：可写
        $editor = $this->user(UserRole::Editor, 'editor-detail@example.test');

        $this->actingAs($editor)->getJson(route('events.show', $event))
            ->assertOk()
            ->assertJsonPath('permissions.update', true)
            ->assertJsonPath('permissions.review', false);
    }

    /**
     * 直接访问 /events/{id}（例如从人物页或外部链接点进来）必须渲染 HTML 页面，
     * 而不是把 JSON 原样丢给浏览器。抽屉里通过 fetch 拉的就是 JSON，靠 Accept 头区分。
     */
    public function test_event_detail_renders_an_html_page_for_browser_navigation(): void
    {
        $event = $this->rawEvent(['title' => '塔卫二侧的相关条目', 'summary' => '穿过星门之后的第二家园。']);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('塔卫二侧的相关条目')
            ->assertSee('穿过星门之后的第二家园。')
            ->assertSee('返回时间线')
            // 不能把 JSON 的载荷结构直接吐在页面上
            ->assertDontSee('"permissions"')
            ->assertDontSee('"toApiArray"');
    }

    public function test_anonymous_visitors_can_submit_annotations(): void
    {
        $event = $this->rawEvent();

        $this->postJson(route('events.annotations.store', $event), [
            'type' => 'correction',
            'body' => '依据 1-1 关卡文本，该条目的纪年应为 1096 年。',
        ])->assertCreated();

        $this->assertDatabaseHas('annotations', ['event_id' => $event->id, 'user_id' => null]);
    }

    /**
     * 纪元下拉按「时代」分组，且**父级本身不可选**。
     *
     * 父级是分期标签而非条目的桶：列成一个可选项，读者选中后只会得到一个空列表。
     * 因此它必须以 `<optgroup>` 的形式出现（只是标题），而不是一个 `<option>`。
     */
    public function test_era_filter_groups_leaves_under_their_period(): void
    {
        $period = \App\Models\Era::create([
            'name' => '测试时代',
            'slug' => 'era-fixture-period',
            'date_label' => '泰拉历 900 — 1200 年',
            'start_index' => 900 * 372,
            'end_index' => 1200 * 372,
            'color' => '#808080',
            'sort_order' => 0,
        ]);

        $child = $this->era('测试分期');
        $child->update(['parent_id' => $period->id]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<optgroup label="测试时代">', $html);
        $this->assertStringContainsString('value="'.$child->id.'"', $html);
        // 父级只作标题出现，不得成为可选项
        $this->assertStringNotContainsString('value="'.$period->id.'"', $html);
    }

    /** 资料集里的「N 条」把 place_id 带进来，下拉必须预选中，否则读者一碰筛选就把条件清掉了。 */
    public function test_place_filter_is_preselected_from_the_query_string(): void
    {
        $place = \App\Models\Place::create([
            'name' => '测试都城',
            'slug' => 'place-fixture-city',
            'kind' => 'city',
            'world' => 'terra',
        ]);

        $event = $this->rawEvent(['place_id' => $place->id]);
        $this->assertSame($place->id, $event->place_id);

        $this->get('/?place_id='.$place->id)
            ->assertOk()
            ->assertSee('data-filter="place_id"', false)
            ->assertSee('value="'.$place->id.'"', false)
            ->assertSee('selected', false);
    }

    /** 通过信息源语料生成一条真实提案，供页面渲染用。 */
    private function synthesizeOneProposal(): void
    {
        $source = $this->source('测试年表', 'page-chronicle', '泰拉历1097年1月，罗德岛抵达龙门，龙门危机爆发。');
        $admin = $this->user(UserRole::Admin, 'admin-page@example.test');

        app(AiEventSynthesizer::class)->synthesize(
            source: $source,
            rawText: $source->raw_text,
            actor: $admin,
        );
    }
}
