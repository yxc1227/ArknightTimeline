<?php

namespace App\Models;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Enums\EventStatus;
use App\Enums\ProposalStatus;
use App\Support\TerraDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * AI 提案 —— AI 与时间线之间唯一的写入通道。
 *
 * 铁律：AI 任何输出都只能落在本表。events 表的写入必须由人工在审核面板上放行，
 * 由 ProposalApplier 以 origin=ai + approved_by=human 的身份落库并生成 revision。
 *
 * 这样设计的原因：
 *  - 幻觉在产品层面的表现是「时间线上多了一条看似合理的假事件」，比报错更危险；
 *  - 人工放行留下 responsible party，让每条 AI 内容都可追溯到具体审核人；
 *  - 提案表天然是「反馈数据集」，驳回理由可以反过来调优 prompt。
 */
#[Fillable([
    'batch_id', 'source_id', 'era_id', 'status',
    'title', 'summary', 'date_display', 'start_index', 'end_index',
    'date_precision', 'date_confidence', 'location',
    'characters', 'factions', 'tags', 'evidence', 'validation', 'confidence',
    'driver', 'model', 'prompt_hash', 'raw_payload',
    'duplicate_of_event_id', 'applied_event_id',
    'review_note', 'reviewed_by', 'reviewed_at',
])]
class AiProposal extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'characters' => 'array',
            'factions' => 'array',
            'tags' => 'array',
            'evidence' => 'array',
            'validation' => 'array',
            'raw_payload' => 'array',
            'confidence' => 'integer',
            'start_index' => 'integer',
            'end_index' => 'integer',
            'date_precision' => DatePrecision::class,
            'date_confidence' => DateConfidence::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function era(): BelongsTo
    {
        return $this->belongsTo(Era::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'duplicate_of_event_id');
    }

    public function appliedEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'applied_event_id');
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(TimelineAnomaly::class);
    }

    // ---------------------------------------------------------------- 校验语义

    /**
     * 第 3 层校验结论：描述是否有可定位的原文支撑。
     * 只要有一条 evidence 的 matched=true，就视为有出处。
     */
    public function hasGroundedEvidence(): bool
    {
        return collect($this->evidence ?? [])->contains(fn (array $e) => ($e['matched'] ?? false) === true);
    }

    /** 时间是否可解析（第 2 层校验）。未解析出来的提案禁止入库。 */
    public function hasResolvableDate(): bool
    {
        return $this->date_precision !== DatePrecision::Unknown
            && $this->start_index !== null
            && $this->start_index !== TerraDate::UNKNOWN_INDEX;
    }

    /**
     * 硬问题：无法通过补正字段消除，只能由审核人**显式免责**放行。
     *
     * 目前只有一条 —— 缺少可定位的原文引证。这条刻意做成不可被 overrides 绕过：
     * 否则审核人「顺手改一个字段」就能把一条毫无出处支撑的幻觉内容写进时间线，
     * 那第 3 层校验就形同虚设。
     */
    public function hardIssues(): array
    {
        if ($this->hasGroundedEvidence()) {
            return [];
        }

        return ['描述缺少可定位的原文引用，无法核实出处（确需入库请显式确认「无出处支撑」）'];
    }

    /** 软问题：可以通过补正字段（overrides）消除。 */
    public function softIssues(): array
    {
        $issues = [];

        if (! $this->hasResolvableDate()) {
            $issues[] = '时间无法解析为泰拉历区间，需人工指定具体时间';
        }

        if (collect($this->validation['anomalies'] ?? [])->contains(fn (array $a) => ($a['blocking'] ?? false) === true)) {
            $issues[] = '触发时间线一致性阻断（因果倒置 / 锚点失效等）';
        }

        return $issues;
    }

    /** 全部阻断级问题（硬 + 软），用于审核面板展示。 */
    public function blockingIssues(): array
    {
        return [...$this->hardIssues(), ...$this->softIssues()];
    }

    public function isApprovable(bool $acknowledgeMissingEvidence = false): bool
    {
        if (! $this->status->canBeApproved()) {
            return false;
        }

        if ($this->hardIssues() !== [] && ! $acknowledgeMissingEvidence) {
            return false;
        }

        // 软问题允许由审核人通过 overrides 现场补正，因此这里只提示、不硬性拦截
        return true;
    }

    /** 审核面板置信度分档。 */
    public function confidenceTier(): string
    {
        return match (true) {
            $this->confidence >= 80 => 'high',
            $this->confidence >= 55 => 'medium',
            default => 'low',
        };
    }

    /** 提案 → 建库候选（不落库，仅供审核面板预览与比对）。 */
    public function toEventAttributes(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'date_display' => $this->date_display,
            'start_index' => $this->start_index ?? TerraDate::UNKNOWN_INDEX,
            'end_index' => $this->end_index ?? $this->start_index ?? TerraDate::UNKNOWN_INDEX,
            'date_precision' => $this->date_precision->value,
            'date_confidence' => $this->date_confidence->value,
            'era_id' => $this->era_id,
            'location' => $this->location,
            'status' => EventStatus::NeedsReview->value,
        ];
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'title' => $this->title,
            'summary' => $this->summary,
            'date' => [
                'display' => $this->date_display,
                'precision' => $this->date_precision->value,
                'precision_label' => $this->date_precision->label(),
                'confidence' => $this->date_confidence->value,
                'confidence_label' => $this->date_confidence->label(),
                'start_index' => $this->start_index,
                'hint' => $this->start_index ? TerraDate::describeIndex($this->start_index) : '无法定位',
            ],
            'location' => $this->location,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_badge' => $this->status->badgeClass(),
            'confidence' => $this->confidence,
            'confidence_tier' => $this->confidenceTier(),
            'characters' => $this->characters ?? [],
            'factions' => $this->factions ?? [],
            'tags' => $this->tags ?? [],
            'evidence' => $this->evidence ?? [],
            'validation' => $this->validation ?? [],
            'blocking_issues' => $this->blockingIssues(),
            'approvable' => $this->isApprovable(),
            'source' => $this->relationLoaded('source') && $this->source ? $this->source->toApiArray() : null,
            'era' => $this->relationLoaded('era') && $this->era ? $this->era->toApiArray() : null,
            'duplicate_of' => $this->relationLoaded('duplicateOf') && $this->duplicateOf
                ? ['id' => $this->duplicateOf->id, 'title' => $this->duplicateOf->title]
                : null,
            'applied_event_id' => $this->applied_event_id,
            'driver' => $this->driver,
            'model' => $this->model,
            'review_note' => $this->review_note,
            'reviewed_by' => $this->relationLoaded('reviewer') ? $this->reviewer?->displayLabel() : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** 用于生成去重指纹：标题归一化 + 时间区间。 */
    public function dedupeFingerprint(): string
    {
        return Str::slug(Str::lower(preg_replace('/\s+/u', '', $this->title) ?? ''))
            .'|'.($this->start_index ?? 'x')
            .'|'.($this->end_index ?? 'x');
    }
}
