<?php

namespace App\Models;

use App\Enums\World;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 地名树。
 *
 * 《大地巡旅》第五章给出的政区是三层结构（维多利亚的三个法理王国 → 郡 → 市 / 村镇；
 * 莱塔尼亚的九大区；乌萨斯的省与集团军属地），而条目里的 `location` 一直是自由文本 ——
 * 「维多利亚 · 伦蒂尼姆」与「伦蒂尼姆」在筛选时是两个不同的值。
 *
 * `location` 保留为**展示原文**，`place_id` 是结构化链接：前者永远不会丢，
 * 后者让「按地区层级聚合」成为可能。
 */
#[Fillable(['name', 'slug', 'parent_id', 'kind', 'faction_id', 'world', 'description', 'logo', 'sort_order'])]
class Place extends Model
{
    /** 层级类型（书里出现过的那些）。 */
    public const KINDS = [
        'nation' => '国家',
        'kingdom' => '法理王国',
        'region' => '大区',
        'province' => '省',
        'city' => '移动城市',
        'settlement' => '聚落',
        'landmark' => '地理实体',
    ];

    protected function casts(): array
    {
        return [
            'world' => World::class,
            'aliases' => 'array',
        ];
    }

    /** 与 Era / Source 同款：参数不可为空，否则会出现跨世界的比较。 */
    public function scopeOfWorld(Builder $query, World|string $world): Builder
    {
        return $query->where('world', $world instanceof World ? $world->value : $world);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * 自身 + 全部下辖地名的 ID。
     *
     * 与 `Faction::selfAndDescendantIds()` 同款，理由也一样：层级必须在**筛选**上成立，
     * 否则选了「维多利亚」却看不到挂在「伦蒂尼姆」下的条目，那这棵树就只是装饰。
     *
     * @return list<int>
     */
    public function selfAndDescendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children()->get() as $child) {
            $ids = array_merge($ids, $child->selfAndDescendantIds());
        }

        return $ids;
    }

    /**
     * 参与 `events.location` 匹配的全部写法：本名 + 书里用过的别名。
     *
     * 匹配要按**命中的那个写法的长度**比较，而不是地名本身的长度：
     * 「炎国 · 尚蜀」同时命中「炎国」与其下辖的「尚蜀」，应当落到更具体的那个。
     *
     * @return list<string>
     */
    public function matchTokens(): array
    {
        return array_values(array_filter(
            [$this->name, ...(array) $this->aliases],
            fn ($token) => filled($token),
        ));
    }

    /** 「维多利亚 · 伦蒂尼姆」——从根到本级的路径，用于列表里给出上下文。 */
    public function pathLabel(): string
    {
        $names = [$this->name];
        $cursor = $this->parent;
        $guard = 0;

        while ($cursor !== null && $guard++ < 8) {
            array_unshift($names, $cursor->name);
            $cursor = $cursor->parent;
        }

        return implode(' · ', $names);
    }

    /* ------------------------------------------------------------------ 徽记 */

    /**
     * 徽记地址。
     *
     * 库里存的是相对路径，这里负责变成可用的 URL。绝大多数地名没有徽记 ——
     * 来源维基一共只收了 47 枚徽记图（同一批文件也供阵营使用；能落到地名上的是 20 枚），
     * 而地名有 164 个 —— 为 null 时视图退回纯文字，绝不渲染一张碎图出来。
     */
    public function logoUrl(): ?string
    {
        return filled($this->logo) ? asset((string) $this->logo) : null;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'kind' => $this->kind,
            'kind_label' => self::KINDS[$this->kind] ?? $this->kind,
            'parent_id' => $this->parent_id,
            'faction_id' => $this->faction_id,
            'world' => $this->world instanceof World ? $this->world->value : World::default()->value,
            'logo' => $this->logoUrl(),
        ];
    }
}
