<?php

namespace App\Services\Ai;

/**
 * AI 抽取驱动。
 *
 * 抽象成接口的两个理由：
 *  1. 「AI 梳理」必须能离线跑 —— heuristic 驱动不依赖任何外部服务，
 *     保证没有 API Key 的环境下功能依然可用（只是抽取精度低一些）；
 *  2. 模型会换、供应商会变，但**提案 → 校验 → 人工放行**这条流水线不变。
 *
 * 所有驱动都必须遵守同一条契约：只返回候选数据，绝不产生副作用。
 */
interface AiDriver
{
    public function name(): string;

    public function model(): ?string;

    /**
     * 从原始文本中抽取事件候选。
     *
     * @param  string  $rawText  出处原文（剧情文本 / 设定集段落）
     * @param  array{
     *     source?: array<string,mixed>,
     *     era?: array<string,mixed>,
     *     existing_events?: array<int, array{title:string, date:string}>,
     *     characters?: array<int, string>,
     *     factions?: array<int, string>,
     *     instruction?: ?string,
     *     max?: int
     * }  $context
     * @return array<int, array{
     *     title:string, summary:string, date_display:string, location?:?string,
     *     characters?:array, factions?:array, tags?:array,
     *     evidence?:array<int, array{quote:string}>, confidence?:int
     * }>
     */
    public function extract(string $rawText, array $context = []): array;
}
