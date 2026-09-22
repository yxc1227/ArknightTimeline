<?php

namespace App\Http\Controllers;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Enums\EventStatus;
use App\Enums\SourceType;
use App\Models\Character;
use App\Models\Era;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Source;
use App\Models\Tag;
use App\Services\TimelineConsistencyChecker;
use App\Support\TerraDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TimelineController extends Controller
{
    public function __construct(private readonly TimelineConsistencyChecker $checker) {}

    /** 主界面：时间线。筛选条件全部由前端驱动，服务端只负责首屏与选项字典。 */
    public function index(Request $request): View
    {
        return view('timeline.index', [
            'filterOptions' => $this->filterOptions(),
            'eras' => Era::ordered()->get(),
            'activeEra' => $request->query('era'),
            'anomalySummary' => $this->checker->openSummary(),
        ]);
    }

    /**
     * 时间线数据源。
     *
     * 排序在数据库完成（start_index, sort_seq, id），不依赖任何应用层排序，
     * 因此分页是稳定的：并发新增条目只会让后续页整体后移，不会出现「漏条 / 重条」。
     */
    public function feed(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);

        // 上限 200 防止误用超长分页把整条时间线一次拉出来；下限 1 以免静默改写调用方的请求
        $perPage = min(200, max(1, (int) $request->integer('per_page', config('timeline.collaboration.per_page', 40))));
        $page = max(1, $request->integer('page', 1));

        $query = Event::query()
            ->with(['era', 'sources', 'characters', 'factions', 'tags'])
            ->withCount(['annotations', 'anomalies'])
            ->filter($filters);

        $total = (clone $query)->count();

        $events = $query->timelineOrder()
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'data' => $events->map(fn (Event $e) => [
                ...$e->toApiArray(),
                'permissions' => [
                    'update' => $request->user()?->can('update', $e) ?? false,
                    'delete' => $request->user()?->can('delete', $e) ?? false,
                    'review' => $request->user()?->can('review', $e) ?? false,
                ],
            ])->values(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $page * $perPage < $total,
                'filters' => $filters,
            ],
            // 未定位条目单独成组：泰拉时间线里「时间未定」的条目数量可观，
            // 强行塞进时间序列会污染排序语义，因此单列一条泳道。
            'unanchored_count' => Event::filter([...$filters, 'only_unanchored' => true])->count(),
            // 年度分布：供顶部缩放条绘制概览。按年聚合而非逐条下发，避免前端拿全量数据。
            'scale' => [
                'buckets' => $this->scaleBuckets($filters),
                'days_per_year' => TerraDate::DAYS_PER_YEAR,
            ],
        ]);
    }

    /**
     * 按「年」聚合条数。start_index = year * 372 + …，因此整除即得年份。
     *
     * 用 floor() 而不是 cast(... as integer)：后者在 MySQL 上不合法
     * （MySQL 的 CAST 目标类型是 SIGNED，没有 INTEGER），而 floor() 在 MySQL 与 SQLite 上语义一致。
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    private function scaleBuckets(array $filters): array
    {
        return Event::query()
            ->filter($filters)
            ->where('start_index', '>', TerraDate::UNKNOWN_INDEX)
            ->selectRaw('floor(start_index / ?) as year_bucket, count(*) as total', [TerraDate::DAYS_PER_YEAR])
            ->groupBy('year_bucket')
            ->orderBy('year_bucket')
            ->pluck('total', 'year_bucket')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** 筛选字典。一次性下发，避免每个下拉框都发一次请求。 */
    public function filterOptions(): array
    {
        return [
            'eras' => Era::ordered()->get()->map(fn (Era $e) => $e->toApiArray()),
            'factions' => Faction::orderBy('sort_order')->get()->map(fn (Faction $f) => [
                ...$f->toApiArray(),
                'depth' => 0,
            ]),
            'characters' => Character::orderBy('sort_order')->limit(400)->get()->map(fn (Character $c) => $c->toApiArray()),
            'sources' => Source::orderBy('type')->orderBy('release_order')->get()->map(fn (Source $s) => $s->toApiArray()),
            'tags' => Tag::orderBy('name')->get()->map(fn (Tag $t) => $t->toApiArray()),
            'enums' => [
                'statuses' => EventStatus::options(),
                'precisions' => collect(DatePrecision::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]),
                'confidences' => collect(DateConfidence::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]),
                'source_types' => SourceType::options(),
                'precision_options' => collect(DatePrecision::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all(),
            ],
            'era_bands' => Era::ordered()->get()->map(fn (Era $e) => [
                'slug' => $e->slug,
                'name' => $e->name,
                'color' => $e->color,
                'start_index' => $e->start_index,
                'end_index' => $e->end_index,
            ]),
            // 时间轴刻度：按十年给出锚点，供滑杆与刻度标签使用
            'scale' => [
                'unknown_index' => TerraDate::UNKNOWN_INDEX,
                'decade_step' => TerraDate::DAYS_PER_YEAR * 10,
            ],
        ];
    }

    /** 把请求参数收敛成 scopeFilter 能吃的结构。 */
    private function extractFilters(Request $request): array
    {
        return array_filter([
            'q' => $request->string('q')->trim()->value(),
            'era_id' => $request->integer('era_id') ?: null,
            'status' => $request->string('status')->value() ?: null,
            'confidence' => $request->string('confidence')->value() ?: null,
            'precision' => $request->string('precision')->value() ?: null,
            'from_index' => $request->has('from_index') ? (int) $request->integer('from_index') : null,
            'to_index' => $request->has('to_index') ? (int) $request->integer('to_index') : null,
            'source_id' => $request->integer('source_id') ?: null,
            'source_type' => $request->string('source_type')->value() ?: null,
            'faction_id' => $request->integer('faction_id') ?: null,
            'character_id' => $request->integer('character_id') ?: null,
            'tag_ids' => array_filter((array) $request->input('tag_ids', [])),
            'only_unanchored' => $request->boolean('only_unanchored'),
            'only_with_anomalies' => $request->boolean('only_with_anomalies'),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
