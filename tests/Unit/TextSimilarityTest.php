<?php

namespace Tests\Unit;

use App\Support\TextSimilarity;
use PHPUnit\Framework\TestCase;

/**
 * 相似度与引用定位是两条关键防线：
 * 前者决定「疑似重复」能不能被拦住，后者决定 AI 的引用是否真的出自原文。
 */
class TextSimilarityTest extends TestCase
{
    public function test_identical_titles_score_one(): void
    {
        $this->assertSame(1.0, TextSimilarity::ratio('切尔诺伯格事变', '切尔诺伯格事变'));
    }

    public function test_whitespace_and_punctuation_are_ignored(): void
    {
        $this->assertSame(1.0, TextSimilarity::ratio('切尔诺伯格事变', '切尔诺伯格事变。'));
        $this->assertSame(1.0, TextSimilarity::ratio('切尔诺伯格 事变', '切尔诺伯格事变'));
    }

    public function test_similar_titles_score_high(): void
    {
        // 同一事件的不同表述应被判定为疑似重复
        $score = TextSimilarity::ratio('切尔诺伯格事变', '切尔诺伯格事变爆发');

        $this->assertGreaterThanOrEqual(0.82, $score);
    }

    public function test_unrelated_titles_score_low(): void
    {
        $score = TextSimilarity::ratio('切尔诺伯格事变', '维多利亚内战全面爆发');

        $this->assertLessThan(0.3, $score);
    }

    public function test_empty_and_one_sided_input(): void
    {
        $this->assertSame(1.0, TextSimilarity::ratio('', ''));
        $this->assertSame(0.0, TextSimilarity::ratio('切尔诺伯格事变', ''));
        $this->assertSame(0.0, TextSimilarity::ratio('', '切尔诺伯格事变'));
    }

    public function test_single_character_titles_are_handled(): void
    {
        $this->assertSame(1.0, TextSimilarity::ratio('岁', '岁'));
        $this->assertSame(0.0, TextSimilarity::ratio('岁', '夕'));
    }

    public function test_contains_quote_tolerates_whitespace_differences(): void
    {
        $haystack = '泰拉历1096年12月23日，切尔诺伯格事变爆发，
整合运动占领切尔诺伯格城区。';

        $this->assertTrue(TextSimilarity::containsQuote($haystack, '切尔诺伯格事变爆发，整合运动占领切尔诺伯格城区'));
    }

    public function test_contains_quote_rejects_paraphrase(): void
    {
        $haystack = '泰拉历1096年12月23日，切尔诺伯格事变爆发。';

        // 改写过的句子不能被当成原文引用，否则防幻觉闸门就失效了
        $this->assertFalse(TextSimilarity::containsQuote($haystack, '切尔诺伯格遭到整合运动攻陷'));
    }

    public function test_locate_quote_returns_character_offsets(): void
    {
        $haystack = '泰拉历1096年12月23日，切尔诺伯格事变爆发。';
        $position = TextSimilarity::locateQuote($haystack, '切尔诺伯格事变爆发');

        $this->assertNotNull($position);
        [$start, $end] = $position;

        $this->assertSame('切尔诺伯格事变爆发', mb_substr($haystack, $start, $end - $start, 'UTF-8'));
    }

    public function test_locate_quote_returns_null_when_absent(): void
    {
        $this->assertNull(TextSimilarity::locateQuote('泰拉历1096年', '龙门危机'));
        $this->assertNull(TextSimilarity::locateQuote('泰拉历1096年', ''));
    }

    public function test_locate_quote_skips_punctuation_between_characters(): void
    {
        $haystack = '泰拉历1100年，维多利亚内战全面爆发。';
        $position = TextSimilarity::locateQuote($haystack, '维多利亚、内战全面爆发');

        // 归一化会丢弃顿号，因此定位应成功并映射回原串区间
        $this->assertNotNull($position);
    }
}
