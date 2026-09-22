<?php

namespace App\Models;

use App\Enums\AnnotationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 标注 / 纠错建议。
 *
 * 权限上刻意与「编辑」解耦：viewer 也能提交标注，
 * 因为「指出问题」的门槛必须低于「改动正文」，否则时间线的纠错回路会断。
 */
#[Fillable([
    'event_id', 'user_id', 'type', 'field', 'body',
    'suggested_patch', 'status', 'resolved_by', 'resolved_at',
])]
class Annotation extends Model
{
    protected function casts(): array
    {
        return [
            'type' => AnnotationType::class,
            'suggested_patch' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'field' => $this->field,
            'body' => $this->body,
            'suggested_patch' => $this->suggested_patch,
            'status' => $this->status,
            'author' => $this->relationLoaded('user') ? $this->user?->displayLabel() : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
