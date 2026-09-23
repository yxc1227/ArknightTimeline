<?php

namespace App\Services;

use App\Enums\ChangeOrigin;
use App\Enums\EventStatus;
use App\Enums\ProposalStatus;
use App\Exceptions\WriteDeniedException;
use App\Models\AiProposal;
use App\Models\Annotation;
use App\Models\Event;
use App\Models\TimelineAnomaly;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 把已批准的 AI 提案写进时间线 —— AI 内容跨越「审核关卡」的唯一通道。
 *
 * 三条不可绕过的约束：
 *  1. 调用者必须是 reviewer（含）以上；
 *  2. 提案必须通过阻断级校验，或被审核人显式补齐（overrides）；
 *  3. 写库时 revision 的 origin 记 ai、user_id 记审核人 ——
 *     这样「谁为这条 AI 内容负责」永远有答案，也便于日后统计各审核人的采纳质量。
 */
final class ProposalApplier
{
    public function __construct(private readonly EventWriter $writer)
    {
    }

    /**
     * 采纳提案，新建时间线条目。
     *
     * 两级闸门：
     *  - **硬问题**（缺可定位出处）只能靠 `$acknowledgeMissingEvidence` 显式放行，
     *    不接受用 overrides 悄悄绕过；
     *  - **软问题**（时间不可解析、一致性阻断）允许审核人补正字段后放行，
     *    但「什么都不改直接放行」依然被拒。
     *
     * @param  array<string, mixed>  $overrides  审核人在面板上修正后的字段（可覆盖 AI 的原始结论）
     */
    public function approve(
        AiProposal $proposal,
        User $reviewer,
        array $overrides = [],
        bool $acknowledgeMissingEvidence = false,
    ): Event {
        $this->assertCanReview($proposal, $reviewer);

        $hard = $proposal->hardIssues();

        if ($hard !== [] && ! $acknowledgeMissingEvidence) {
            throw new WriteDeniedException(
                '提案未通过出处校验，需显式确认「无出处支撑」后才能采纳：'.implode('；', $hard),
                'proposal_unverified',
            );
        }

        $soft = $proposal->softIssues();

        if ($soft !== [] && $overrides === []) {
            throw new WriteDeniedException(
                '提案存在需修正的问题，请先补正字段再采纳：'.implode('；', $soft),
                'proposal_blocked',
            );
        }

        $event = DB::transaction(function () use ($proposal, $reviewer, $overrides, $acknowledgeMissingEvidence) {
            $payload = [
                ...$this->basePayload($proposal),
                ...$overrides,
            ];

            $event = $this->writer->create(
                data: $payload,
                actor: $reviewer,
                origin: ChangeOrigin::Ai,
                proposal: $proposal,
                ip: request()?->ip(),
            );

            $note = "\n采纳为条目 #{$event->id}。";

            if ($acknowledgeMissingEvidence && $proposal->hardIssues() !== []) {
                // 留下免责痕迹：「谁在明知无出处的情况下放行了这条内容」必须可追溯
                $note .= "\n⚠️ 审核人 {$reviewer->displayLabel()} 已确认该提案**无原文出处支撑**，理由见审核备注。";
            }

            $proposal->forceFill([
                'status' => ProposalStatus::Applied->value,
                'applied_event_id' => $event->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => trim(($proposal->review_note ?? '').$note),
            ])->save();

            // 把该条目的一致性异常溯源回提案，方便日后复盘「哪批 AI 产出更容易出问题」
            TimelineAnomaly::where('event_id', $event->id)
                ->whereNull('ai_proposal_id')
                ->update(['ai_proposal_id' => $proposal->id]);

            return $event;
        });

        return $event;
    }

    /**
     * 把提案合并进一条既有条目（疑似重复的处置方式）。
     *
     * 合并不是简单丢弃：提案里的出处引文与关联人物会**追加**到目标条目上，
     * 并留下一条 annotation 说明来源，做到「信息不丢、来源可查」。
     */
    public function mergeInto(AiProposal $proposal, Event $target, User $reviewer): Event
    {
        $this->assertCanReview($proposal, $reviewer);

        return DB::transaction(function () use ($proposal, $target, $reviewer) {
            $target = $target->fresh(['sources', 'characters', 'factions', 'tags']);

            $sources = $target->sources->map(fn ($s) => [
                'id' => $s->id,
                'chapter' => $s->pivot->chapter,
                'stage_code' => $s->pivot->stage_code,
                'quote' => $s->pivot->quote,
                'quote_offset' => $s->pivot->quote_offset,
                'is_primary' => (bool) $s->pivot->is_primary,
            ])->all();

            if ($proposal->source_id) {
                $sources[] = [
                    'id' => $proposal->source_id,
                    'quote' => $proposal->evidence[0]['quote'] ?? null,
                    'quote_offset' => $proposal->evidence[0]['offset'] ?? null,
                    'is_primary' => false,
                ];
            }

            $merge = fn (array $existing, array $incoming) => collect($existing)
                ->merge($incoming)
                ->unique('id')
                ->values()
                ->all();

            $event = $this->writer->update(
                event: $target,
                data: [
                    'sources' => $sources,
                    'characters' => $merge(
                        $target->characters->map(fn ($c) => ['id' => $c->id, 'role' => $c->pivot->role])->all(),
                        collect($proposal->characters ?? [])->map(fn ($c) => ['name' => $c['name'], 'role' => $c['role'] ?? 'support'])->all(),
                    ),
                    'factions' => $merge(
                        $target->factions->map(fn ($f) => ['id' => $f->id, 'role' => $f->pivot->role])->all(),
                        collect($proposal->factions ?? [])->map(fn ($f) => ['name' => $f['name'], 'role' => $f['role'] ?? 'involved'])->all(),
                    ),
                ],
                expectedVersion: $target->version,
                actor: $reviewer,
                origin: ChangeOrigin::Ai,
                ip: request()?->ip(),
            );

            Annotation::create([
                'event_id' => $event->id,
                'user_id' => $reviewer->id,
                'type' => 'verification',
                'body' => sprintf(
                    'AI 提案 #%d「%s」（%s）经审核判定为重复，已将其出处引文与关联信息合并到本条目。',
                    $proposal->id,
                    $proposal->title,
                    $proposal->date_display,
                ),
                'status' => 'resolved',
                'resolved_by' => $reviewer->id,
                'resolved_at' => now(),
            ]);

            $proposal->forceFill([
                'status' => ProposalStatus::Applied->value,
                'applied_event_id' => $event->id,
                'duplicate_of_event_id' => $event->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => trim(($proposal->review_note ?? '')."\n判定为重复，已合并进条目 #{$event->id}。"),
            ])->save();

            return $event;
        });
    }

    public function reject(AiProposal $proposal, User $reviewer, string $note): AiProposal
    {
        $this->assertCanReview($proposal, $reviewer);

        $proposal->forceFill([
            'status' => ProposalStatus::Rejected->value,
            'review_note' => $note,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        return $proposal;
    }

    /**
     * 批量采纳。逐条独立事务，一条失败不影响其余 —— 批量操作里「全有或全无」
     * 会让审核人被迫重做大量已确认的工作。
     *
     * @param  array<int, int>  $ids
     * @return array{applied: array<int, int>, failed: array<int, array{id:int, reason:string}>}
     */
    public function bulkApprove(array $ids, User $reviewer): array
    {
        $applied = [];
        $failed = [];

        foreach ($ids as $id) {
            $proposal = AiProposal::find($id);

            if (! $proposal) {
                $failed[] = ['id' => $id, 'reason' => '提案不存在'];

                continue;
            }

            try {
                $applied[] = $this->approve($proposal, $reviewer)->id;
            } catch (WriteDeniedException $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return ['applied' => $applied, 'failed' => $failed];
    }

    /** 把提案内容转成 EventWriter 可接受的载荷。 */
    private function basePayload(AiProposal $proposal): array
    {
        $primaryQuote = collect($proposal->evidence ?? [])->firstWhere('matched', true)
            ?? ($proposal->evidence[0] ?? null);

        return [
            'title' => $proposal->title,
            'summary' => $proposal->summary,
            'date_display' => $proposal->date_display,
            'start_index' => $proposal->start_index,
            'end_index' => $proposal->end_index,
            'date_precision' => $proposal->date_precision->value,
            'date_confidence' => $proposal->date_confidence->value,
            'era_id' => $proposal->era_id,
            'location' => $proposal->location,
            // AI 产出默认落在「待校验」，必须由人再点一次「标记已校验」才算数
            'status' => EventStatus::NeedsReview->value,
            'sources' => $proposal->source_id ? [[
                'id' => $proposal->source_id,
                'quote' => $primaryQuote['quote'] ?? null,
                'quote_offset' => $primaryQuote['offset'] ?? null,
                'is_primary' => true,
            ]] : [],
            'characters' => collect($proposal->characters ?? [])
                ->map(fn ($c) => ['name' => $c['name'], 'role' => $c['role'] ?? 'support'])
                ->all(),
            'factions' => collect($proposal->factions ?? [])
                ->map(fn ($f) => ['name' => $f['name'], 'role' => $f['role'] ?? 'involved'])
                ->all(),
            'tags' => collect($proposal->tags ?? [])
                ->map(fn ($t) => is_array($t) ? $t : ['name' => (string) $t])
                ->all(),
        ];
    }

    private function assertCanReview(AiProposal $proposal, User $reviewer): void
    {
        if (! $reviewer->canReview()) {
            throw new WriteDeniedException('审核 AI 提案需要审核员（含）以上权限。', 'proposal_review_denied');
        }

        if ($proposal->status === ProposalStatus::Applied) {
            throw new WriteDeniedException('该提案已经入库，不能重复采纳。', 'proposal_already_applied');
        }
    }
}
