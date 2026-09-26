<?php

namespace App\Http\Controllers;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Enums\EventStatus;
use App\Enums\SourceType;
use App\Enums\World;
use App\Models\Character;
use App\Models\Era;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Place;
use App\Models\Source;
use App\Models\Tag;
use App\Services\TimelineConsistencyChecker;
use App\Support\TerraDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TimelineController extends Controller
{
    public function __construct(private readonly TimelineConsistencyChecker $checker)
    {
    }

    /**
     * 主界面：时间线。筛选条件全部由前端驱动，服务端只负责首屏与选项字典。
     *
     * 世界是唯一一个**必须由服务端先确定**的维度：纪元分组、选项字典与异常统计
     * 都要跟着它走，而它们都发生在首屏渲染阶段。
     */
    public function index(Request $request): View
    {
        $world = World::fromRequest($request->string('world')->value());

        return view('timeline.index', [
            'filterOptions' => $this->dictionaries($world),
            'worlds' => World::switcherOptions(),
            'activeWorld' => $world,
            // 只给叶子纪元：父级「时代」是分期标签，选中它只会得到一个空列表。
            // 预载 parent 是为了在视图里按「时代」分组显示（<optgroup>）。
            'eras' => Era::ofWorld($world)->leaves()->with('parent')->ordered()->get(),
            'activeEra' => $request->query('era'),
            'anomalySummary' => $this->checker->openSummary($world),
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
            ->with(['era', 'sources', 'characters', 'factions', 'tags', 'place'])
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
            // 未定位条目单独成组：时间线里「时间未定」的条目数量可观，
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

    /** 筛选字典（按当前世界）。一次性下发，避免每个下拉框都发一次请求。 */
    public function filterOptions(Request $request): array
    {
        return $this->dictionaries(World::fromRequest($request->string('world')->value()));
    }

    /**
     * 选项字典的具体组装。
     *
     * 世界相关的三件事必须跟着世界走：**纪元**（区间不可跨世界比较）、
     * **出处**（版本号与章节属于各自的资料体系）、以及**阵营/人物**
     * （按「在这个世界里出现过」收敛 —— 罗德岛这类跨世界的组织会自然出现在两边，
     * 而整合运动不会出现在塔卫二的筛选里，选择后 0 结果的空筛选没有意义）。
     *
     * 标签保持全局：它是跨世界的横切概念（天灾、源石），刻意不做世界隔离。
     *
     * @return array<string, mixed>
     */
    private function dictionaries(World $world): array
    {
        // 「在这个世界里出现过」——用于阵营与人物这类共享字典的收敛
        $appearsInWorld = fn (Builder $q) => $q->ofWorld($world);

        return [
            'world' => $world->value,
            'worlds' => World::switcherOptions(),
            // 只给叶子纪元：父级「时代」是分期标签而非条目的桶，列进筛选只会给出一个空结果。
            // 预载 parent 是为了让每个纪元带上自己的「时代」，界面据此分组。
            'eras' => Era::ofWorld($world)->leaves()->with('parent')->ordered()->get()
                ->map(fn (Era $e) => $e->toApiArray()),
            // 分期：只取真的有下辖纪元的顶层纪元。顶层而无子级的（如「远古 · 前纪元」）
            // 自己就是一段完整叙事，不该再套一层同名标题。
            'era_periods' => Era::ofWorld($world)->whereHas('children')->ordered()->get()
                ->map(fn (Era $e) => [
                    'id' => $e->id,
                    'slug' => $e->slug,
                    'name' => $e->name,
                    'color' => $e->color,
                    'date_label' => $e->date_label,
                    // 刻度条要在分期边界压一条窄带，因此需要区间
                    'start_index' => $e->start_index,
                    'end_index' => $e->end_index,
                ]),
            'factions' => Faction::whereHas('events', $appearsInWorld)
                ->orderBy('sort_order')->get()->map(fn (Faction $f) => [
                    ...$f->toApiArray(),
                    'depth' => 0,
                ]),
            'characters' => Character::whereHas('events', $appearsInWorld)
                ->orderBy('sort_order')->limit(400)->get()->map(fn (Character $c) => $c->toApiArray()),
            // 地名下拉：只列真的挂着条目的那些 —— 点进去是空的选项只是噪音。
            // 名字带上父级（「维多利亚 · 伦蒂尼姆」）以免同名的聚落难以分辨，
            // 但只展开一层，够用且不必逐条回溯整条链。
            'places' => Place::ofWorld($world)->whereHas('events')->with('parent')
                ->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Place $p) => [
                    'id' => $p->id,
                    'name' => $p->parent ? $p->parent->name.' · '.$p->name : $p->name,
                ]),
            'sources' => Source::ofWorld($world)
                ->orderBy('type')->orderBy('release_order')->get()->map(fn (Source $s) => $s->toApiArray()),
            'tags' => Tag::orderBy('name')->get()->map(fn (Tag $t) => $t->toApiArray()),
            'enums' => [
                'statuses' => EventStatus::options(),
                'precisions' => collect(DatePrecision::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]),
                'confidences' => collect(DateConfidence::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]),
                'source_types' => SourceType::options(),
                'precision_options' => collect(DatePrecision::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all(),
            ],
            // 色带同理：父级时代的色带会整段盖住子纪元，画出来是一块无信息的底色
            'era_bands' => Era::ofWorld($world)->leaves()->ordered()->get()->map(fn (Era $e) => [
                'slug' => $e->slug,
                'name' => $e->name,
                'color' => $e->color,
                'start_index' => $e->start_index,
                'end_index' => $e->end_index,
                // 所属分期：刻度条据此在分期边界画更强的分界
                'period_id' => $e->parent_id,
            ]),
            // 时间轴刻度：按十年给出锚点，供滑杆与刻度标签使用
            'scale' => [
                'unknown_index' => TerraDate::UNKNOWN_INDEX,
                'decade_step' => TerraDate::DAYS_PER_YEAR * 10,
                'calendar' => $world->calendarLabel(),
            ],
        ];
    }

    /** 把请求参数收敛成 scopeFilter 能吃的结构。 */
    private function extractFilters(Request $request): array
    {
        return array_filter([
            // 世界永远存在（缺省泰拉），不参与「空值即忽略」的过滤 —— 它是一条隔离边界
            'world' => World::fromRequest($request->string('world')->value())->value,
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
            'place_id' => $request->integer('place_id') ?: null,
            'character_id' => $request->integer('character_id') ?: null,
            'tag_ids' => array_filter((array) $request->input('tag_ids', [])),
            'only_unanchored' => $request->boolean('only_unanchored'),
            'only_with_anomalies' => $request->boolean('only_with_anomalies'),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
