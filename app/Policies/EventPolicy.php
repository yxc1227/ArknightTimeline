<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

/**
 * 条目级权限。
 *
 * 设计原则：**读永远开放，写分级，冻结状态例外**。
 *  - 未登录用户可完整浏览时间线（这是靠爱发电的考据项目，不能先登录再看）；
 *  - 写入需要 editor；
 *  - 「标记已校验」「处理争议」「锁定条目」需要 reviewer；
 *  - 条目被锁定或进入争议态后，editor 的写权限被收回，只能走标注建议。
 */
class EventPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Event $event): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return (bool) $user?->canEditEvents();
    }

    public function update(?User $user, Event $event): bool
    {
        if (! $user?->canEditEvents()) {
            return false;
        }

        if ($event->is_locked && ! $user->canReview()) {
            return false;
        }

        if ($event->isFrozen() && ! $user->canReview()) {
            return false;
        }

        return true;
    }

    public function delete(?User $user, Event $event): bool
    {
        return (bool) $user?->canEditEvents() && (! $event->is_locked || $user->canReview());
    }

    /** 审核动作：标记已校验、解除争议、锁定 / 解锁。 */
    public function review(?User $user, Event $event): bool
    {
        return (bool) $user?->canReview();
    }

    public function resolveConflict(?User $user, Event $event): bool
    {
        return (bool) $user?->canEditEvents();
    }

    /** 回滚历史版本。 */
    public function revert(?User $user, Event $event): bool
    {
        return (bool) $user?->canReview();
    }

    public function restore(?User $user, Event $event): bool
    {
        return (bool) $user?->canReview();
    }

    /** 标注对所有人开放，包括未登录访客（匿名标注会记 user_id = null）。 */
    public function annotate(?User $user, Event $event): bool
    {
        return true;
    }
}
