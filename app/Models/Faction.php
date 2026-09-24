<?php

namespace App\Models;

use App\Enums\FactionKind;
use App\Enums\World;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'parent_id', 'full_name', 'color', 'description', 'sort_order', 'kind'])]
class Faction extends Model
{
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_faction')
            ->withPivot(['role', 'note'])
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'kind' => FactionKind::class,
        ];
    }

    /**
     * 只要**组织**（排除政体与地域）。
     *
     * 判据只有一处（`FactionKind::isOrganization()`）—— 政体与地域在地名树里各有节点，
     * 混进组织页会让「维多利亚」与「莱茵生命」看起来是同一类东西。
     */
    public function scopeOrganizations(Builder $query): Builder
    {
        return $query->whereIn('kind', FactionKind::organizationValues());
    }

    /**
     * 该阵营是否出现在某个世界的历史里。
     *
     * 阵营**刻意没有 world 列**：同一个组织可以同时存在于两个世界的历史里
     * （罗德岛协建了终末地工业），按世界切分只会把同一个组织拆成两份。
     * 因此归属是**推导**出来的，而不是第二份真相：
     *
     *  1. 自身在本世界有条目或人物 → 属于本世界；
     *  2. 自身毫无链接（内部部门、委员会这类还没挂上东西的）→ 随最近的、有链接的上级。
     *
     * 第 2 条不是补救而是必需：「精英干员」「医疗部」永远不会自己出现在条目里，
     * 只看第 1 条它们会在两页里都不见 —— 一个组织凭空消失，比归类可疑更难发现。
     */
    public function belongsToWorld(World $world): bool
    {
        $faction = $this;

        // 上限 8 层：层级是人写的，出现环时不至于把进程拖死
        for ($depth = 0; $depth < 8 && $faction !== null; $depth++) {
            if ($faction->hasLinksInWorld($world)) {
                return true;
            }

            $faction = $faction->parent;
        }

        return false;
    }

    /** 自身在本世界是否有条目或人物。 */
    private function hasLinksInWorld(World $world): bool
    {
        return $this->countInWorld('events', $world) > 0
            || $this->countInWorld('characters', $world) > 0;
    }

    /**
     * 本世界的条目数 / 人物数。
     *
     * 优先取 `withCount(['events as world_events_count' => …])` 预载的计数；
     * 没预载就回查一次 —— **不能静默当成 0**：那会让组织在两个世界里都消失，
     * 而「组织少了一个」是页面上最不容易被发现的错。
     */
    private function countInWorld(string $relation, World $world): int
    {
        $key = 'world_'.$relation.'_count';

        if (! array_key_exists($key, $this->getAttributes())) {
            return $this->{$relation}()->where('world', $world->value)->count();
        }

        return (int) $this->getAttribute($key);
    }

    /** 含自身的整棵阵营树 ID（层级筛选时向下包含子阵营）。 */
    public function selfAndDescendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children()->get() as $child) {
            $ids = array_merge($ids, $child->selfAndDescendantIds());
        }

        return $ids;
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
            'full_name' => $this->full_name,
            'color' => $this->color,
            'parent_id' => $this->parent_id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
        ];
    }
}
