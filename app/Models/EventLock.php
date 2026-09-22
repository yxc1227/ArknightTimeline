<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 条目编辑租约。
 *
 * 这是**软锁**：不阻止他人编辑，只把「谁正在编辑」这个事实公开出去。
 * 因为硬锁在多人协作里会变成僵尸锁（关掉浏览器就走了），
 * 而时间线的冲突远少于「信息不对称」造成的重复劳动，所以选择「可见 + 租约 + 冲突合并」而非互斥。
 */
#[Fillable(['event_id', 'user_id', 'token', 'reason', 'expires_at'])]
class EventLock extends Model
{
    /** 租约时长（秒）。客户端每 60s 续租一次。 */
    public const LEASE_SECONDS = 300;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
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

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public static function purgeExpired(): int
    {
        return static::where('expires_at', '<', now())->delete();
    }

    public function toApiArray(): array
    {
        return [
            'user' => $this->relationLoaded('user') ? $this->user?->displayLabel() : null,
            'reason' => $this->reason,
            'expires_at' => $this->expires_at->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($this->expires_at, false)),
        ];
    }
}
