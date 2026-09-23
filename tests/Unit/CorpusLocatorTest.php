<?php

namespace Tests\Unit;

use App\Support\CorpusLocator;
use PHPUnit\Framework\TestCase;

/**
 * 引文定位器：L3「出处可定位」硬闸门的实现。
 *
 * 匹配口径是本类唯一需要被反复确认的事 —— 它松一点，闸门就形同虚设；
 * 紧一点，「被排版折行的引文」就会集体误报，维护者随即学会忽略它。
 * 因此这里把「容忍什么」与「不容忍什么」都钉死。
 */
class CorpusLocatorTest extends TestCase
{
    private const CORPUS = <<<'TXT'
泰拉纪年：年表
注：[]为凯尔希补充

结晶时代
969 莱塔尼亚巫王赫尔昏佐伦即位
    叙拉古正式脱离莱塔尼亚
990 一片全新的地区由维多利亚首次发现，并被命名为“哥伦比亚”
TXT;

    public function test_locates_a_quote_and_reports_line_and_char_offset(): void
    {
        $locator = CorpusLocator::forText(self::CORPUS);
        $hit = $locator->locate('叙拉古正式脱离莱塔尼亚');

        $this->assertNotNull($hit);
        $this->assertSame(6, $hit->line);
        // 必须是**字符**偏移而不是字节偏移：中文一个字三字节，混用会让「回跳原文」
        // 跳到完全无关的位置，而这正是这个字段存在的意义。
        $this->assertSame(mb_strpos(self::CORPUS, '叙拉古'), $hit->charOffset);
        $this->assertNotSame(strpos(self::CORPUS, '叙拉古'), $hit->charOffset);
    }

    /**
     * 引文被排版折行、或带前后空白时仍须命中。
     * 若不容忍这一点，OCR 与排版的换行会让近半数引文误报，闸门随即失去威信。
     */
    public function test_tolerates_whitespace_and_line_breaks_inside_the_quote(): void
    {
        $locator = CorpusLocator::forText(self::CORPUS);

        $this->assertNotNull($locator->locate("  叙拉古\n正式脱离莱塔尼亚  "));
        $this->assertNotNull($locator->locate('969 莱塔尼亚巫王赫尔昏佐伦即位'));
    }

    /** 改写过的引文永远定位不到 —— 这正是闸门要拦的东西。 */
    public function test_rejects_rewritten_quotes(): void
    {
        $locator = CorpusLocator::forText(self::CORPUS);

        // 续行本身不带年份：补一个「969」前缀看起来更整齐，却已不是逐字引文
        $this->assertNull($locator->locate('969 叙拉古正式脱离莱塔尼亚'));
        // 同义改写同样拦下
        $this->assertNull($locator->locate('叙拉古正式独立'));
        $this->assertNull($locator->locate('叙拉古正式脱离莱塔尼亚帝国'));
        $this->assertNull($locator->locate(''));
    }

    public function test_distinguishes_continuation_lines_from_year_headed_lines(): void
    {
        $locator = CorpusLocator::forText(self::CORPUS);

        $this->assertTrue($locator->lineStartsWithYear(5));
        $this->assertFalse($locator->lineStartsWithYear(6));

        // 第 6 行是第 5 行的续行：年表用这种形态表达「同一年下的第二个分句」
        $this->assertTrue($locator->isContinuationLine(6));
        $this->assertFalse($locator->isContinuationLine(5));
        // 标题行既不以年份开头，也不是续行
        $this->assertFalse($locator->lineStartsWithYear(1));
        $this->assertFalse($locator->isContinuationLine(1));
    }

    public function test_enumerator_is_one_based_and_matches_line_count(): void
    {
        $locator = CorpusLocator::forText(self::CORPUS);

        $this->assertSame(7, $locator->lineCount());
        $this->assertSame('969 莱塔尼亚巫王赫尔昏佐伦即位', $locator->lineText(5));
        $this->assertSame('', $locator->lineText(999));
    }

    public function test_missing_file_yields_null_instead_of_throwing(): void
    {
        $this->assertNull(CorpusLocator::forFile('/nowhere/does-not-exist.txt'));
    }

    public function test_squeeze_only_removes_whitespace(): void
    {
        // 「只丢空白」是这条闸门的全部宽容度：标点与文字一个都不能动
        $this->assertSame('叙拉古正式脱离', CorpusLocator::squeeze(" 叙拉古\n正式 脱离 "));
        $this->assertSame('叙拉古正式脱离莱塔尼亚。', CorpusLocator::squeeze('叙拉古正式脱离莱塔尼亚。'));
    }
}
