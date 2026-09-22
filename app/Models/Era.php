<?php

namespace App\Models;

use App\Support\TerraDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'slug', 'subtitle', 'date_label',
    'start_index', 'end_index', 'description', 'color', 'sort_order',
])]
class Era extends Model
{
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
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
            'subtitle' => $this->subtitle,
            'date_label' => $this->date_label,
            'description' => $this->description,
            'color' => $this->color,
            'start_index' => $this->start_index,
            'end_index' => $this->end_index,
            'start_hint' => TerraDate::describeIndex($this->start_index),
        ];
    }
}
