<?php

namespace App\Services\Ai;

/**
 * 纯规则的离线抽取驱动。
 *
 * 存在的意义不是「假装是 AI」，而是：
 *  1. **兜底**：没有 API Key、断网、配额耗尽时功能不塌陷；
 *  2. **前置过滤**：先按「包含纪年表述的段落」切出候选片段，再交给 LLM 精修，
 *     可以把 token 消耗砍掉一个数量级（时间线语料里大量段落与时间无关）；
 *  3. **可对照的基线**：当 LLM 驱动产出异常时，用它来判定是模型问题还是语料问题。
 *
 * 抽取策略：以「纪年表述」为锚点切窗 —— 这是从真实语料反推出来的规律，
 * 《明日方舟》的剧情与设定集在叙述事件时，几乎总在句子开头给出泰拉历年份。
 */
final class HeuristicAiDriver implements AiDriver
{
    /**
     * 纪年表述锚点。覆盖「泰拉历1096年12月23日」「1097年冬」「泰拉历前500年」等形态。
     *
     * 前缀（泰拉历 / 前）必须包含在匹配内，否则「泰拉历前500年」只会截出「500年」，
     * 既丢失纪元前的语义，也会让后续的标题抽取从错误的位置开始。
     */
    private const DATE_PATTERN = '/(?:泰拉历\s*)?(?:前\s*)?\d{3,4}\s*年(?:\s*\d{1,2}\s*月(?:\s*\d{1,2}\s*日)?)?(?:\s*(?:初春|早春|晚春|初秋|深秋|初|末|中|底|春|夏|秋|冬))?/u';

    /** 候选窗口最大长度（字符）。太长的段落对审核者没有价值。 */
    private const WINDOW_MAX = 220;

    /** 句子分隔符。含多字节标点，比较时必须按长度切片而不是按单字节比较。 */
    private const DELIMITERS = ["\n", '。', '；', '！', '？', '!', '?', ';'];

    public function name(): string
    {
        return 'heuristic';
    }

    public function model(): ?string
    {
        return null;
    }

    public function extract(string $rawText, array $context = []): array
    {
        $max = (int) ($context['max'] ?? config('timeline.ai.max_candidates', 25));
        $characterDict = $context['characters'] ?? [];
        $factionDict = $context['factions'] ?? [];

        $windows = $this->sliceWindows($rawText);
        $candidates = [];

        foreach ($windows as $window) {
            if (count($candidates) >= $max) {
                break;
            }

            $candidate = $this->buildCandidate($window, $rawText, $characterDict, $factionDict);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * 以纪年锚点为中心切出候选窗口，并合并落在同一句内的重复锚点。
     *
     * 全部使用字节偏移运算（preg_match_all 给出的就是字节偏移），
     * 只在写入 quote_offset 时转成字符偏移，避免中文下 mb_* 与 substr 混用错位。
     *
     * @return array<int, array{text:string, byte_start:int, date:string}>
     */
    private function sliceWindows(string $rawText): array
    {
        if (preg_match_all(self::DATE_PATTERN, $rawText, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $windows = [];
        $cursor = -1;

        foreach ($matches[0] as [$date, $byteOffset]) {
            if ($byteOffset <= $cursor) {
                continue; // 已被上一个窗口覆盖
            }

            [$start, $end] = $this->sentenceBounds($rawText, $byteOffset);

            $text = trim(substr($rawText, $start, $end - $start));

            if (mb_strlen($text, 'UTF-8') > self::WINDOW_MAX) {
                $text = mb_substr($text, 0, self::WINDOW_MAX, 'UTF-8').'…';
            }

            // 兜底防线：即便边界计算有问题，也不允许非法字节流进入数据库。
            // 丢弃非法序列而不是替换成 ，后者会污染原文引证的可定位性。
            if (! mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }

            if (mb_strlen($text, 'UTF-8') < 8) {
                continue; // 信息量过低，大概率是目录或表格残片
            }

            // cursor 前移，使同一句内后续锚点被上面的 `$byteOffset <= $cursor` 跳过，
            // 避免一句话产出多条重复提案。
            $windows[] = ['text' => $text, 'byte_start' => $start, 'date' => $date];
            $cursor = $end;
        }

        return $windows;
    }

    /**
     * 找到锚点所在的句子边界：向前到最近的换行/句末标点，向后到最近的句末标点。
     *
     * ⚠️ 两个必须避开的坑（都真实踩过，并且会以「JSON 写入失败」这种远端症状暴露）：
     *
     *  1. 不能用 `in_array($text[$i], $delimiters, true)` 判标点：`$text[$i]` 是**单字节**，
     *     而「。」「；」等是多字节字符，严格比较永远为 false —— 句界判定会全部失效，
     *     窗口一路吃到文末，后半个文档的锚点会被整体跳过。必须按长度切片比较。
     *  2. 返回的是**字节**偏移，而中文一字 3 字节，所以任何非标点处的截断都必须
     *     对齐到 UTF-8 字符边界，否则会产生非法字节序列。
     *
     * @return array{0:int, 1:int} 字节区间
     */
    private function sentenceBounds(string $text, int $offset): array
    {
        $length = strlen($text);
        $start = 0;

        // 向前找起点
        for ($i = $offset - 1; $i >= 0; $i--) {
            $delimiterLength = $this->delimiterLengthAt($text, $i);

            if ($delimiterLength !== null) {
                $start = $i + $delimiterLength;
                break;
            }

            // 兜底：不要向前吞掉过多内容（约 120 个汉字）
            if ($offset - $i > 360) {
                $start = $i;
                break;
            }
        }

        $end = $length;

        // 向后找终点
        for ($i = $offset; $i < $length; $i++) {
            $delimiterLength = $this->delimiterLengthAt($text, $i);

            if ($delimiterLength !== null) {
                $end = $i + $delimiterLength;
                break;
            }
        }

        // 对齐到字符边界（UTF-8 续字节形如 10xxxxxx），掩护上面的 360 字节兜底分支
        while ($start > 0 && $start < $length && (ord($text[$start]) & 0xC0) === 0x80) {
            $start--;
        }

        while ($end < $length && (ord($text[$end]) & 0xC0) === 0x80) {
            $end++;
        }

        return [max(0, $start), $end];
    }

    /** 若 $i 处正好是一个句子分隔符，返回其字节长度，否则返回 null。 */
    private function delimiterLengthAt(string $text, int $index): ?int
    {
        if ($index < 0) {
            return null;
        }

        foreach (self::DELIMITERS as $delimiter) {
            $length = strlen($delimiter);

            if (substr($text, $index, $length) === $delimiter) {
                return $length;
            }
        }

        return null;
    }

    /**
     * @param  array{text:string, byte_start:int, date:string}  $window
     * @param  array<int, string>  $characterDict
     * @param  array<int, string>  $factionDict
     */
    private function buildCandidate(array $window, string $rawText, array $characterDict, array $factionDict): ?array
    {
        $text = $window['text'];
        $title = $this->extractTitle($text, $window['date']);

        if ($title === '') {
            return null;
        }

        $confidence = $this->scoreConfidence($window['date'], $text);

        return [
            'title' => $title,
            'summary' => $this->cleanSummary($text),
            'date_display' => $window['date'],
            'location' => $this->guessLocation($text),
            'characters' => $this->matchDictionary($text, $characterDict, 'support'),
            'factions' => $this->matchDictionary($text, $factionDict, 'involved'),
            'tags' => $this->guessTags($text),
            'evidence' => [[
                'quote' => $text,
                'offset' => mb_strlen(substr($rawText, 0, $window['byte_start']), 'UTF-8'),
            ]],
            'confidence' => $confidence,
        ];
    }

    /**
     * 标题抽取，按可靠性降序尝试：
     *   1. 书名号 / 直角引号显式标注的标题：『切尔诺伯格事变』
     *   2. 纪年之后**第一个非空分句**：1096年12月23日，切尔诺伯格事变爆发 → 「切尔诺伯格事变爆发」
     *   3. 纪年之前最靠近纪年的分句（部分语料先写事件名再写时间）
     *   4. 退化为窗口首句截断
     *
     * 注意第 2 条必须跳过空分句：语料普遍写成「纪年 + ，+ 主谓短语」，
     * 直接取 split()[0] 会拿到空串，导致标题退化成「泰拉历」这种前缀。
     */
    private function extractTitle(string $text, string $date): string
    {
        if (preg_match('/[『「《【]([^』」》】]{2,40})[』」》】]/u', $text, $m)) {
            return trim($m[1]);
        }

        $after = $this->substringAfter($text, $date);

        if ($after !== '') {
            foreach (preg_split('/[，,。；;：:、]/u', $after) ?: [] as $clause) {
                $clause = trim($clause);
                $length = mb_strlen($clause, 'UTF-8');

                if ($length >= 3 && $length <= 32) {
                    return $this->tidyTitle($clause);
                }

                if ($length > 32) {
                    break; // 首个实体分句就过长，说明这里不是标题式表述
                }
            }
        }

        $before = $this->substringBefore($text, $date);

        if (trim($before) !== '') {
            $parts = preg_split('/[，,。；;：:、]/u', $before) ?: [];
            $last = trim((string) end($parts));
            $length = mb_strlen($last, 'UTF-8');

            if ($length >= 3 && $length <= 32) {
                // 去掉「泰拉历」「纪元」这类纯纪年前缀，它们不是事件名
                $cleaned = preg_replace('/^(?:泰拉历|泰拉|纪元|元)?\s*前?$/u', '', $last) ?? $last;

                if (trim($cleaned) !== '') {
                    return $this->tidyTitle($cleaned);
                }
            }
        }

        return $this->tidyTitle(mb_substr($text, 0, 24, 'UTF-8'));
    }

    /**
     * 去掉标题两端的空白与残留标点。
     *
     * ⚠️ 必须用正则而不是 trim($str, $charlist)：trim 的 charlist 是**按字节**匹配的，
     * 把「，。、」这类多字节字符写进 charlist 会按单字节裁剪，
     * 从而把合法的中文字符削掉一半，产生非法 UTF-8 并最终导致 JSON 写入失败。
     */
    private function tidyTitle(string $title): string
    {
        $title = preg_replace('/^[\s\p{P}\p{S}]+|[\s\p{P}\p{S}]+$/u', '', $title) ?? $title;

        return trim($title);
    }

    private function cleanSummary(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** 从「地点」词表中粗提发生地。 */
    private function guessLocation(string $text): ?string
    {
        $pattern = '/([\p{Han}]{2,12}(?:城|市|区|镇|村|领|郡|堡|站|岛|港|关|要塞|平原|峡谷|矿区|研究所|实验室|舰|号))/u';

        if (preg_match_all($pattern, $text, $m) && $m[1] !== []) {
            return $m[1][0];
        }

        return null;
    }

    /**
     * @param  array<int, string>  $dictionary
     * @return array<int, array{name:string, role:string}>
     */
    private function matchDictionary(string $text, array $dictionary, string $defaultRole): array
    {
        $hits = [];

        foreach ($dictionary as $name) {
            if ($name === '' || ! str_contains($text, (string) $name)) {
                continue;
            }

            $hits[] = ['name' => (string) $name, 'role' => $defaultRole];
        }

        return array_slice($hits, 0, 12);
    }

    /** @return array<int, string> */
    private function guessTags(string $text): array
    {
        $lexicon = ['战役', '战争', '条约', '政变', '起义', '叛乱', '灾害', '灾难', '事故', '谈判', '会议', '实验', '事故', '屠杀', '戒严'];
        $tags = [];

        foreach (array_unique($lexicon) as $word) {
            if (str_contains($text, $word)) {
                $tags[] = $word;
            }
        }

        return array_slice($tags, 0, 4);
    }

    /** 纪年表述越具体，置信度越高。 */
    private function scoreConfidence(string $date, string $text): int
    {
        $score = match (true) {
            (bool) preg_match('/\d{1,2}\s*日/u', $date) => 72,
            (bool) preg_match('/\d{1,2}\s*月/u', $date) => 64,
            (bool) preg_match('/(春|夏|秋|冬|初|末|中|底)/u', $date) => 55,
            default => 48,
        };

        // 有明确因果/结果动词的句子，作为「事件」的成色更好
        if (preg_match('/(爆发|发生|宣告|签署|结束|开始|沦陷|解体|成立|灭亡|战败|胜利)/u', $text)) {
            $score += 6;
        }

        // 窗口过长说明信息稀薄
        if (mb_strlen($text, 'UTF-8') > 160) {
            $score -= 8;
        }

        return max(10, min(95, $score));
    }

    private function substringAfter(string $text, string $needle): string
    {
        $pos = mb_strpos($text, $needle, 0, 'UTF-8');

        if ($pos === false) {
            return '';
        }

        return mb_substr($text, $pos + mb_strlen($needle, 'UTF-8'), null, 'UTF-8');
    }

    private function substringBefore(string $text, string $needle): string
    {
        $pos = mb_strpos($text, $needle, 0, 'UTF-8');

        return $pos === false ? '' : mb_substr($text, 0, $pos, 'UTF-8');
    }
}
