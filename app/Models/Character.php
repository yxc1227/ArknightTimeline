<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug', 'codename', 'faction_id', 'race', 'description', 'sort_order'])]
class Character extends Model
{
    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_character')
            ->withPivot(['role', 'note'])
            ->withTimestamps();
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
            'codename' => $this->codename,
            'slug' => $this->slug,
            'faction_id' => $this->faction_id,
            'faction' => $this->relationLoaded('faction') ? $this->faction?->name : null,
        ];
    }
}
