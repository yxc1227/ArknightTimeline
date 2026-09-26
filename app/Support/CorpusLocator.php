<?php

namespace App\Support;

/**
 * 引文定位器：在出处原文（`sources.raw_text`）里找出引文的位置。
 *
 * README 把「出处可定位」列为 L3 硬闸门 —— 但闸门要有人真的去核对才成立，
 * 本类就是那次核对：给一段引文，回答「它在原文的哪个字符、哪一行」，
 * 或者回答「找不到」。找不到就意味着这条引文不是逐字抄的。
 *
 * ## 匹配规则：容忍空白，不容忍改写
 *
 * 归一化时**只丢弃空白字符**（含换行：设定集原文里引文常被排版折行切断），
 * 其余字符必须逐字相同。于是：
 *
 *   - 「被断行切断的引文」仍能定位 —— 这是必须容忍的，否则 OCR / 排版的换行
 *     会让一半引文都变成「定位失败」，闸门随即被当成噪音而失去威信；
 *   - 「改写过的引文」永远定位不到 —— 这正是闸门要拦的东西。
 *
 * 刻意不做同义替换、标点折叠、模糊匹配：那些都会让「定位成功」不再等于
 * 「逐字一致」，闸门也就白设了。
 */
final class CorpusLocator
{
    /** 按「文件 + 指纹」缓存：同一进程内多次 seed / 校验只解析一次全文。 */
    private static array $fileCache = [];

    /** 归一化后的全文（已去掉全部空白字符）。 */
    private string $normalized = '';

    /**
     * 第 i 个归一化字符在原文中的**字节**偏移。
     *
     * 存字节而不是字符偏移是刻意的：字节偏移由 preg_match_all 直接给出，
     * 一次遍历即可拿到百万字符级原文的全部位置；换算成字符偏移则要为每个字符
     * 调用一次 mb_strlen，代价高一个数量级。换算只在**命中时**做一次。
     */
    private array $byteOffsets = [];

    private function __construct(private readonly string $text)
    {
        $this->index();
    }

    public static function forText(string $text): self
    {
        return new self($text);
    }

    /**
     * 从磁盘上的文件构建（带进程内缓存）。文件不存在时返回 null ——
     * 调用方应当据 graceful 处理，而不是让整条 seed 流程失败。
     */
    public static function forFile(string $path): ?self
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $key = $path.'|'.filesize($path).'|'.filemtime($path);

        return self::$fileCache[$key] ??= new self((string) file_get_contents($path));
    }

    private function index(): void
    {
        // 一次抓出全部非空白字符及其字节位置，避免逐字符的 PHP 循环
        preg_match_all('/\S/u', $this->text, $matches, PREG_OFFSET_CAPTURE);

        $normalized = '';
        $offsets = [];

        foreach ($matches[0] as [$char, $byteOffset]) {
            $normalized .= $char;
            $offsets[] = $byteOffset;
        }

        $this->normalized = $normalized;
        $this->byteOffsets = $offsets;
    }

    /** 逐字查找引文；找不到返回 null（= 该引文不是逐字抄自这段原文）。 */
    public function locate(string $quote): ?CorpusHit
    {
        $needle = self::squeeze($quote);

        if ($needle === '') {
            return null;
        }

        $position = mb_strpos($this->normalized, $needle);

        if ($position === false) {
            return null;
        }

        $byteOffset = $this->byteOffsets[$position];

        return new CorpusHit(
            // 命中时才做一次字节 → 字符的换算
            charOffset: mb_strlen(substr($this->text, 0, $byteOffset)),
            byteOffset: $byteOffset,
            line: substr_count($this->text, "\n", 0, $byteOffset) + 1,
            length: mb_strlen($needle),
        );
    }

    /** 该行是否为「续行」——即本身不以年份开头、但紧接着一个以年份开头的行。 */
    public function isContinuationLine(int $line): bool
    {
        $lines = $this->lines();

        return isset($lines[$line - 1], $lines[$line - 2])
            && ! self::startsWithYear($lines[$line - 1])
            && self::startsWithYear($lines[$line - 2]);
    }

    /** 该行（1 起算）是否以年份或编者按标记开头。 */
    public function lineStartsWithYear(int $line): bool
    {
        $lines = $this->lines();

        return isset($lines[$line - 1]) && self::startsWithYear($lines[$line - 1]);
    }

    /** 取某行的原文（不含行尾换行），供出处页就地展示上下文。 */
    public function lineText(int $line): string
    {
        return $this->lines()[$line - 1] ?? '';
    }

    public function lineCount(): int
    {
        return count($this->lines());
    }

    /** @return list<string> */
    private function lines(): array
    {
        return explode("\n", $this->text);
    }

    /** 只丢空白，其余一律保留 —— 见类注释的匹配规则。 */
    public static function squeeze(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }

    private static function startsWithYear(string $line): bool
    {
        return (bool) preg_match('/^\s*(?:\d{3,4}|\[)/u', $line);
    }
}
