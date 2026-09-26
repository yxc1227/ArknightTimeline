<?php

namespace App\Models;

use App\Enums\ChangeOrigin;
use App\Enums\RevisionAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 事件版本快照。
 *
 * 三重用途：
 *  1. 审计 —— 谁在什么时候改了什么（changed_fields + origin）；
 *  2. 冲突合并 —— base_snapshot 提供三方合并的「共同祖先」；
 *  3. 回滚 —— snapshot 可直接还原为一次新的写入版本。
 */
#[Fillable([
    'event_id', 'version', 'action', 'origin', 'changed_fields',
    'snapshot', 'base_snapshot', 'comment', 'user_id', 'ai_proposal_id', 'ip_address',
])]
class EventRevision extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'snapshot' => 'array',
            'base_snapshot' => 'array',
            'version' => 'integer',
            'action' => RevisionAction::class,
            'origin' => ChangeOrigin::class,
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aiProposal(): BelongsTo
    {
        return $this->belongsTo(AiProposal::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'origin' => $this->origin->value,
            'origin_label' => $this->origin->label(),
            'changed_fields' => $this->changed_fields ?? [],
            'comment' => $this->comment,
            'author' => $this->relationLoaded('user') ? $this->user?->displayLabel() : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
