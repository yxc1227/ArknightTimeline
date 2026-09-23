<?php

namespace App\Support;

/**
 * 引文在出处原文中的命中位置。
 *
 * 同时给出两套坐标，因为它们服务不同的读者：
 *
 *  - `charOffset`：程序用的**字符**偏移（不是字节偏移 —— 中文原文里两者差三倍，
 *    写错会让「一键回跳」跳到完全无关的地方）。存进 `event_source.quote_offset`。
 *  - `line`：人核对用的行号，存进 `event_source.source_line`。
 *
 * 两套坐标都相对 `sources.raw_text`，因此 raw_text 一旦被改写，
 * 既有引用的定位全部失效 —— 这正是出处页上那条 WARN 的意思。
 */
final readonly class CorpusHit
{
    public function __construct(
        public int $charOffset,
        public int $byteOffset,
        public int $line,
        public int $length,
    ) {
    }
}
