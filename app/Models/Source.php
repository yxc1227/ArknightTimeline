<?php

namespace App\Models;

use App\Enums\SourceType;
use App\Enums\World;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'slug', 'world', 'type', 'code', 'chapter',
    'release_order', 'release_date', 'description', 'raw_text',
])]
class Source extends Model
{
    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'world' => World::class,
        ];
    }

    /**
     * 按世界过滤。
     *
     * 注意这**不是**引用完整性约束：跨世界引用是允许的
     * （一份泰拉设定集完全可以记到塔卫二的事），因此没有「出处必须与事件同世界」这条不变量。
     * 本列只用来回答「这份出处主要讲哪个世界」，供筛选与分组使用。
     */
    public function scopeOfWorld(Builder $query, World|string $world): Builder
    {
        return $query->where('world', $world instanceof World ? $world->value : $world);
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_source')
            ->withPivot(['chapter', 'stage_code', 'quote', 'quote_offset', 'source_line', 'is_annotation', 'is_primary', 'sort_order'])
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
            'world' => $this->world instanceof World ? $this->world->value : World::default()->value,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'code' => $this->code,
            'chapter' => $this->chapter,
            'release_order' => $this->release_order,
            'has_raw_text' => filled($this->raw_text),
        ];
    }
}
