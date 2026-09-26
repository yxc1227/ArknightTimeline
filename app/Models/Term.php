<?php

namespace App\Models;

use App\Enums\World;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 词条：书里给出专门解释的术语与专名。
 *
 * 金律乐章、帝政主义、圣愚、提卡兹、结晶时代……这些概念在《大地巡旅》里都有成段的解释，
 * 而此前它们只能躺在某条事件的详述里 —— 读者看见「金律乐章」却无处可查。
 *
 * **与世界的关系与阵营、种族不同**：词条是一批释义，而释义天然带着视角 ——
 * 「金律乐章」是莱塔尼亚的立国宪章，「协议」是塔卫二上的失落之物。
 * 因此 `world` 可以区分，且为空时表示**两个世界通用**（「源石」这类两边都成立的概念）。
 */
#[Fillable(['name', 'slug', 'category', 'definition', 'origin', 'world', 'sort_order'])]
class Term extends Model
{
    public const CATEGORIES = [
        'term' => '术语',
        'concept' => '概念',
        'object' => '器物',
        'proper' => '专名',
    ];

    protected function casts(): array
    {
        return [
            'world' => World::class,
        ];
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * 某个世界看得到的词条：本世界专属的 **加上**通用的。
     *
     * 通用词条必须在两页都出现 —— 它本来就两边都适用，
     * 只在一页给出会让人以为那是某一方独有的东西。
     */
    public function scopeVisibleIn(Builder $query, World $world): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('world', $world->value)
            ->orWhereNull('world'));
    }

    /** 是否为两个世界通用的词条（界面上要标出来，否则读者会以为它在另一页缺失了）。 */
    public function isShared(): bool
    {
        return $this->world === null;
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
            'category' => $this->category,
            'category_label' => self::CATEGORIES[$this->category] ?? $this->category,
            'definition' => $this->definition,
            'origin' => $this->origin,
            'world' => $this->world?->value,
            'world_label' => $this->world?->label() ?? '通用',
        ];
    }
}
