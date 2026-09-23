<?php

namespace App\Support;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;

/**
 * 把《明日方舟》的纪年文本解析成 TerraDate 区间。
 *
 * 解析策略：从最精确的模式往下退化，首个命中者胜出；
 * 任何无法解析的输入都会得到 precision=unknown 而不是抛异常 —— 因为
 * 「时间未定」是时间线的常态（泰拉与塔卫二都有大量年份未载的条目），
 * 必须能被正常录入与检索，而不是被当作录入错误挡下。
 *
 * 支持形态（示例）：
 *   泰拉历1096年12月23日 / 1096-12-23 / 1096/12/23   → day
 *   1097年12月                                        → month
 *   1097年冬 / 1097年秋季                              → season
 *   1097年初 / 1097年末 / 1097年中                      → year（收窄区间）
 *   1097年                                            → year
 *   1096年12月23日 — 1097年1月5日                       → range
 *   切尔诺伯格事变后 3 年                               → relative（需锚点回填）
 *   泰拉历前 200 年 / 远古                                → unknown
 */
final class TerraDateParser
{
    /** 季节 → 月份，仅用于区间宽度推算。 */
    private const SEASON_MONTHS = 3;

    /** 相对时间：「切尔诺伯格事变前 3 年」= 2-24 字锚点 + 前/后 + 年数。 */
    private const RELATIVE_PATTERN = '/(.{2,24}?)(?:之后|以后|后|前)\s*(\d{1,3})\s*年/u';

    /** 纪元前年份：「泰拉历前 200 年」。历法前缀可省，但「前」必须紧贴串首。 */
    private const BEFORE_ERA_PATTERN = '/^(?:泰拉历|塔罗斯历|泰拉|纪元|元)?\s*前\s*(\d{1,4})\s*年/u';

    /**
     * 是否是相对时间。
     *
     * 与 parseRelative 共用同一个模式常量：两处各写一份正则，
     * 迟早会出现「优先级判断说它是相对时间、解析器却解析不出来」的错配。
     */
    private function isRelative(string $text): bool
    {
        return preg_match(self::RELATIVE_PATTERN, $text) === 1;
    }

    /** 是否是纪元前年份（它的「前」与相对时间的「前」不是一回事）。 */
    private function isBeforeEra(string $text): bool
    {
        return preg_match(self::BEFORE_ERA_PATTERN, $text) === 1;
    }

    public function parse(?string $raw, DateConfidence $confidence = DateConfidence::Confirmed): TerraDate
    {
        $text = $this->normalize((string) $raw);

        if ($text === '') {
            return TerraDate::unknown();
        }

        /*
         * 相对时间优先。
         *
         * 原本不需要这一步：绝对年份的模式要求四位数，于是「切尔诺伯格事变前3年」
         * 里的 3 匹配不上，安全地落到了 parseRelative。但塔罗斯历的年份只有
         * 一两位数（「塔罗斯历 5 年」），模式必须放宽到 \d{1,4} ——
         * 那条隐式保护随之消失，于是改写成显式判断。
         *
         * 唯一的例外是**纪元前年份**：「泰拉历前200年」也有「前」，但它是绝对年份。
         * 两者的区别很明确 —— 纪元前的「前」紧跟在历法名之后（或位于串首），
         * 而相对时间的「前 / 后」前面必须还有 2-24 字的锚点。
         */
        if (! $this->isBeforeEra($text) && $this->isRelative($text)) {
            return $this->parseRelative($text, $confidence) ?? TerraDate::unknown($raw);
        }

        return $this->parseRange($text, $confidence)
            ?? $this->parseDay($text, $confidence)
            ?? $this->parseMonth($text, $confidence)
            ?? $this->parseSeason($text, $confidence)
            ?? $this->parsePartialYear($text, $confidence)
            // 必须早于 parseRelative：否则「泰拉历前200年」会被误判成相对时间
            ?? $this->parseBeforeEra($text, $confidence)
            ?? $this->parseYear($text, $confidence)
            ?? $this->parseRelative($text, $confidence)
            ?? TerraDate::unknown($raw);
    }

    /** 全角转半角、统一分隔符、压缩空白，保留中文语义字符。 */
    private function normalize(string $raw): string
    {
        $text = trim($raw);

        // 全角数字 / 字母 → 半角
        $text = mb_convert_kana($text, 'as', 'UTF-8');
        // 破折号统一。必须把「单个」em dash（—）也列进来：
        // 语料里「1096年 — 1097年」这种单个破折号比「——」更常见，
        // 漏掉它会让区间被误判成单点日期。
        $text = str_replace(['——', '—', '―', '–', '−', 'ー', '－', '─'], '-', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text) ?? '';

        return trim((string) $text);
    }

    /** 显式区间：1096年12月23日 — 1097年1月5日 / 1097年12月-1098年2月 */
    private function parseRange(string $text, DateConfidence $confidence): ?TerraDate
    {
        $pattern = '/(\d{1,4})\s*年\s*(\d{1,2})\s*月(?:\s*(\d{1,2})\s*日)?'
            .'\s*(?:-|~|至|到)\s*(?:(\d{1,4})\s*年)?\s*(\d{1,2})\s*月(?:\s*(\d{1,2})\s*日)?/u';

        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        $startYear = (int) $m[1];
        $startMonth = (int) $m[2];
        $startDay = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 1;
        $endYear = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : $startYear;
        $endMonth = (int) $m[5];
        $endDay = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : TerraDate::DAYS_PER_MONTH;

        $start = TerraDate::toIndex($startYear, $startMonth, $startDay);
        $end = TerraDate::toIndex($endYear, $endMonth, $endDay);

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        return new TerraDate(
            display: $text,
            startIndex: $start,
            endIndex: $end,
            precision: DatePrecision::Range,
            confidence: $confidence,
            year: $startYear,
            month: $startMonth,
            day: $startDay,
        );
    }

    private function parseDay(string $text, DateConfidence $confidence): ?TerraDate
    {
        if (preg_match('/(\d{1,4})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $text, $m)
            || preg_match('/(\d{1,4})[-\/](\d{1,2})[-\/](\d{1,2})/u', $text, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            $index = TerraDate::toIndex($year, $month, $day);

            return new TerraDate(
                display: $text,
                startIndex: $index,
                endIndex: $index,
                precision: DatePrecision::Day,
                confidence: $confidence,
                year: $year,
                month: $month,
                day: $day,
            );
        }

        return null;
    }

    private function parseMonth(string $text, DateConfidence $confidence): ?TerraDate
    {
        if (preg_match('/(\d{1,4})\s*年\s*(\d{1,2})\s*月/u', $text, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            [$start, $end] = TerraDate::monthBounds($year, $month);

            return new TerraDate(
                display: $text,
                startIndex: $start,
                endIndex: $end,
                precision: DatePrecision::Month,
                confidence: $confidence,
                year: $year,
                month: $month,
            );
        }

        return null;
    }

    private function parseSeason(string $text, DateConfidence $confidence): ?TerraDate
    {
        if (preg_match('/(\d{1,4})\s*年\s*(?:的)?\s*(初春|早春|晚春|初秋|深秋|春|夏|秋|冬)/u', $text, $m)) {
            $year = (int) $m[1];
            $season = mb_substr($m[2], -1);

            [$start, $end] = TerraDate::seasonBounds($year, $season);

            return new TerraDate(
                display: $text,
                startIndex: $start,
                endIndex: $end,
                precision: DatePrecision::Season,
                confidence: $confidence,
                year: $year,
            );
        }

        return null;
    }

    /** 「1097年初 / 年中 / 年末」—— 精度仍是年，但收窄区间以改善排序。 */
    private function parsePartialYear(string $text, DateConfidence $confidence): ?TerraDate
    {
        if (preg_match('/(\d{1,4})\s*年\s*(初|末|中|底)/u', $text, $m)) {
            $year = (int) $m[1];
            $part = $m[2];

            [$monthStart, $monthEnd] = match ($part) {
                '初' => [1, 4],
                '中' => [5, 8],
                '末', '底' => [9, 12],
                default => [1, 12],
            };

            $start = TerraDate::toIndex($year, $monthStart, 1);
            $end = TerraDate::toIndex($year, $monthEnd, TerraDate::DAYS_PER_MONTH);

            return new TerraDate(
                display: $text,
                startIndex: $start,
                endIndex: $end,
                precision: DatePrecision::Year,
                confidence: $confidence,
                year: $year,
            );
        }

        return null;
    }

    /**
     * 纪元前的年份：「泰拉历前200年」→ year = -200。
     *
     * 刻意要求「前」出现在串首（允许「泰拉历 / 纪元 / 元」前缀），
     * 否则「切尔诺伯格事变前3年」这类相对时间会被误判成绝对年份。
     */
    private function parseBeforeEra(string $text, DateConfidence $confidence): ?TerraDate
    {
        if (preg_match(self::BEFORE_ERA_PATTERN, $text, $m)) {
            $year = -((int) $m[1]);
            [$start, $end] = TerraDate::yearBounds($year);

            return new TerraDate(
                display: $text,
                startIndex: $start,
                endIndex: $end,
                precision: DatePrecision::Year,
                confidence: $confidence,
                year: $year,
            );
        }

        return null;
    }

    private function parseYear(string $text, DateConfidence $confidence): ?TerraDate
    {
        if (preg_match('/(\d{1,4})\s*年/u', $text, $m)
            || preg_match('/\b(1\d{3})\b/u', $text, $m)) {
            $year = (int) $m[1];
            [$start, $end] = TerraDate::yearBounds($year);

            return new TerraDate(
                display: $text,
                startIndex: $start,
                endIndex: $end,
                precision: DatePrecision::Year,
                confidence: $confidence,
                year: $year,
            );
        }

        return null;
    }

    /**
     * 相对时间：例如「切尔诺伯格事变后 3 年」。
     * 无法就地求值，因此索引置为哨兵值，由 EventResolver 在锚点事件确定后回填。
     */
    private function parseRelative(string $text, DateConfidence $confidence): ?TerraDate
    {
        if (preg_match(self::RELATIVE_PATTERN, $text, $m)) {
            $isBefore = str_contains($m[0], '前');
            $offset = (int) $m[2] * ($isBefore ? -1 : 1);

            return new TerraDate(
                display: $text,
                startIndex: TerraDate::UNKNOWN_INDEX,
                endIndex: TerraDate::UNKNOWN_INDEX,
                precision: DatePrecision::Relative,
                confidence: $confidence,
                anchorLabel: trim($m[1]),
                anchorOffsetYears: $offset,
            );
        }

        return null;
    }
}
