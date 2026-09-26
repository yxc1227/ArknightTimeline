<?php

namespace App\Support;

/**
 * 中文友好的文本相似度。
 *
 * 刻意不用 levenshtein：中文没有词边界，编辑距离对「切尔诺伯格事变」vs
 * 「切尔诺伯格核心城坠落事件」这类重组型标题的判别效果很差。
 * 改用字符 2-gram 的 Jaccard 系数，对增删字、语序调整都稳健，且无需分词依赖。
 */
final class TextSimilarity
{
    /** 归一化：去空白、去标点、统一小写。 */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\s\p{P}\p{S}]+/u', '', $text) ?? $text;

        return $text;
    }

    /** 包含关系（短串完整出现在长串中）的相似度下限。 */
    private const CONTAINMENT_FLOOR = 0.9;

    /**
     * 相似度，返回 0.0 ~ 1.0。
     *
     * 先用 2-gram Jaccard 度量整体重合度，再对**包含关系**单独提权：
     * 中文事件标题最常见的重复形态是「X」与「X爆发」这类后缀扩展，
     * 此时 Jaccard 会被长度差稀释（7 字 vs 9 字只有 0.75），
     * 但语义上它们几乎必然是同一事件，因此必须判定为疑似重复。
     */
    public static function ratio(string $a, string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        if ($a === '' && $b === '') {
            return 1.0;
        }

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $score = self::jaccard($a, $b);

        $shorter = mb_strlen($a, 'UTF-8') <= mb_strlen($b, 'UTF-8') ? $a : $b;
        $longer = $shorter === $a ? $b : $a;

        // 长度下限 3 是经验值：单字/双字标题（如「岁」「夕」）的包含关系没有判别力
        if (mb_strlen($shorter, 'UTF-8') >= 3 && mb_strpos($longer, $shorter) !== false) {
            return max($score, self::CONTAINMENT_FLOOR);
        }

        return $score;
    }

    private static function jaccard(string $a, string $b): float
    {
        $setA = self::bigrams($a);
        $setB = self::bigrams($b);

        if ($setA === [] || $setB === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($setA, $setB));
        $union = count($setA + $setB);

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /**
     * 归一化文本是否包含子串（用于 evidence 出处定位校验）。
     * 用归一化后的形式比对，可容忍原文里的换行、空格与标点差异。
     */
    public static function containsQuote(string $haystack, string $quote): bool
    {
        $quote = self::normalize($quote);

        if ($quote === '') {
            return false;
        }

        return mb_strpos(self::normalize($haystack), $quote) !== false;
    }

    /**
     * 在原串中定位归一化片段对应的字符区间，返回 [start, end)；找不到返回 null。
     * 用于把 AI 给出的引用回填成可点击的原文高亮。
     *
     * @return array{0:int, 1:int}|null
     */
    public static function locateQuote(string $haystack, string $quote): ?array
    {
        $needle = self::normalize($quote);

        if ($needle === '') {
            return null;
        }

        // 逐字符扫描，跳过被归一化丢弃的字符，拼出到归一化流的映射。
        $map = [];
        $stream = '';
        $length = mb_strlen($haystack, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($haystack, $i, 1, 'UTF-8');
            if (self::normalize($char) === '') {
                continue;
            }
            $stream .= mb_strtolower($char, 'UTF-8');
            $map[] = $i;
        }

        $pos = mb_strpos($stream, $needle);

        if ($pos === false) {
            return null;
        }

        $start = $map[$pos] ?? null;
        $endIndex = $pos + mb_strlen($needle, 'UTF-8') - 1;
        $end = $map[$endIndex] ?? null;

        if ($start === null || $end === null) {
            return null;
        }

        return [$start, $end + 1];
    }

    /** @return array<string, true> */
    private static function bigrams(string $text): array
    {
        $gram = [];
        $length = mb_strlen($text, 'UTF-8');

        if ($length === 1) {
            return [$text => true];
        }

        for ($i = 0; $i < $length - 1; $i++) {
            $gram[mb_substr($text, $i, 2, 'UTF-8')] = true;
        }

        return $gram;
    }
}
