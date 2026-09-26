<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 种族字典（《大地巡旅》第四章「泰拉种族」）。
 *
 * 之所以把原来的自由字符串升级成字典：写错一个种族名不会有任何东西报错，
 * 而「菲林」写成「菲琳」之后，人眼看不出、筛选也筛不全。
 */
#[Fillable(['name', 'slug', 'english', 'description', 'sort_order'])]
class Race extends Model
{
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
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
            'english' => $this->english,
            'description' => $this->description,
        ];
    }
}
