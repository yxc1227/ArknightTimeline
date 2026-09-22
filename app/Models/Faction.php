<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'parent_id', 'full_name', 'color', 'description', 'sort_order'])]
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
        ];
    }
}
