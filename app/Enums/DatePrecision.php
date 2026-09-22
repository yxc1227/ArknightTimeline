<?php

namespace App\Enums;

/**
 * 游戏内纪元时间的精度。
 *
 * 《明日方舟》的纪年文本粒度极不统一（精确到日 / 只给月份 / 只给年份 / 只给季节 / 完全未知），
 * 因此时间一律以「区间」存储，精度决定区间的宽窄。
 */
enum DatePrecision: string
{
    case Day = 'day';
    case Month = 'month';
    case Season = 'season';
    case Year = 'year';
    case Range = 'range';       // 显式区间，例如「1096年—1098年」
    case Relative = 'relative'; // 相对锚点，例如「切城事变后 3 年」
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Day => '精确到日',
            self::Month => '精确到月',
            self::Season => '季节',
            self::Year => '仅纪年',
            self::Range => '区间',
            self::Relative => '相对时间',
            self::Unknown => '未知',
        };
    }

    /** 精度越低，界面提示越强，检索时越依赖相邻条目。 */
    public function isFuzzy(): bool
    {
        return in_array($this, [self::Year, self::Season, self::Range, self::Relative, self::Unknown], true);
    }
}
