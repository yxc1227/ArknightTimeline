<?php

namespace Tests\Unit;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Support\TerraDate;
use App\Support\TerraDateParser;
use PHPUnit\Framework\TestCase;

/**
 * 泰拉历解析的边界。这是整个时间线的地基：
 * 解析错了不会报错，只会安静地把条目排到错误的位置 —— 所以覆盖要尽可能密。
 */
class TerraDateParserTest extends TestCase
{
    private TerraDateParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TerraDateParser();
    }

    public function test_parses_full_date_to_a_single_point(): void
    {
        $date = $this->parser->parse('泰拉历1096年12月23日');

        $this->assertSame(DatePrecision::Day, $date->precision);
        $this->assertSame(1096, $date->year);
        $this->assertSame(12, $date->month);
        $this->assertSame(23, $date->day);
        $this->assertSame($date->startIndex, $date->endIndex);
        $this->assertSame(TerraDate::toIndex(1096, 12, 23), $date->startIndex);
    }

    public function test_parses_iso_style_dates(): void
    {
        $dashed = $this->parser->parse('1096-12-23');
        $slashed = $this->parser->parse('1096/12/23');

        $this->assertSame(DatePrecision::Day, $dashed->precision);
        $this->assertSame($dashed->startIndex, $slashed->startIndex);
    }

    public function test_month_precision_expands_to_whole_month(): void
    {
        $date = $this->parser->parse('泰拉历1097年12月');

        $this->assertSame(DatePrecision::Month, $date->precision);
        [$start, $end] = TerraDate::monthBounds(1097, 12);

        $this->assertSame($start, $date->startIndex);
        $this->assertSame($end, $date->endIndex);
    }

    public function test_season_precision_and_winter_crossing_year(): void
    {
        $winter = $this->parser->parse('泰拉历1097年冬');

        $this->assertSame(DatePrecision::Season, $winter->precision);
        // 冬季跨年：起点在 1097 年 12 月，终点落在次年 2 月
        $this->assertSame(TerraDate::toIndex(1097, 12, 1), $winter->startIndex);
        $this->assertGreaterThan(TerraDate::toIndex(1098, 1, 1), $winter->endIndex);
        $this->assertLessThanOrEqual(TerraDate::toIndex(1098, 2, 31), $winter->endIndex);

        $summer = $this->parser->parse('泰拉历1097年夏');
        $this->assertSame(DatePrecision::Season, $summer->precision);
        $this->assertSame(TerraDate::toIndex(1097, 6, 1), $summer->startIndex);
    }

    public function test_partial_year_narrows_the_range(): void
    {
        $early = $this->parser->parse('1097年初');
        $late = $this->parser->parse('泰拉历1097年末');

        $this->assertSame(DatePrecision::Year, $early->precision);
        $this->assertLessThan($late->startIndex, $early->endIndex);
        $this->assertGreaterThan(TerraDate::toIndex(1097), $late->startIndex);
    }

    public function test_year_only_expands_to_whole_year(): void
    {
        $date = $this->parser->parse('泰拉历1099年');
        [$start, $end] = TerraDate::yearBounds(1099);

        $this->assertSame(DatePrecision::Year, $date->precision);
        $this->assertSame($start, $date->startIndex);
        $this->assertSame($end, $date->endIndex);
    }

    public function test_explicit_range(): void
    {
        $date = $this->parser->parse('1096年12月23日 — 1097年1月5日');

        $this->assertSame(DatePrecision::Range, $date->precision);
        $this->assertSame(TerraDate::toIndex(1096, 12, 23), $date->startIndex);
        $this->assertSame(TerraDate::toIndex(1097, 1, 5), $date->endIndex);
    }

    public function test_reversed_range_is_normalised(): void
    {
        $date = $this->parser->parse('1097年3月 — 1097年1月');

        $this->assertLessThan($date->endIndex, $date->startIndex);
    }

    public function test_before_era_years_are_negative_and_sorted_first(): void
    {
        $date = $this->parser->parse('泰拉历前500年');

        $this->assertSame(-500, $date->year);
        $this->assertSame(DatePrecision::Year, $date->precision);
        $this->assertLessThan(TerraDate::toIndex(1, 1, 1), $date->startIndex);
    }

    public function test_relative_before_era_phrase_is_not_mistaken_for_absolute_year(): void
    {
        // 「事变前3年」是相对时间，不能被当成「纪元前 3 年」
        $date = $this->parser->parse('切尔诺伯格事变前3年');

        $this->assertSame(DatePrecision::Relative, $date->precision);
        $this->assertSame('切尔诺伯格事变', $date->anchorLabel);
        $this->assertSame(-3, $date->anchorOffsetYears);
        $this->assertTrue($date->isUnanchored());
    }

    public function test_relative_after_phrase_keeps_the_anchor_label(): void
    {
        $date = $this->parser->parse('切尔诺伯格事变后3年');

        $this->assertSame(DatePrecision::Relative, $date->precision);
        $this->assertSame(3, $date->anchorOffsetYears);
        $this->assertSame(TerraDate::UNKNOWN_INDEX, $date->startIndex);
    }

    public function test_unparseable_text_falls_back_to_unknown_instead_of_throwing(): void
    {
        // 「时间未定」是泰拉时间线的常态，必须能被正常录入而不是抛异常
        $date = $this->parser->parse('泰拉历纪元前（年表未载）');

        $this->assertSame(DatePrecision::Unknown, $date->precision);
        $this->assertSame(TerraDate::UNKNOWN_INDEX, $date->startIndex);
        $this->assertTrue($date->isUnanchored());
        $this->assertSame('泰拉历纪元前（年表未载）', $date->display);
    }

    public function test_empty_input_is_unknown(): void
    {
        $this->assertSame(DatePrecision::Unknown, $this->parser->parse(null)->precision);
        $this->assertSame(DatePrecision::Unknown, $this->parser->parse('   ')->precision);
    }

    public function test_display_text_is_preserved_verbatim(): void
    {
        // 展示层永远回落到原文，绝不从索引反推格式化（那会伪造精度）
        $raw = '泰拉历1097年冬';
        $this->assertSame($raw, $this->parser->parse($raw)->display);
    }

    public function test_fullwidth_digits_are_normalised(): void
    {
        $date = $this->parser->parse('泰拉历１０９６年１２月２３日');

        $this->assertSame(1096, $date->year);
        $this->assertSame(23, $date->day);
    }

    public function test_index_round_trip(): void
    {
        foreach ([[1096, 12, 23], [1101, 1, 1], [-500, 1, 1], [1097, 14, 31]] as [$y, $m, $d]) {
            $index = TerraDate::toIndex($y, $m, $d);
            $parts = TerraDate::fromIndex($index);

            $this->assertSame($index, TerraDate::toIndex($parts['year'], $parts['month'], $parts['day']));
        }
    }

    public function test_overlap_semantics_used_by_range_filtering(): void
    {
        $winter = $this->parser->parse('泰拉历1097年冬');

        // 「1097年冬」应能命中「1098年1月」这一天的查询
        $january = TerraDate::toIndex(1098, 1, 15);
        $this->assertTrue(TerraDate::overlaps($winter->startIndex, $winter->endIndex, $january, $january));

        // 但不该命中「1096年」——那在冬天开始之前
        [$y1096Start, $y1096End] = TerraDate::yearBounds(1096);
        $this->assertFalse(TerraDate::overlaps($winter->startIndex, $winter->endIndex, $y1096Start, $y1096End));
    }

    public function test_confidence_is_carried_through(): void
    {
        $date = $this->parser->parse('1097年', DateConfidence::Disputed);

        $this->assertSame(DateConfidence::Disputed, $date->confidence);
    }

    public function test_describe_index_hints_at_month(): void
    {
        $this->assertStringContainsString('1096', TerraDate::describeIndex(TerraDate::toIndex(1096, 12, 23)));
        $this->assertSame('时间未定', TerraDate::describeIndex(TerraDate::UNKNOWN_INDEX));
    }
}
