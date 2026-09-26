<?php

namespace App\Models;

use App\Enums\World;
use App\Support\TerraDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'slug', 'world', 'subtitle', 'date_label',
    'start_index', 'end_index', 'description', 'color', 'sort_order', 'parent_id',
])]
class Era extends Model
{
    protected function casts(): array
    {
        return [
            'world' => World::class,
        ];
    }

    /**
     * 按世界过滤。
     *
     * 参数刻意**不可为空**：纪元区间只在同一纪年体系内可比，
     * 任何一条不限定世界的纪元查询都有机会跨世界比较，那是无声的错误。
     * 把它设成必填，等于让编译器替我们检查「每个调用点都想清楚了自己在哪个世界」。
     */
    public function scopeOfWorld(Builder $query, World|string $world): Builder
    {
        return $query->where('world', $world instanceof World ? $world->value : $world);
    }

    /** 本纪元所属世界的纪年名称（泰拉历 / 塔罗斯历）。 */
    public function calendarLabel(): string
    {
        return ($this->world instanceof World ? $this->world : World::default())->calendarLabel();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** 上级「时代」。为空表示它自己就是顶层分期。 */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->ordered();
    }

    /** 是否是一个「时代」（= 有下辖纪元）。时代只是分期标签，条目不该挂在它上面。 */
    public function isPeriod(): bool
    {
        return $this->children()->exists();
    }

    /**
     * 叶子纪元：没有下辖纪元的那些。
     *
     * 条目自动归属、时间轴色带、筛选下拉都必须只用叶子 ——
     * 否则条目会被塞进一个纯标签的时代里，而它的区间是子纪元的并集，
     * 「时代错位」校验会因此永远通过（父的区间总是覆盖得了子的一切）。
     */
    public function scopeLeaves(Builder $query): Builder
    {
        return $query->whereDoesntHave('children');
    }

    public function aiProposals(): HasMany
    {
        return $this->hasMany(AiProposal::class);
    }

    /** 该纪元的时间跨度（天粒度）。 */
    public function spanDays(): int
    {
        return max(0, $this->end_index - $this->start_index);
    }

    /** 某条目是否落在本纪元区间内 —— 时代错位校验的判据。 */
    public function coversIndex(int $index): bool
    {
        return $index >= $this->start_index && $index <= $this->end_index;
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('start_index');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'world' => $this->world instanceof World ? $this->world->value : World::default()->value,
            'subtitle' => $this->subtitle,
            'date_label' => $this->date_label,
            'description' => $this->description,
            'color' => $this->color,
            'start_index' => $this->start_index,
            'end_index' => $this->end_index,
            'parent_id' => $this->parent_id,
            // 上级「时代」：界面按它把纪元收拢成分期。
            // 只在预载了 parent 时给出 —— 少了这道守卫，列表里每一行都会各自回查一次父级。
            'period' => $this->relationLoaded('parent') && $this->parent !== null
                ? [
                    'id' => $this->parent->id,
                    'slug' => $this->parent->slug,
                    'name' => $this->parent->name,
                    'color' => $this->parent->color,
                    'date_label' => $this->parent->date_label,
                ]
                : null,
            // 回溯文案必须用本世界的历法名：在塔卫二的纪元上写「泰拉历」是彻底错误的信息
            'start_hint' => TerraDate::describeIndex($this->start_index, $this->calendarLabel()),
        ];
    }
}
