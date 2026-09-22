<?php

namespace App\Support;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;

/**
 * 《明日方舟》游戏内纪年（泰拉历）时间值对象。
 *
 * ## 为什么不用 datetime
 * 泰拉历不是完整可用的历法：官方文本粒度极不统一（「1096年12月23日」「1097年冬」「1098年」「黎博利纪元」），
 * 且存在跨纪元、跨千年的说法差异。强行落库成 carbon 会伪造精度、并在排序时产生错误。
 *
 * ## 存储模型
 * 时间一律以**闭区间** [startIndex, endIndex] 表示，精度决定区间宽度：
 *
 *   precision=day     → start == end
 *   precision=month   → 整月
 *   precision=season  → 整季（冬跨年，因此 month 允许 > 12）
 *   precision=year    → 整年
 *   precision=range   → 显式区间
 *   precision=relative→ 依赖锚点事件解析后回填
 *   precision=unknown → start = end = 0，单独归入「时间未定」泳道
 *
 * 网格索引是单调递增的整数，与公历闰年/月末无关：
 *
 *   index = year * 372 + (month - 1) * 31 + (day - 1)
 *
 * 这样「区间包含」查询、区间重叠查询都可以直接用 B-Tree 索引完成，
 * 且新插入任何条目都不会破坏既有条目的排序，天然避免多人编辑时「时间线被拆断」。
 *
 * 展示层永远回落到 `display` 原文，绝不从索引反推格式化，以免误导用户。
 */
final readonly class TerraDate
{
    /** 一年 12 个月，每月按 31 天占位。 */
    public const DAYS_PER_MONTH = 31;

    public const DAYS_PER_YEAR = 372;

    /** 未定位时间的哨兵索引。真实条目 year >= 1，因此 0 不会被占用。 */
    public const UNKNOWN_INDEX = 0;

    public function __construct(
        public string $display,
        public int $startIndex,
        public int $endIndex,
        public DatePrecision $precision,
        public DateConfidence $confidence = DateConfidence::Confirmed,
        public ?int $year = null,
        public ?int $month = null,
        public ?int $day = null,
        public ?string $anchorLabel = null,
        public ?int $anchorOffsetYears = null,
    ) {}

    public static function unknown(string $display = '时间未定'): self
    {
        return new self(
            display: $display,
            startIndex: self::UNKNOWN_INDEX,
            endIndex: self::UNKNOWN_INDEX,
            precision: DatePrecision::Unknown,
            confidence: DateConfidence::Unknown,
        );
    }

    public static function toIndex(int $year, int $month = 1, int $day = 1): int
    {
        $month = max(1, $month);
        $day = max(1, min(self::DAYS_PER_MONTH, $day));

        return $year * self::DAYS_PER_YEAR
            + ($month - 1) * self::DAYS_PER_MONTH
            + ($day - 1);
    }

    /** @return array{year:int, month:int, day:int} */
    public static function fromIndex(int $index): array
    {
        // 使用 floor 语义，保证负数（远古纪元）也正确回解。
        $year = (int) floor($index / self::DAYS_PER_YEAR);
        $rem = $index - $year * self::DAYS_PER_YEAR;
        $month = (int) floor($rem / self::DAYS_PER_MONTH) + 1;
        $day = $rem - ($month - 1) * self::DAYS_PER_MONTH + 1;

        return ['year' => $year, 'month' => $month, 'day' => $day];
    }

    /** @return array{0:int, 1:int} */
    public static function yearBounds(int $year): array
    {
        return [self::toIndex($year, 1, 1), self::toIndex($year, 12, 31)];
    }

    /** @return array{0:int, 1:int} */
    public static function monthBounds(int $year, int $month): array
    {
        return [
            self::toIndex($year, $month, 1),
            self::toIndex($year, $month, self::DAYS_PER_MONTH),
        ];
    }

    /**
     * 季节 → 月份区间。冬季跨年，因此结束月份为次年的 2 月。
     *
     * 结束位置取「下一季首月的第 1 天」再退 1 天，而不是「本季最后一个月 + 31 天」：
     * 后者会因为月份算术与年算术的年长不一致（12 × 31 = 372 恰好等于一年）而整体多出一个月，
     * 把冬季错误地延伸到次年 3 月末。
     *
     * @return array{0:int, 1:int}
     */
    public static function seasonBounds(int $year, string $season): array
    {
        [$startMonth, $span] = match ($season) {
            '春' => [3, 3],
            '夏' => [6, 3],
            '秋' => [9, 3],
            '冬' => [12, 3], // 12 月 → 次年 1 月 → 次年 2 月
            default => [1, 12],
        };

        $start = self::toIndex($year, $startMonth, 1);
        $end = self::toIndex($year, $startMonth + $span, 1) - 1;

        return [$start, $end];
    }

    /** 未定位时间（索引为哨兵值）。 */
    public function isUnanchored(): bool
    {
        return $this->precision === DatePrecision::Unknown
            || $this->startIndex === self::UNKNOWN_INDEX;
    }

    /** 索引区间宽度（天粒度单位），用于界面提示「约 ±N 天」。 */
    public function spread(): int
    {
        return max(0, $this->endIndex - $this->startIndex);
    }

    public function containsIndex(int $index): bool
    {
        return $index >= $this->startIndex && $index <= $this->endIndex;
    }

    /** 供界面显示的粗粒度回溯标签，例如「约 1097 年 12 月」。 */
    public static function describeIndex(int $index): string
    {
        if ($index === self::UNKNOWN_INDEX) {
            return '时间未定';
        }

        $parts = self::fromIndex($index);
        $month = (($parts['month'] - 1) % 12) + 1;

        return sprintf('约 泰拉历 %d 年 %d 月', $parts['year'], $month);
    }

    /**
     * 两个区间是否重叠。用于「按时间段筛选」与出处矛盾预检。
     */
    public static function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart <= $bEnd && $bStart <= $aEnd;
    }
}
