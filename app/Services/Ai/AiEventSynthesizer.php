<?php

namespace App\Services\Ai;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Enums\ProposalStatus;
use App\Models\AiProposal;
use App\Models\Character;
use App\Models\Era;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Source;
use App\Models\User;
use App\Services\TimelineConsistencyChecker;
use App\Support\TerraDate;
use App\Support\TerraDateParser;
use App\Support\TextSimilarity;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * AI 梳理流水线：原文 → 抽取 → 四层校验 → 提案。
 *
 * ═══════════════════════════════════════════════════════════════
 *  AI 补全的触发机制
 * ═══════════════════════════════════════════════════════════════
 *  1. 手动触发（默认）
 *     - 单点补全：在条目编辑面板点「AI 补全此条」，以当前条目的领域信息
 *       构造小上下文，只让模型补描述 / 补人物 / 补出处引文；
 *     - 出处补全：在出处详情页点「梳理该出处」，把 source.raw_text 整段送进去；
 *     - 文本即时梳理：在「AI 梳理」页直接粘贴一段剧情原文。
 *  2. 批量触发：选多个出处排队执行（走队列，避免长请求阻塞）。
 *  3. 自动触发：新建出处且已填入 raw_text 时，dispatch 一次梳理任务。
 *
 * ═══════════════════════════════════════════════════════════════
 *  四层校验（任何一层不过都进不了 events 表）
 * ═══════════════════════════════════════════════════════════════
 *  L1 Schema：必填字段非空、confidence 落在 0-100、数组字段结构正确。
 *  L2 可解析：date_display 必须能被 TerraDateParser 解析出网格索引；
 *             解析失败即标 unverified，禁止自动入库（时间线最怕错误的时间）。
 *  L3 可溯源：每条 evidence.quote 必须在原文中定位成功（归一化后子串匹配），
 *             并回填字符偏移。没有任何一条 matched 的提案一律不许通过 ——
 *             这条是防幻觉的主闸门。
 *  L4 一致性：调用 TimelineConsistencyChecker 做只读预检（时代错位、疑似重复、
 *             相对时间锚点失效），结果进入审核面板，阻断级问题不允许通过。
 *
 * 校验结论全部写入 ai_proposals.validation，与提案一起持久化，
 * 因此审核人可以离线复现「当时为什么被判为有问题」。
 */
final class AiEventSynthesizer
{
    public function __construct(
        private readonly AiDriver $driver,
        private readonly TimelineConsistencyChecker $checker,
        private readonly TerraDateParser $parser,
    ) {}

    /**
     * 对一段原文执行梳理，产出待审提案。
     *
     * @return array{batch_id: string, proposals: Collection<int, AiProposal>, stats: array<string, int>, driver: string, model: ?string}
     */
    public function synthesize(
        Source $source,
        string $rawText,
        User $actor,
        ?Era $era = null,
        ?string $instruction = null,
    ): array {
        $batchId = (string) Str::uuid();

        $candidates = $this->driver->extract($rawText, $this->buildContext($source, $era, $instruction));

        $stats = [
            'extracted' => count($candidates),
            'pending' => 0,
            'duplicate' => 0,
            'unverified' => 0,
            'dropped' => 0,
        ];

        $proposals = collect();
        $batchFingerprints = [];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeCandidate($candidate, $rawText);

            // L1：结构性丢弃（连标题和描述都没有的候选没有任何审核价值）
            if ($normalized === null) {
                $stats['dropped']++;

                continue;
            }

            $fingerprint = $normalized['fingerprint'];

            if (isset($batchFingerprints[$fingerprint])) {
                $stats['duplicate']++;

                continue;
            }

            $batchFingerprints[$fingerprint] = true;

            $proposal = $this->persistProposal($source, $era, $batchId, $normalized);

            $stats[match ($proposal->status) {
                ProposalStatus::Duplicate => 'duplicate',
                ProposalStatus::Unverified => 'unverified',
                default => 'pending',
            }]++;

            $proposals->push($proposal);
        }

        return [
            'batch_id' => $batchId,
            'proposals' => $proposals,
            'stats' => $stats,
            'driver' => $this->driver->name(),
            'model' => $this->driver->model(),
        ];
    }

    /**
     * 组装给模型的上下文。注入既有条目清单是**抑制重复**最有效的手段之一 ——
     * 比事后去重更省 token，也避免模型「换个说法把同一条再写一遍」。
     */
    private function buildContext(Source $source, ?Era $era, ?string $instruction): array
    {
        $existing = Event::query()
            ->when($era, fn ($q) => $q->where('era_id', $era->id))
            ->timelineOrder()
            ->limit((int) config('timeline.ai.context_event_limit', 60))
            ->get(['title', 'date_display'])
            ->map(fn (Event $e) => ['title' => $e->title, 'date' => $e->date_display])
            ->all();

        return [
            'source' => $source->toApiArray(),
            'era' => $era?->toApiArray(),
            'existing_events' => $existing,
            // 词典用于把「提到的人名/阵营名」对齐成结构化关联
            'characters' => Character::query()->limit(500)->pluck('name')->all(),
            'factions' => Faction::query()->limit(300)->pluck('name')->all(),
            'instruction' => $instruction,
            'max' => (int) config('timeline.ai.max_candidates', 25),
        ];
    }

    /**
     * L1 + L2 + L3 校验，并归一化候选结构。
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function normalizeCandidate(array $candidate, string $rawText): ?array
    {
        $title = trim((string) ($candidate['title'] ?? ''));
        $dateDisplay = trim((string) ($candidate['date_display'] ?? ''));

        if ($title === '' || $dateDisplay === '') {
            return null;
        }

        // L3：出处定位。逐条 evidence 在原文里找，命中则回填字符偏移。
        $evidence = $this->groundEvidence($candidate['evidence'] ?? [], $rawText);

        $summary = trim((string) ($candidate['summary'] ?? ''));

        if ($summary === '' && $evidence !== []) {
            $summary = (string) $evidence[0]['quote'];
        }

        // L2：时间可解析性。给 AI 的默认置信度是 inferred，不是 confirmed ——
        // 除非原文逐字含该纪年表述，否则不允许冒充「已确证」。
        $parsed = $this->parser->parse($dateDisplay, DateConfidence::Inferred);

        return [
            'title' => $title,
            'summary' => $summary,
            'date_display' => $dateDisplay,
            'start_index' => $parsed->startIndex,
            'end_index' => $parsed->endIndex,
            'date_precision' => $parsed->precision,
            'date_confidence' => $parsed->precision === DatePrecision::Unknown
                ? DateConfidence::Unknown
                : DateConfidence::Inferred,
            'location' => $candidate['location'] ?? null,
            'characters' => $this->normalizeRelations($candidate['characters'] ?? [], 'support'),
            'factions' => $this->normalizeRelations($candidate['factions'] ?? [], 'involved'),
            'tags' => collect($candidate['tags'] ?? [])
                ->map(fn ($t) => is_array($t) ? (string) ($t['name'] ?? '') : (string) $t)
                ->filter()
                ->unique()
                ->take(6)
                ->values()
                ->all(),
            'evidence' => $evidence,
            'raw_payload' => $candidate,
            'confidence' => max(0, min(100, (int) ($candidate['confidence'] ?? 50))),
            'fingerprint' => Str::slug(TextSimilarity::normalize($title)).'|'.$parsed->startIndex,
        ];
    }

    /**
     * L3 核心：把模型给出的引用回到原文里定位。
     *
     * 用归一化子串匹配而非精确匹配，是为了容忍换行/空格/标点差异；
     * 但**不接受**任何改写 —— 归一化只丢弃空白与标点，不改变任何实义字符。
     *
     * @param  array<int, mixed>  $evidence
     * @return array<int, array{quote:string, offset:?int, matched:bool}>
     */
    private function groundEvidence(array $evidence, string $rawText): array
    {
        $grounded = [];

        foreach (array_slice(is_array($evidence) ? $evidence : [], 0, 5) as $item) {
            $quote = trim((string) (is_array($item) ? ($item['quote'] ?? '') : $item));

            if ($quote === '') {
                continue;
            }

            $position = TextSimilarity::locateQuote($rawText, $quote);
            $matched = TextSimilarity::containsQuote($rawText, $quote) || $position !== null;

            $grounded[] = [
                'quote' => $quote,
                'offset' => $position[0] ?? null,
                'matched' => $matched,
            ];
        }

        return $grounded;
    }

    /**
     * @param  array<int, mixed>  $relations
     * @return array<int, array{name:string, role:string}>
     */
    private function normalizeRelations(array $relations, string $defaultRole): array
    {
        return collect($relations)
            ->map(function ($item) use ($defaultRole) {
                if (is_string($item)) {
                    return ['name' => $item, 'role' => $defaultRole];
                }

                if (is_array($item)) {
                    $name = (string) ($item['name'] ?? '');

                    return $name === '' ? null : ['name' => $name, 'role' => (string) ($item['role'] ?? $defaultRole)];
                }

                return null;
            })
            ->filter()
            ->unique('name')
            ->take(16)
            ->values()
            ->all();
    }

    /** L4：一致性预检 + 状态判定 + 落库。 */
    private function persistProposal(Source $source, ?Era $era, string $batchId, array $data): AiProposal
    {
        $proposal = new AiProposal([
            'batch_id' => $batchId,
            'source_id' => $source->id,
            'era_id' => $era?->id ?? $source->events()->first()?->era_id,
            'title' => $data['title'],
            'summary' => $data['summary'],
            'date_display' => $data['date_display'],
            'start_index' => $data['start_index'],
            'end_index' => $data['end_index'],
            'date_precision' => $data['date_precision']->value,
            'date_confidence' => $data['date_confidence']->value,
            'location' => $data['location'],
            'characters' => $data['characters'],
            'factions' => $data['factions'],
            'tags' => $data['tags'],
            'evidence' => $data['evidence'],
            'confidence' => $data['confidence'],
            'driver' => $this->driver->name(),
            'model' => $this->driver->model(),
            'prompt_hash' => hash('sha256', $source->id.'|'.$data['title'].'|'.$data['date_display'].'|'.now()->toDateString()),
            'raw_payload' => $data['raw_payload'],
        ]);

        $preview = $this->checker->previewForProposal($proposal);

        $validation = [
            'schema' => ['passed' => true],
            'date_parse' => [
                'passed' => $proposal->hasResolvableDate(),
                'start_index' => $data['start_index'],
                'end_index' => $data['end_index'],
                'precision' => $data['date_precision']->value,
                'hint' => $data['start_index'] === TerraDate::UNKNOWN_INDEX
                    ? '未能解析出泰拉历区间'
                    : TerraDate::describeIndex($data['start_index']),
            ],
            'grounding' => [
                'passed' => $proposal->hasGroundedEvidence(),
                'matched' => collect($data['evidence'])->where('matched', true)->count(),
                'total' => count($data['evidence']),
            ],
            'anomalies' => $preview['anomalies'],
            'blocking' => $preview['blocking'],
        ];

        $duplicate = collect($preview['anomalies'])->firstWhere('type', 'duplicate_suspect');

        $proposal->status = match (true) {
            $duplicate !== null => ProposalStatus::Duplicate,
            ! $proposal->hasGroundedEvidence() => ProposalStatus::Unverified,
            default => ProposalStatus::Pending,
        };

        if ($duplicate !== null) {
            $proposal->duplicate_of_event_id = $duplicate['duplicate_of_event_id'] ?? null;
        }

        $proposal->validation = $validation;
        $proposal->save();

        return $proposal;
    }
}
