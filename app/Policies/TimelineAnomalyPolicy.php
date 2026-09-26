<?php

namespace App\Policies;

use App\Models\TimelineAnomaly;
use App\Models\User;

class TimelineAnomalyPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, TimelineAnomaly $anomaly): bool
    {
        return true;
    }

    /** 处置异常（标记忽略 / 已解决 / 触发全量体检）需要审核员。 */
    public function resolve(?User $user, TimelineAnomaly $anomaly): bool
    {
        return (bool) $user?->canReview();
    }

    public function scan(?User $user): bool
    {
        return (bool) $user?->canReview();
    }
}
