<?php

namespace App\Models;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Enums\EventStatus;
use App\Enums\World;
use App\Support\TerraDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * 时间线事件条目。
 *
 * 协作要点：
 *  - `version` 是乐观锁（compare-and-swap）依据，任何写入都必须携带客户端读到的版本号；
 *  - `status = disputed|deprecated` 时正文冻结，仅接受 annotation 建议；
 *  - 排序完全由 `start_index` + `sort_seq` 派生，不存在链表式「前驱/后继」字段，
 *    因此并发插入不会破坏时间线拓扑。
 */
#[Fillable([
    'world', 'title', 'slug', 'summary', 'details', 'location',
    'date_display', 'start_index', 'end_index', 'date_precision', 'date_confidence',
    'era_id', 'sort_seq', 'parent_event_id', 'caused_by_event_id',
    'status', 'version', 'is_locked', 'created_by', 'updated_by',
    'verified_at', 'verified_by',
])]
class Event extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'world' => World::class,
            'start_index' => 'integer',
            'end_index' => 'integer',
            'sort_seq' => 'integer',
            'version' => 'integer',
            'is_locked' => 'boolean',
            'date_precision' => DatePrecision::class,
            'date_confidence' => DateConfidence::class,
            'status' => EventStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    /** 所属世界。空值按缺省世界处理 —— 历史行与未指定世界的写入都落在泰拉。 */
    public function world(): World
    {
        return $this->world instanceof World ? $this->world : World::default();
    }

    /**
     * 按世界过滤。
     *
     * 不可为空：`start_index` 是没有量纲的整数网格，跨世界的数值比较毫无意义，
     * 而错误的结果看起来完全正常。把世界设成必填参数，
     * 是为了让「忘记按世界隔离」在调用点就写不出来。
     */
    public function scopeOfWorld(Builder $query, World|string $world): Builder
    {
        return $query->where('world', $world instanceof World ? $world->value : $world);
    }

    // ---------------------------------------------------------------- 关系

    public function era(): BelongsTo
    {
        return $this->belongsTo(Era::class);
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'event_source')
            ->withPivot(['chapter', 'stage_code', 'quote', 'quote_offset', 'is_primary', 'sort_order'])
            ->withTimestamps();
    }

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class, 'event_character')
            ->withPivot(['role', 'note'])
            ->withTimestamps();
    }

    public function factions(): BelongsToMany
    {
        return $this->belongsToMany(Faction::class, 'event_faction')
            ->withPivot(['role', 'note'])
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'event_tag')
            ->withPivot(['tagged_by'])
            ->withTimestamps();
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class)->latest();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(EventRevision::class)->latest('version');
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(TimelineAnomaly::class);
    }

    public function openAnomalies(): HasMany
    {
        return $this->anomalies()->where('status', 'open');
    }

    public function editLock(): HasMany
    {
        return $this->hasMany(EventLock::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_event_id');
    }

    public function causedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'caused_by_event_id');
    }

    public function consequences(): HasMany
    {
        return $this->hasMany(self::class, 'caused_by_event_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ---------------------------------------------------------------- 查询作用域

    /**
     * 时间线主排序：可定位条目在前 → 索引 → 同日权重 → 主键。
     *
     * 第一顺位刻意用 CASE 把「时间未定」的条目（start_index = 0）排到最后：
     * 若只按 start_index 排序，它们会插在「纪元前」（负索引）与「泰拉历 1038 年」之间，
     * 既污染时间序列语义，又会在分页时占用首屏位置。
     */
    public function scopeTimelineOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case when date_precision = 'unknown' then 1 else 0 end")
            ->orderBy('start_index')
            ->orderBy('sort_seq')
            ->orderBy('id');
    }

    /**
     * 统一筛选入口。所有维度都可组合，且「时间段」按区间重叠语义匹配，
     * 因此「1097年冬」这类宽区间条目在查询「1097年12月」时也会命中。
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        /*
         * 世界永远排在最前面，且**不允许缺省**。
         *
         * 它不是一个普通的筛选项：`start_index` 只在同一纪年体系内可比，
         * 少了这一条，泰拉历 1097 年（索引 405,702）与塔罗斯历 5 年（索引 1,860）
         * 会被排进同一条序列，分页与年代分布统计随之整体失真 —— 而且不报错。
         */
        $world = World::fromRequest($filters['world'] ?? null);

        return $query
            ->where('world', $world->value)
            ->when(filled($filters['q'] ?? null), function (Builder $q) use ($filters) {
                $term = '%'.Str::lower(trim((string) $filters['q'])).'%';
                $q->where(function (Builder $inner) use ($term) {
                    $inner->whereRaw('lower(title) like ?', [$term])
                        ->orWhereRaw('lower(summary) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(details, \'\')) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(location, \'\')) like ?', [$term])
                        ->orWhereRaw('lower(date_display) like ?', [$term]);
                });
            })
            ->when(filled($filters['era_id'] ?? null), fn (Builder $q) => $q->where('era_id', $filters['era_id']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(filled($filters['confidence'] ?? null), fn (Builder $q) => $q->where('date_confidence', $filters['confidence']))
            ->when(filled($filters['precision'] ?? null), fn (Builder $q) => $q->where('date_precision', $filters['precision']))
            // 区间重叠：条目区间 [start,end] ∩ 查询区间 [from,to] ≠ ∅
            ->when(filled($filters['from_index'] ?? null), fn (Builder $q) => $q->where('end_index', '>=', (int) $filters['from_index']))
            ->when(filled($filters['to_index'] ?? null), fn (Builder $q) => $q->where('start_index', '<=', (int) $filters['to_index']))
            ->when(! empty($filters['only_unanchored']), fn (Builder $q) => $q->where('date_precision', DatePrecision::Unknown->value))
            ->when(filled($filters['source_id'] ?? null), fn (Builder $q) => $q->whereHas(
                'sources',
                fn (Builder $s) => $s->where('sources.id', $filters['source_id'])
            ))
            ->when(filled($filters['source_type'] ?? null), fn (Builder $q) => $q->whereHas(
                'sources',
                fn (Builder $s) => $s->where('sources.type', $filters['source_type'])
            ))
            ->when(filled($filters['faction_id'] ?? null), function (Builder $q) use ($filters) {
                $faction = Faction::find($filters['faction_id']);
                $ids = $faction ? $faction->selfAndDescendantIds() : [(int) $filters['faction_id']];
                $q->whereHas('factions', fn (Builder $f) => $f->whereIn('factions.id', $ids));
            })
            ->when(filled($filters['character_id'] ?? null), fn (Builder $q) => $q->whereHas(
                'characters',
                fn (Builder $c) => $c->where('characters.id', $filters['character_id'])
            ))
            ->when(! empty($filters['tag_ids']), fn (Builder $q) => $q->whereHas(
                'tags',
                fn (Builder $t) => $t->whereIn('tags.id', (array) $filters['tag_ids'])
            ))
            ->when(! empty($filters['only_with_anomalies']), fn (Builder $q) => $q->whereHas(
                'anomalies',
                fn (Builder $a) => $a->where('status', 'open')
            ));
    }

    // ---------------------------------------------------------------- 领域行为

    /** 时间区间是否有交集。 */
    public function overlapsRange(int $from, int $to): bool
    {
        return TerraDate::overlaps($this->start_index, $this->end_index, $from, $to);
    }

    /** 是否为未定位时间的条目（单独泳道展示）。 */
    public function isUnanchored(): bool
    {
        return $this->date_precision === DatePrecision::Unknown
            || $this->start_index === TerraDate::UNKNOWN_INDEX;
    }

    /** 正文是否被冻结（争议/废弃），冻结后只能提交标注建议。 */
    public function isFrozen(): bool
    {
        return $this->status->isFrozen();
    }

    /** 可参与「已校验」标记的前置条件：无阻断级未处置异常。 */
    public function hasBlockingAnomalies(): bool
    {
        return $this->openAnomalies()
            ->whereIn('type', collect(\App\Enums\AnomalyType::cases())
                ->filter(fn ($t) => $t->isBlocking())
                ->map(fn ($t) => $t->value)
                ->all())
            ->exists();
    }

    /** 供前端渲染的完整载荷。 */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'world' => $this->world()->value,
            'world_label' => $this->world()->label(),
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'details' => $this->details,
            'location' => $this->location,
            'date' => [
                'display' => $this->date_display,
                'precision' => $this->date_precision->value,
                'precision_label' => $this->date_precision->label(),
                'confidence' => $this->date_confidence->value,
                'confidence_label' => $this->date_confidence->label(),
                'start_index' => $this->start_index,
                'end_index' => $this->end_index,
                // 回溯文案要带本世界的历法名，塔卫二与泰拉的纪年不是同一套
                'hint' => $this->isUnanchored()
                    ? '时间未定'
                    : TerraDate::describeIndex($this->start_index, $this->world()->calendarLabel()),
            ],
            'era' => $this->relationLoaded('era') && $this->era ? $this->era->toApiArray() : null,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'badge' => $this->status->badgeClass(),
                'frozen' => $this->isFrozen(),
            ],
            'version' => $this->version,
            'is_locked' => $this->is_locked,
            'sort_seq' => $this->sort_seq,
            'sources' => $this->relationLoaded('sources')
                ? $this->sources->map(fn (Source $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'type' => $s->type->value,
                    'type_label' => $s->type->label(),
                    'code' => $s->code,
                    'chapter' => $s->pivot->chapter,
                    'stage_code' => $s->pivot->stage_code,
                    'quote' => $s->pivot->quote,
                    'is_primary' => (bool) $s->pivot->is_primary,
                ])->values()->all()
                : [],
            'characters' => $this->relationLoaded('characters')
                ? $this->characters->map(fn (Character $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'role' => $c->pivot->role,
                ])->values()->all()
                : [],
            'factions' => $this->relationLoaded('factions')
                ? $this->factions->map(fn (Faction $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'color' => $f->color,
                    'role' => $f->pivot->role,
                ])->values()->all()
                : [],
            'tags' => $this->relationLoaded('tags')
                ? $this->tags->map(fn (Tag $t) => $t->toApiArray())->values()->all()
                : [],
            'annotations_count' => $this->annotations_count ?? null,
            'anomalies_count' => $this->anomalies_count ?? null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
