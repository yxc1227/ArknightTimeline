<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AiProposal;
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
            ->assertSee('泰拉时间线')
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

    public function test_guest_only_sees_read_only_navigation(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('只读浏览中')
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

    public function test_anonymous_visitors_can_submit_annotations(): void
    {
        $event = $this->rawEvent();

        $this->postJson(route('events.annotations.store', $event), [
            'type' => 'correction',
            'body' => '依据 1-1 关卡文本，该条目的纪年应为 1096 年。',
        ])->assertCreated();

        $this->assertDatabaseHas('annotations', ['event_id' => $event->id, 'user_id' => null]);
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
