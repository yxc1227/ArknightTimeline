<?php

namespace App\Services;

use App\Enums\AnomalySeverity;
use App\Enums\AnomalyType;
use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Models\AiProposal;
use App\Enums\World;
use App\Models\Era;
use App\Models\Event;
use App\Models\TimelineAnomaly;
use App\Support\TerraDate;
use App\Support\TerraDateParser;
use App\Support\TextSimilarity;
use Illuminate\Support\Facades\DB;

/**
 * 时间线一致性巡检。
 *
 * 设计立场：一致性**不能**靠编辑时的互斥锁保证，因为绝大多数不一致是语义层的
 * （A 把事件定在 1097 年、B 把它的起因定在 1099 年；两个人都没写错格式，但拼起来是错的）。
 * 因此这里采用「写入后立即体检 + 持续巡检 + 异常收件箱」的收敛式策略：
 *
 *  1. 每条条目写入后同步体检（保证新问题立刻可见）；
 *  2. 定时任务全量巡检（保证历史数据与新增规则同样被覆盖）；
 *  3. 巡检结果是**收敛的**：本轮不再复现的异常自动置为 resolved，
 *     所以收件箱里的每一条都代表「此刻仍然存在的问题」，不会累积噪音。
 */
final class TimelineConsistencyChecker
{
    public function __construct(private readonly TerraDateParser $parser = new TerraDateParser())
    {
    }

    /**
     * 体检单条事件，并把结果同步进 timeline_anomalies。返回本轮产出的异常载荷。
     *
     * @return array<int, array<string, mixed>>
     */
    public function checkEvent(Event $event): array
    {
        $event = $event->fresh(['era', 'causedBy', 'parent']);

        if (! $event) {
            return [];
        }

        $produced = collect($this->detect($event))
            ->map(function (array $a) use ($event) {
                // detect() 内部用枚举表达类型（便于读代码），但指纹与落库需要标量值
                $type = $a['type'] instanceof \BackedEnum ? $a['type']->value : $a['type'];
                $fingerprint = TimelineAnomaly::fingerprintFor($type, $event->id, $a['related_event_id'] ?? null);

                TimelineAnomaly::updateOrCreate(
                    ['fingerprint' => $fingerprint],
                    [
                        'type' => $type,
                        'severity' => $a['severity'],
                        'event_id' => $event->id,
                        'related_event_id' => $a['related_event_id'] ?? null,
                        'message' => $a['message'],
                        'context' => $a['context'] ?? [],
                        'status' => 'open',
                    ],
                );

                return [...$a, 'type' => $type];
            })
            ->all();

        // 收敛：本轮未复现的 open 异常自动关闭。
        $fingerprints = array_map(
            fn (array $a) => TimelineAnomaly::fingerprintFor($a['type'], $event->id, $a['related_event_id'] ?? null),
            $produced,
        );

        TimelineAnomaly::where('event_id', $event->id)
            ->where('status', 'open')
            ->whereNull('ai_proposal_id')
            ->when($fingerprints !== [], fn ($q) => $q->whereNotIn('fingerprint', $fingerprints))
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        return $produced;
    }

    /**
     * 对尚未入库的 AI 提案做**只读**预检，结果写进提案的 validation 字段，不落 anomalies 表。
     *
     * @return array{anomalies: array<int, array<string, mixed>>, blocking: bool}
     */
    public function previewForProposal(AiProposal $proposal): array
    {
        $anomalies = [];

        $unanchored = $proposal->date_precision === DatePrecision::Unknown
            || $proposal->start_index === null
            || $proposal->start_index === TerraDate::UNKNOWN_INDEX;

        // 时代错位
        if (! $unanchored && $proposal->era_id) {
            $era = $proposal->era ?? Era::find($proposal->era_id);
            if ($era && ! $era->coversIndex($proposal->start_index)) {
                $anomalies[] = [
                    'type' => AnomalyType::EraMismatch->value,
                    'blocking' => false,
                    'message' => sprintf(
                        '提案时间 %s 落在纪元「%s」的区间（%s）之外。',
                        TerraDate::describeIndex($proposal->start_index),
                        $era->name,
                        $era->date_label,
                    ),
                ];
            }
        }

        // 相对时间锚点未解析
        if ($proposal->date_precision === DatePrecision::Relative && $unanchored) {
            $anomalies[] = [
                'type' => AnomalyType::UnanchoredRelative->value,
                'blocking' => true,
                'message' => '提案使用相对时间但锚点事件未能解析，入库后会掉进「时间未定」泳道。',
            ];
        }

        // 疑似重复（与既有条目 + 同批次其他提案）
        // 世界取自提案所属出处：跨世界的「相似」不是重复，泰拉与塔卫二的
        // 索引也不可比较，混在一起比对只会产出假阳性
        $duplicate = $this->findDuplicateForCandidate(
            $proposal->source?->world ?? World::default(),
            $proposal->title,
            $proposal->start_index,
            $proposal->end_index,
        );

        if ($duplicate) {
            $anomalies[] = [
                'type' => AnomalyType::DuplicateSuspect->value,
                'blocking' => false,
                'message' => sprintf('与既有条目「%s」高度相似（%s）。', $duplicate->title, $duplicate->date_display),
                'duplicate_of_event_id' => $duplicate->id,
                'similarity' => TextSimilarity::ratio($proposal->title, $duplicate->title),
            ];
        }

        return [
            'anomalies' => $anomalies,
            'blocking' => collect($anomalies)->contains(fn (array $a) => ($a['blocking'] ?? false) === true),
        ];
    }

    /**
     * 单条事件的规则集。返回原始异常描述数组（未落库）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function detect(Event $event): array
    {
        $anomalies = [];
        $tolerance = config('timeline.consistency.source_year_tolerance', 1);
        $duplicateThreshold = config('timeline.consistency.duplicate_similarity', 0.82);
        $overload = config('timeline.consistency.overloaded_day_threshold', 12);

        if ($event->isUnanchored()) {
            if ($event->date_precision === DatePrecision::Relative) {
                $anomalies[] = [
                    'type' => AnomalyType::UnanchoredRelative,
                    'severity' => AnomalySeverity::Error,
                    'message' => '条目声明为相对时间，但锚点未解析，无法定位。',
                ];
            }

            // 未定位条目无法参与因果/时代判定，直接返回，避免误报。
            return $anomalies;
        }

        // 1) 因果倒置：结果早于起因
        if ($event->causedBy && ! $event->causedBy->isUnanchored()
            && $event->start_index < $event->causedBy->start_index) {
            $anomalies[] = [
                'type' => AnomalyType::OrderInversion,
                'severity' => AnomalySeverity::Error,
                'related_event_id' => $event->causedBy->id,
                'message' => sprintf(
                    '本条目（%s）早于其直接起因「%s」（%s）。',
                    $event->date_display,
                    $event->causedBy->title,
                    $event->causedBy->date_display,
                ),
                'context' => ['gap' => $event->causedBy->start_index - $event->start_index],
            ];
        }

        // 2) 子事件早于父事件
        if ($event->parent && ! $event->parent->isUnanchored()
            && $event->start_index < $event->parent->start_index) {
            $anomalies[] = [
                'type' => AnomalyType::ParentOutOfRange,
                'severity' => AnomalySeverity::Error,
                'related_event_id' => $event->parent->id,
                'message' => sprintf('子条目早于其上级条目「%s」（%s）。', $event->parent->title, $event->parent->date_display),
            ];
        }

        // 3) 时代错位
        if ($event->era && ! $event->era->coversIndex($event->start_index)) {
            $anomalies[] = [
                'type' => AnomalyType::EraMismatch,
                'severity' => AnomalySeverity::Warning,
                'message' => sprintf(
                    '条目时间 %s 不在所属纪元「%s」的区间（%s）内。',
                    TerraDate::describeIndex($event->start_index),
                    $event->era->name,
                    $event->era->date_label,
                ),
            ];
        }

        // 4) 出处矛盾：引文里的年份与本条目年份差距过大
        $eventYear = TerraDate::fromIndex($event->start_index)['year'];

        foreach ($event->sources as $source) {
            $quote = $source->pivot->quote;

            if (blank($quote)) {
                continue;
            }

            $parsed = $this->parser->parse($quote, DateConfidence::Inferred);

            if ($parsed->isUnanchored() || $parsed->year === null) {
                continue;
            }

            if (abs($parsed->year - $eventYear) > $tolerance) {
                $anomalies[] = [
                    'type' => AnomalyType::SourceDisagreement,
                    'severity' => AnomalySeverity::Warning,
                    'related_event_id' => null,
                    'message' => sprintf(
                        '出处「%s」的引文中出现 %d 年，与本条目年份 %d 年相差超过 %d 年。',
                        $source->name,
                        $parsed->year,
                        $eventYear,
                        $tolerance,
                    ),
                    'context' => ['source_id' => $source->id, 'quote' => $quote],
                ];
            }
        }

        // 5) 疑似重复：标题高相似且时间区间重叠
        $duplicate = $this->findDuplicate($event, $duplicateThreshold);

        if ($duplicate) {
            $anomalies[] = [
                'type' => AnomalyType::DuplicateSuspect,
                'severity' => AnomalySeverity::Warning,
                'related_event_id' => $duplicate->id,
                'message' => sprintf(
                    '与条目「%s」标题相似度 %.0f%% 且时间区间重叠，疑似重复。',
                    $duplicate->title,
                    TextSimilarity::ratio($event->title, $duplicate->title) * 100,
                ),
            ];
        }

        // 6) 单日过载：同一时间点上堆积过多条目，通常意味着存在拆分错误
        //    必须限定在世界内：索引相同只说明「在各自的纪年里落在同一天」，
        //    泰拉历 1097 年与塔罗斯历 1097 年毫无关系，跨世界计数是纯粹的噪声
        $sameMoment = Event::where('id', '!=', $event->id)
            ->ofWorld($event->world())
            ->where('start_index', $event->start_index)
            ->count();

        if ($sameMoment + 1 > $overload) {
            $anomalies[] = [
                'type' => AnomalyType::OverloadedDay,
                'severity' => AnomalySeverity::Info,
                'message' => sprintf('%s 上已聚集 %d 条条目，建议复核是否应合并或细化时间。', $event->date_display, $sameMoment + 1),
            ];
        }

        return $anomalies;
    }

    /** 在既有条目中寻找疑似重复。 */
    private function findDuplicate(Event $event, float $threshold): ?Event
    {
        return $this->findDuplicateForCandidate(
            $event->world(),
            $event->title,
            $event->start_index,
            $event->end_index,
            $event->id,
            $threshold,
        );
    }

    /**
     * 在既有条目中寻找疑似重复。
     *
     * 世界是**第一个参数**，因为它同时决定了两件事：候选集的范围，
     * 以及「索引相同」是否有意义。泰拉历 1097 年与塔罗斯历 5 年在数值上
     * 天差地别，但两个世界的区间重叠判定本身就不成立 —— 跨世界比对只会产出假阳性。
     */
    private function findDuplicateForCandidate(
        World $world,
        string $title,
        ?int $startIndex,
        ?int $endIndex,
        ?int $excludeId = null,
        ?float $threshold = null,
    ): ?Event {
        $threshold ??= config('timeline.consistency.duplicate_similarity', 0.82);

        if ($title === '' || $startIndex === null || $startIndex === TerraDate::UNKNOWN_INDEX) {
            return null;
        }

        $endIndex ??= $startIndex;

        // 先用低成本的索引条件把候选集压小（时间区间重叠），再做昂贵的相似度计算。
        $candidates = Event::query()
            ->ofWorld($world)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('end_index', '>=', $startIndex - TerraDate::DAYS_PER_YEAR)
            ->where('start_index', '<=', $endIndex + TerraDate::DAYS_PER_YEAR)
            ->limit(300)
            ->get(['id', 'title', 'date_display', 'start_index', 'end_index']);

        $best = null;
        $bestScore = $threshold;

        foreach ($candidates as $candidate) {
            $score = TextSimilarity::ratio($title, $candidate->title);

            if ($score >= $bestScore && TerraDate::overlaps($startIndex, $endIndex, $candidate->start_index, $candidate->end_index)) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * 全量巡检。供定时任务与「一键体检」按钮使用。
     *
     * @return array{checked:int, anomalies:int}
     */
    public function checkAll(?callable $progress = null): array
    {
        $count = 0;
        $total = 0;

        Event::with(['era', 'causedBy', 'parent'])->chunkById(200, function ($events) use (&$count, &$total, $progress) {
            foreach ($events as $event) {
                $produced = $this->checkEvent($event);
                $count++;
                $total += count($produced);
            }

            if ($progress) {
                $progress($count);
            }
        });

        return ['checked' => $count, 'anomalies' => $total];
    }

    /** 全量重算事件索引（例如批量调整了纪元区间之后）。 */
    /**
     * 按纪元区间补全未归属条目的 era_id。
     *
     * **必须逐世界进行**：纪元区间只在同一纪年体系内可比。
     * 泰拉第一个纪元「远古 · 前纪元」的区间是 -186000 ~ 371627，
     * 而塔罗斯历 5 年的索引只有 1860 —— 少了世界条件，
     * 塔卫二的事件会被静默归进泰拉的「远古 · 前纪元」，且不报任何错。
     */
    public function reindexEraAssignments(): int
    {
        // 只用**叶子**纪元。父级「时代」的区间是子纪元的并集，
        // 让它参与分配会把条目塞进一个纯标签里；更糟的是此后「时代错位」再也报不出来 ——
        // 父的区间必然覆盖子纪元中的一切，错位会被父级悄悄兜住。
        $eras = Era::leaves()->ordered()->get();
        $updated = 0;

        foreach ($eras as $era) {
            $updated += Event::whereNull('era_id')
                ->ofWorld($era->world)
                ->whereBetween('start_index', [$era->start_index, $era->end_index])
                ->update(['era_id' => $era->id]);
        }

        return $updated;
    }

    /** 统计当前未处置的异常，用于导航角标。 */
    /**
     * 未处理异常的分级统计。
     *
     * 传入世界时按该世界统计：异常自身不带世界字段，它的世界来自所属条目。
     * 不传则统计全部（导航栏角标用的就是这个口径）。
     *
     * @return array<string, int>
     */
    public function openSummary(?World $world = null): array
    {
        /*
         * 表名一律交给查询构造器去加前缀：
         * 本项目用 DB_PREFIX 给所有表加了前缀（默认 arknight_），
         * 而 selectRaw / groupBy 里的**原生字符串不会被加前缀** ——
         * 写成 `timeline_anomalies.severity` 在 MySQL 上尚能靠别名蒙混，
         * 在 SQLite 上直接报 "no such column"。
         *
         * join 之后必须限定 `timeline_anomalies.status`：events 表也有 status 列，
         * 不加限定就是歧义列（而这个前缀由构造器负责补上）。
         */
        $query = DB::table('timeline_anomalies')
            ->where('timeline_anomalies.status', 'open');

        if ($world !== null) {
            $query->join('events', 'events.id', '=', 'timeline_anomalies.event_id')
                ->where('events.world', $world->value);
        }

        return $query
            // severity 只有异常表有，不会歧义
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->all();
    }
}
