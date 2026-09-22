<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 兼容 OpenAI Chat Completions 协议的驱动。
 *
 * 只要服务商暴露 /chat/completions（OpenAI、DeepSeek、通义、本地 vLLM/Ollama 的兼容层都算），
 * 换 base_url 即可切换，无需改代码。
 *
 * 提示词设计要点（这些约束是为了配合下游的四层校验）：
 *  1. 强制输出 JSON 数组，不允许任何解释性文字 —— 降低解析失败率；
 *  2. 强制每条候选给出 evidence.quote，且必须是**原文的逐字片段** ——
 *     这是「可定位出处」校验的前置条件，模型不遵守就会被判 unverified；
 *  3. 注入「该出处已收录的条目清单」，从源头抑制重复造条目；
 *  4. 明确告知「不确定就给低 confidence」，让模型把不确定性外化，
 *     而不是编一个看起来合理的日期。
 */
final class OpenAiCompatibleDriver implements AiDriver
{
    public function __construct(
        private readonly string $endpoint,
        private readonly ?string $apiKey,
        private readonly string $modelName,
        private readonly int $timeout = 60,
    ) {}

    public function name(): string
    {
        return 'openai-compatible';
    }

    public function model(): ?string
    {
        return $this->modelName;
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    public function extract(string $rawText, array $context = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('未配置 TIMELINE_AI_KEY，无法使用 openai-compatible 驱动。');
        }

        $max = (int) ($context['max'] ?? config('timeline.ai.max_candidates', 25));

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->acceptJson()
            ->post($this->endpoint, [
                'model' => $this->modelName,
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->userPrompt($rawText, $context, $max)],
                ],
            ]);

        if ($response->failed()) {
            Log::warning('AI 抽取请求失败', ['status' => $response->status(), 'body' => $response->body()]);

            throw new RuntimeException('AI 服务返回错误：HTTP '.$response->status());
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');
        $decoded = $this->decodeJson($content);

        $events = $decoded['events'] ?? $decoded;

        if (! is_array($events)) {
            return [];
        }

        return collect($events)
            ->filter(fn ($e) => is_array($e) && filled($e['title'] ?? null))
            ->map(fn (array $e) => [
                'title' => (string) $e['title'],
                'summary' => (string) ($e['summary'] ?? ''),
                'date_display' => (string) ($e['date_display'] ?? '时间未定'),
                'location' => $e['location'] ?? null,
                'characters' => $e['characters'] ?? [],
                'factions' => $e['factions'] ?? [],
                'tags' => $e['tags'] ?? [],
                'evidence' => collect($e['evidence'] ?? [])
                    ->map(fn ($q) => is_array($q) ? ['quote' => (string) ($q['quote'] ?? '')] : ['quote' => (string) $q])
                    ->filter(fn ($q) => $q['quote'] !== '')
                    ->values()
                    ->all(),
                'confidence' => (int) ($e['confidence'] ?? 50),
            ])
            ->take($max)
            ->values()
            ->all();
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
你是《明日方舟》（Arknights）世界观考据助手，专职把剧本文本与设定集段落整理成结构化的时间线事件。

硬性规则：
1. 只输出一个 JSON 对象，形如 {"events": [...]}，不要输出任何解释、前言、Markdown 代码围栏。
2. 每条事件必须包含字段：title、summary、date_display、location、characters、factions、tags、evidence、confidence。
3. date_display 必须使用原文中的游戏内纪年表述（例如「泰拉历1096年12月23日」「1097年冬」）。原文没有明确时间时，写「时间未定」，绝不猜测。
4. evidence 必须是从输入原文中逐字复制的片段（不允许改写、不允许翻译、不允许拼接），用于人工核验。至少给一条。
5. 不要输出原文中不存在的事件。不要根据常识补充原文未提及的内容。
6. confidence 为 0-100 的整数：时间与描述都能在原文逐字找到依据则给 80 以上；时间靠推断给 40-60；描述依赖外部知识给 30 以下。
7. 如果输入的「已收录条目」中已存在同一事件，跳过它，不要重复输出。
8. 语言使用简体中文。

字段格式：
- characters: [{"name": "人物名", "role": "protagonist|support|mentioned"}]
- factions:   [{"name": "阵营名", "role": "instigator|involved|victim"}]
- tags:       ["标签字符串", ...]
- evidence:   [{"quote": "原文逐字片段"}, ...]
PROMPT;
    }

    private function userPrompt(string $rawText, array $context, int $max): string
    {
        $source = $context['source'] ?? [];
        $era = $context['era'] ?? [];
        $existing = $context['existing_events'] ?? [];
        $instruction = $context['instruction'] ?? null;

        $existingList = collect($existing)
            ->take((int) config('timeline.ai.context_event_limit', 60))
            ->map(fn (array $e) => sprintf('- %s（%s）', $e['title'] ?? '', $e['date'] ?? ''))
            ->implode("\n");

        $parts = [
            '【出处】'.($source['name'] ?? '未指定').' / '.($source['type_label'] ?? '未指定类型').' / '.($source['code'] ?? '无编号'),
            '【目标纪元】'.($era['name'] ?? '未指定').'（'.($era['date_label'] ?? '区间未知').'）',
            $existingList !== '' ? "【已收录条目（请勿重复）】\n".$existingList : '【已收录条目】无',
            $instruction ? '【额外要求】'.$instruction : '',
            '【最多产出】'.$max.' 条',
            "【原始文本】\n<<<TEXT\n".$rawText."\nTEXT",
            '请输出 JSON：{"events":[...]}',
        ];

        return implode("\n\n", array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * 容错解析：模型偶尔会裹上代码围栏或在 JSON 前后加解释，
     * 这里做尽力而为的提取，解析不了就返回空数组（而不是抛异常），
     * 让上层把「模型没产出有效结果」当成正常业务分支处理。
     */
    private function decodeJson(string $content): array
    {
        $content = trim($content);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $content, $m)) {
            $content = $m[1];
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // 尝试截取第一个 { 到最后一个 }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        Log::warning('AI 返回内容无法解析为 JSON', ['preview' => mb_substr($content, 0, 300)]);

        return [];
    }
}
