<?php

namespace App\Policies;

use App\Models\AiProposal;
use App\Models\User;

/**
 * AI 提案权限。
 *
 * 关键点：**看**和**放行**是两件事。
 * editor 可以看提案、可以发起梳理任务（AI 产出一律是待审状态，没有直接危害），
 * 但只有 reviewer 能把 AI 内容写进时间线 —— 这是防幻觉的最后一道闸门。
 */
class AiProposalPolicy
{
    public function viewAny(?User $user): bool
    {
        return (bool) $user?->canEditEvents();
    }

    public function view(?User $user, AiProposal $proposal): bool
    {
        return (bool) $user?->canEditEvents();
    }

    /** 发起 AI 梳理任务。 */
    public function create(?User $user): bool
    {
        return (bool) $user?->canEditEvents();
    }

    /** 采纳 / 驳回 / 合并。 */
    public function review(?User $user, AiProposal $proposal): bool
    {
        return (bool) $user?->canReview();
    }
}
