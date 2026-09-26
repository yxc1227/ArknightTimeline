<?php

namespace App\Enums;

/**
 * 时间线一致性异常类型。由 TimelineConsistencyChecker 在每次写入后与定时任务中产出，
 * 汇入「一致性收件箱」供审核员处置。
 */
enum AnomalyType: string
{
    case OrderInversion = 'order_inversion';           // 因果倒置：结果早于起因
    case EraMismatch = 'era_mismatch';                 // 时代错位：落在所属纪元区间之外
    case SourceDisagreement = 'source_disagreement';   // 多出处互相矛盾
    case DuplicateSuspect = 'duplicate_suspect';       // 同日相似条目，疑似重复
    case ParentOutOfRange = 'parent_out_of_range';     // 子事件早于父事件
    case OverloadedDay = 'overloaded_day';             // 单日条目过多
    case UnanchoredRelative = 'unanchored_relative';   // 相对时间锚点失效

    public function label(): string
    {
        return match ($this) {
            self::OrderInversion => '因果倒置',
            self::EraMismatch => '时代错位',
            self::SourceDisagreement => '出处矛盾',
            self::DuplicateSuspect => '疑似重复',
            self::ParentOutOfRange => '子早于父',
            self::OverloadedDay => '单日过载',
            self::UnanchoredRelative => '锚点失效',
        };
    }

    /** 阻塞级异常会阻断「标记为已校验」，其余仅为提示。 */
    public function isBlocking(): bool
    {
        return in_array($this, [self::OrderInversion, self::ParentOutOfRange, self::UnanchoredRelative], true);
    }
}
