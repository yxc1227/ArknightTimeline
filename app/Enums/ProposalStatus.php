<?php

namespace App\Enums;

/**
 * AI 提案状态机。
 *
 * pending ──approve──▶ approved ──apply──▶ applied
 *    │                                        ▲
 *    ├──reject──▶ rejected                    │
 *    └──duplicate──▶ duplicate ──merge───────┘
 *
 * 关键约束：AI 永不直接写 events 表，必须停在 pending 等待人工放行。
 */
enum ProposalStatus: string
{
    case Pending = 'pending';       // 待人工审阅
    case Approved = 'approved';     // 人工通过，等待落库
    case Applied = 'applied';       // 已写入时间线
    case Rejected = 'rejected';     // 人工驳回
    case Duplicate = 'duplicate';   // 疑似与既有条目重复，等待合并
    case Unverified = 'unverified'; // 缺少可定位原文引用，禁止通过

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待审阅',
            self::Approved => '已通过',
            self::Applied => '已入库',
            self::Rejected => '已驳回',
            self::Duplicate => '疑似重复',
            self::Unverified => '出处缺失',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge badge--warn',
            self::Approved => 'badge badge--info',
            self::Applied => 'badge badge--ok',
            self::Rejected => 'badge badge--muted',
            self::Duplicate => 'badge badge--danger',
            self::Unverified => 'badge badge--danger',
        };
    }

    /** 只有通过了「出处可定位」校验的提案才允许被采纳。 */
    public function canBeApproved(): bool
    {
        return in_array($this, [self::Pending, self::Duplicate], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Approved, self::Duplicate, self::Unverified], true);
    }
}
