<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'slug', 'type', 'code', 'chapter',
    'release_order', 'release_date', 'description', 'raw_text',
])]
class Source extends Model
{
    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
        ];
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_source')
            ->withPivot(['chapter', 'stage_code', 'quote', 'quote_offset', 'is_primary', 'sort_order'])
            ->withTimestamps();
    }

    public function aiProposals(): HasMany
    {
        return $this->hasMany(AiProposal::class);
    }

    public function editors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'source_user')->withTimestamps();
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
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'code' => $this->code,
            'chapter' => $this->chapter,
            'release_order' => $this->release_order,
            'has_raw_text' => filled($this->raw_text),
        ];
    }
}
