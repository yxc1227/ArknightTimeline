<?php

namespace App\Models;

use App\Enums\AnomalySeverity;
use App\Enums\AnomalyType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 时间线一致性异常。
 *
 * 一致性不是靠「编辑时加锁」保证的，而是靠「写入后立刻体检 + 异常收件箱」保证的。
 * 原因：多人协作出错的形态大多是语义冲突（A 把事件定在 1097 年，B 把它的起因定在 1099 年），
 * 这类冲突无法在写入瞬间判定，只能通过持续的规则巡检收敛。
 *
 * fingerprint 唯一索引保证同一问题不会被重复告警淹没。
 */
#[Fillable([
    'type', 'severity', 'event_id', 'related_event_id', 'ai_proposal_id',
    'fingerprint', 'message', 'context', 'status', 'resolved_by', 'resolved_at',
])]
class TimelineAnomaly extends Model
{
    protected function casts(): array
    {
        return [
            'type' => AnomalyType::class,
            'severity' => AnomalySeverity::class,
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    public function relatedEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'related_event_id')->withTrashed();
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AiProposal::class, 'ai_proposal_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public static function fingerprintFor(string $type, int $eventId, ?int $relatedId = null, ?int $proposalId = null): string
    {
        return implode(':', [$type, $eventId, $relatedId ?? 0, $proposalId ?? 0]);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'severity_badge' => $this->severity->badgeClass(),
            'blocking' => $this->type->isBlocking(),
            'message' => $this->message,
            'context' => $this->context ?? [],
            'status' => $this->status,
            'event' => $this->relationLoaded('event') && $this->event
                ? ['id' => $this->event->id, 'title' => $this->event->title, 'date_display' => $this->event->date_display]
                : null,
            'related_event' => $this->relationLoaded('relatedEvent') && $this->relatedEvent
                ? ['id' => $this->relatedEvent->id, 'title' => $this->relatedEvent->title]
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
