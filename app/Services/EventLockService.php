<?php

namespace App\Services;

use App\Exceptions\WriteDeniedException;
use App\Models\Event;
use App\Models\EventLock;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 编辑租约（软锁）。
 *
 * 为什么不做硬锁：时间线的编辑是长尾分布，绝大多数条目同时只有一人在看；
 * 而硬锁会产生僵尸锁（用户直接关标签页），把条目永久锁死比偶尔的冲突严重得多。
 *
 * 因此这里只做三件事：
 *   1. 公开「谁正在编辑什么」，消解信息不对称导致的重复劳动；
 *   2. 用租约（默认 5 分钟，可续租）自动回收，不产生永久锁；
 *   3. 审核员可以强制接管（force），保留人工兜底能力。
 *
 * 真正的数据安全由 EventWriter 的乐观锁保证 —— 即使软锁被绕过，也不会丢更新。
 */
final class EventLockService
{
    /** 尝试获取/续租编辑租约。 */
    public function acquire(Event $event, User $user, bool $force = false): EventLock
    {
        $this->purgeExpired();

        $existing = EventLock::where('event_id', $event->id)->first();

        if ($existing && ! $existing->isExpired() && $existing->user_id !== $user->id && ! $force) {
            $holder = $existing->user?->displayLabel() ?? '其他编辑者';

            throw new WriteDeniedException(
                sprintf('「%s」正在编辑该条目，你的改动仍可提交，但可能触发冲突合并。', $holder),
                'locked_by_other',
            );
        }

        if ($existing && $force && $existing->user_id !== $user->id && ! $user->canReview()) {
            throw new WriteDeniedException('强制接管他人编辑租约需要审核员权限。', 'lock_force_denied');
        }

        $seconds = (int) config('timeline.collaboration.lease_seconds', EventLock::LEASE_SECONDS);

        return EventLock::updateOrCreate(
            ['event_id' => $event->id],
            [
                'user_id' => $user->id,
                'token' => $existing?->token ?? Str::random(48),
                'reason' => 'editing',
                'expires_at' => now()->addSeconds($seconds),
            ],
        );
    }

    /** 续租。token 不匹配说明租约已被他人接管，返回 null 让前端提示。 */
    public function renew(string $token, User $user): ?EventLock
    {
        $lock = EventLock::where('token', $token)->where('user_id', $user->id)->first();

        if (! $lock) {
            return null;
        }

        $seconds = (int) config('timeline.collaboration.lease_seconds', EventLock::LEASE_SECONDS);
        $lock->update(['expires_at' => now()->addSeconds($seconds)]);

        return $lock;
    }

    public function release(string $token, ?User $user = null): void
    {
        $query = EventLock::where('token', $token);

        if ($user && ! $user->canReview()) {
            $query->where('user_id', $user->id);
        }

        $query->delete();
    }

    /** 当前租约状态，供详情面板显示「谁在编辑」。未登录访客也能看到租约归属。 */
    public function status(Event $event, ?User $user): array
    {
        $this->purgeExpired();

        $lock = EventLock::with('user')->where('event_id', $event->id)->first();

        if (! $lock) {
            return ['locked' => false];
        }

        return [
            'locked' => true,
            'mine' => $user !== null && $lock->user_id === $user->id,
            'holder' => $lock->user?->displayLabel(),
            'expires_at' => $lock->expires_at->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($lock->expires_at, false)),
        ];
    }

    public function purgeExpired(): int
    {
        return EventLock::purgeExpired();
    }
}
