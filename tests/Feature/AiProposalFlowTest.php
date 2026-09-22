<?php

namespace Tests\Feature;

use App\Enums\ProposalStatus;
use App\Enums\UserRole;
use App\Exceptions\WriteDeniedException;
use App\Models\AiProposal;
use App\Models\Event;
use App\Models\EventRevision;
use App\Models\Source;
use App\Services\Ai\AiEventSynthesizer;
use App\Services\ProposalApplier;
use App\Support\TerraDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * AI 补全的完整链路：触发 → 四层校验 → 人工放行 → 入库（带 origin=ai 的版本记录）。
 *
 * 这里最重要的断言不是「能不能跑通」，而是**拦不拦得住**：
 * 缺出处的提案必须无法通过 overrides 悄悄入库。
 */
class AiProposalFlowTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    private const CORPUS = <<<'TXT'
    泰拉纪年表（节选）

    泰拉历1096年12月23日，切尔诺伯格事变爆发，整合运动占领切尔诺伯格城区。
    泰拉历1097年1月，罗德岛抵达龙门，龙门危机爆发。
    泰拉历1099年12月，沃伦姆德城因感染者与市民的对立陷入失控。
    TXT;

    private function synthesize(string $rawText = self::CORPUS, ?Source $source = null): array
    {
        $source ??= $this->source('测试年表', 'test-chronicle', $rawText);
        $editor = $this->user(UserRole::Reviewer);

        return app(AiEventSynthesizer::class)->synthesize(
            source: $source,
            rawText: $rawText,
            actor: $editor,
        );
    }

    public function test_synthesis_produces_pending_proposals_never_events(): void
    {
        $result = $this->synthesize();

        $this->assertGreaterThanOrEqual(3, $result['stats']['extracted']);
        $this->assertSame('heuristic', $result['driver']);
        $this->assertGreaterThan(0, $result['proposals']->count());

        // 铁律：AI 产出只落在提案表，时间线保持为空
        $this->assertSame(0, Event::count());
        $this->assertSame($result['proposals']->count(), AiProposal::count());
    }

    public function test_every_proposal_records_the_four_layer_validation(): void
    {
        $result = $this->synthesize();

        foreach ($result['proposals'] as $proposal) {
            $validation = $proposal->validation;

            $this->assertArrayHasKey('schema', $validation);
            $this->assertArrayHasKey('date_parse', $validation);
            $this->assertArrayHasKey('grounding', $validation);
            $this->assertArrayHasKey('anomalies', $validation);

            // L3：出自本段原文的提案，引证必须能定位
            $this->assertTrue($validation['grounding']['passed'], '引证应可在原文中定位');
            $this->assertGreaterThan(0, $validation['grounding']['matched']);
        }
    }

    public function test_proposal_dates_are_parsed_into_the_terra_grid(): void
    {
        $this->synthesize();

        $proposal = AiProposal::where('date_display', 'like', '%1096年12月23日%')->firstOrFail();

        $this->assertSame('day', $proposal->date_precision->value);
        $this->assertSame(TerraDate::toIndex(1096, 12, 23), $proposal->start_index);
        $this->assertTrue($proposal->hasResolvableDate());
    }

    public function test_ungrounded_proposal_cannot_be_approved_even_with_overrides(): void
    {
        $proposal = $this->ungroundedProposal();
        $reviewer = $this->user(UserRole::Reviewer, 'reviewer-gate@example.test');

        $this->assertNotSame([], $proposal->hardIssues());
        $this->assertFalse($proposal->isApprovable());

        // 关键断言：改字段不能绕过出处闸门
        try {
            app(ProposalApplier::class)->approve($proposal, $reviewer, [
                'title' => '被人为修正过的标题',
                'summary' => '被人为修正过的描述。',
            ]);
            $this->fail('缺少出处的提案不应被 overrides 绕过');
        } catch (WriteDeniedException $e) {
            $this->assertSame('proposal_unverified', $e->code_);
        }

        $this->assertSame(ProposalStatus::Unverified, $proposal->fresh()->status);
    }

    public function test_ungrounded_proposal_can_only_pass_with_explicit_acknowledgement(): void
    {
        $proposal = $this->ungroundedProposal();
        $reviewer = $this->user(UserRole::Reviewer, 'reviewer-ack@example.test');

        $event = app(ProposalApplier::class)->approve(
            proposal: $proposal,
            reviewer: $reviewer,
            overrides: [],
            acknowledgeMissingEvidence: true,
        );

        $this->assertSame('被人为构造的无出处事件', $event->title);
        // AI 内容入库后默认仍处于「待校验」，需人工再点一次才成为已校验
        $this->assertSame('needs_review', $event->status->value);
        $this->assertStringContainsString('无原文出处支撑', $proposal->fresh()->review_note);
    }

    public function test_approving_records_ai_origin_and_the_responsible_reviewer(): void
    {
        $this->synthesize();

        $proposal = AiProposal::where('title', 'like', '%切尔诺伯格事变爆发%')->firstOrFail();
        $reviewer = $this->user(UserRole::Reviewer, 'reviewer-apply@example.test');

        $event = app(ProposalApplier::class)->approve($proposal, $reviewer);

        $this->assertSame('needs_review', $event->status->value);
        $this->assertSame(1, $event->version);
        $this->assertSame($proposal->source_id, $event->sources()->first()?->id);

        $revision = EventRevision::where('event_id', $event->id)->firstOrFail();
        $this->assertSame('ai', $revision->origin->value);
        // 「谁为这条 AI 内容负责」必须可追溯
        $this->assertSame($reviewer->id, $revision->user_id);
        $this->assertSame($proposal->id, $revision->ai_proposal_id);

        $proposal->refresh();
        $this->assertSame(ProposalStatus::Applied, $proposal->status);
        $this->assertSame($event->id, $proposal->applied_event_id);
    }

    public function test_editor_cannot_approve_proposals(): void
    {
        $this->synthesize();
        $proposal = AiProposal::firstOrFail();
        $editor = $this->user(UserRole::Editor, 'editor-approve@example.test');

        $this->actingAs($editor)
            ->postJson(route('proposals.approve', $proposal))
            ->assertForbidden();
    }

    public function test_reviewer_can_approve_over_http(): void
    {
        $this->synthesize();
        $proposal = AiProposal::where('title', 'like', '%龙门%')->firstOrFail();
        $reviewer = $this->user(UserRole::Reviewer, 'reviewer-http@example.test');

        $this->actingAs($reviewer)
            ->postJson(route('proposals.approve', $proposal))
            ->assertOk()
            ->assertJsonPath('event.status.value', 'needs_review');

        $this->assertSame(1, Event::count());
    }

    public function test_rejection_requires_a_reason_and_is_kept_as_feedback(): void
    {
        $this->synthesize();
        $proposal = AiProposal::firstOrFail();
        $reviewer = $this->user(UserRole::Reviewer, 'reviewer-reject@example.test');

        // 没有理由不给驳回
        $this->actingAs($reviewer)
            ->postJson(route('proposals.reject', $proposal), [])
            ->assertStatus(422);

        $this->actingAs($reviewer)
            ->postJson(route('proposals.reject', $proposal), ['note' => '时间粒度太粗，需先核对关卡文本'])
            ->assertOk();

        $proposal->refresh();
        $this->assertSame(ProposalStatus::Rejected, $proposal->status);
        $this->assertSame('时间粒度太粗，需先核对关卡文本', $proposal->review_note);
    }

    public function test_applied_proposal_cannot_be_applied_twice(): void
    {
        $this->synthesize();
        $proposal = AiProposal::firstOrFail();
        $reviewer = $this->user(UserRole::Reviewer, 'reviewer-twice@example.test');

        app(ProposalApplier::class)->approve($proposal, $reviewer);

        $this->expectException(WriteDeniedException::class);
        app(ProposalApplier::class)->approve($proposal->fresh(), $reviewer);
    }

    public function test_synthesis_requires_edit_permission(): void
    {
        $viewer = $this->user(UserRole::Viewer);
        $source = $this->source('语料', 'viewer-corpus', self::CORPUS);

        $this->actingAs($viewer)
            ->postJson(route('ai.synthesize'), ['source_id' => $source->id])
            ->assertForbidden();
    }

    public function test_synthesis_without_text_is_rejected(): void
    {
        $editor = $this->user(UserRole::Editor, 'editor-empty@example.test');

        $this->actingAs($editor)
            ->postJson(route('ai.synthesize'), ['raw_text' => ''])
            ->assertStatus(422);
    }

    public function test_duplicate_detection_marks_proposals_that_already_exist(): void
    {
        // 先把年表里的内容建成正式条目
        $this->rawEvent([
            'title' => '切尔诺伯格事变爆发',
            'start_index' => TerraDate::toIndex(1096, 12, 23),
            'end_index' => TerraDate::toIndex(1096, 12, 23),
        ]);

        $this->synthesize();

        $proposal = AiProposal::where('title', 'like', '%切尔诺伯格事变爆发%')->firstOrFail();

        $this->assertSame(ProposalStatus::Duplicate, $proposal->status);
        $this->assertNotNull($proposal->duplicate_of_event_id);
    }

    /** 构造一条「引证不在原文里」的提案，用于验证硬闸门。 */
    private function ungroundedProposal(): AiProposal
    {
        $source = $this->source('无出处语料', 'ungrounded-source', '泰拉历1100年，某段与提案无关的原文。');

        return AiProposal::create([
            'source_id' => $source->id,
            'status' => ProposalStatus::Unverified->value,
            'title' => '被人为构造的无出处事件',
            'summary' => '这段描述在原文中找不到任何支撑。',
            'date_display' => '泰拉历1100年',
            'start_index' => TerraDate::toIndex(1100, 6, 1),
            'end_index' => TerraDate::toIndex(1100, 6, 1),
            'date_precision' => 'day',
            'date_confidence' => 'inferred',
            'evidence' => [['quote' => '这句话从来不存在于原始文本中', 'offset' => null, 'matched' => false]],
            'validation' => ['grounding' => ['passed' => false, 'matched' => 0, 'total' => 1], 'anomalies' => []],
            'confidence' => 20,
        ]);
    }
}
