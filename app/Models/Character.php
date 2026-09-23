<?php

namespace App\Models;

use App\Enums\World;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 人物 / 干员。
 *
 * 人物**属于一个世界**：泰拉的干员档案在 PRTS，塔卫二的人员档案在终末地 WIKI，
 * 两份名单的受众与条目体系都不同，混在一个列表里只会让读者不知道该去哪边找。
 * （注意这一点与**阵营**相反：组织可以是跨世界的 —— 罗德岛制药同时也是
 *   终末地工业的组建方之一 —— 而一个人物的档案不会横跨两个世界。）
 */
#[Fillable([
    'name', 'slug', 'world', 'codename', 'faction_id', 'race',
    'description', 'wiki_slug', 'sort_order',
])]
class Character extends Model
{
    protected function casts(): array
    {
        return [
            'world' => World::class,
        ];
    }

    /** 所属世界。空值按缺省世界处理 —— 历史行与未指定世界的写入都落在泰拉。 */
    public function world(): World
    {
        return $this->world instanceof World ? $this->world : World::default();
    }

    /**
     * 按世界过滤。
     *
     * 与 Event / Era / Source 上的同名作用域一样，参数**不可为空**：
     * 遗忘一次不会报错，只会把两个名单混在一起。
     */
    public function scopeOfWorld(Builder $query, World|string $world): Builder
    {
        return $query->where('world', $world instanceof World ? $world->value : $world);
    }

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

    /* ------------------------------------------------------------------ 简介 */

    /** 本仓库是否为他写过简介（而不是只靠结构化字段拼出来的那一句）。 */
    public function hasProfile(): bool
    {
        return filled($this->description);
    }

    /**
     * 简介正文。
     *
     * 没有人工简介时**不编一句出来**，而是退回由结构化字段拼成的「事实卡」：
     * 世界、阵营、种族、参与条目数。这样页面永远是充实的，但读者能一眼看出
     * 哪部分是本仓库写的、哪部分只是字段罗列。
     */
    public function profileText(): string
    {
        if ($this->hasProfile()) {
            return (string) $this->description;
        }

        $facts = array_values(array_filter([
            '所属世界：'.$this->world()->label(),
            filled($this->faction?->name) ? '所属阵营：'.$this->faction->name : null,
            filled($this->race) ? '种族：'.$this->race : null,
        ]));

        // 优先用 withCount 预载的计数：卡片网格一页 24 张，
        // 在模型里再查一次就会变成 24 次查询，而且就发生在最显眼的页面上
        $count = match (true) {
            isset($this->attributes['events_count']) => (int) $this->attributes['events_count'],
            $this->relationLoaded('events') => $this->events->count(),
            default => $this->exists ? $this->events()->count() : 0,
        };

        $tail = $count > 0
            ? "本仓库收录了 {$count} 条与之相关的时间线条目。"
            : '本仓库暂未收录与之相关的时间线条目。';

        return implode('，', array_slice($facts, 0, 3)).'。'.$tail;
    }

    /** 该人物是否需要人工补简介（界面据此显示提示）。 */
    public function needsProfile(): bool
    {
        return ! $this->hasProfile();
    }

    /* ------------------------------------------------------------------ 外链 */

    /** 该世界的维基配置（base / label）。 */
    private function wiki(): array
    {
        return (array) config('timeline.character.wikis.'.$this->world()->value, []);
    }

    /**
     * 该人物在**所属世界**权威维基上的条目地址。
     *
     * 条目名优先取显式配置的 wiki_slug，留空时回落到人物名称 ——
     * 「默认可推导，但必须可覆盖」，因为对方的命名空间不归我们管
     * （同名消歧、别名、转写差异都只能由人指定）。
     */
    public function wikiUrl(): string
    {
        $base = (string) ($this->wiki()['base'] ?? '');
        $title = filled($this->wiki_slug) ? (string) $this->wiki_slug : $this->name;

        return rtrim($base, '/').'/'.rawurlencode($title);
    }

    public function wikiLabel(): string
    {
        return (string) ($this->wiki()['label'] ?? '维基');
    }

    /** 外链的目标站点（用于界面上「这条链接会带你离开本站」的提示）。 */
    public function wikiHost(): string
    {
        return (string) (parse_url((string) ($this->wiki()['base'] ?? ''), PHP_URL_HOST) ?: '');
    }

    /* ------------------------------------------------------------------ 查询 */

    /** 关键词：名称 / 代号 / 种族。 */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // 转义 LIKE 通配符，否则搜一个 % 就会命中全部人物
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $needle = '%'.mb_strtolower($escaped).'%';

        return $query->where(function (Builder $inner) use ($needle) {
            $inner->whereRaw('lower(name) like ?', [$needle])
                ->orWhereRaw('lower(coalesce(codename, \'\')) like ?', [$needle])
                ->orWhereRaw('lower(coalesce(race, \'\')) like ?', [$needle]);
        });
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'codename' => $this->codename,
            'slug' => $this->slug,
            'world' => $this->world()->value,
            'world_label' => $this->world()->label(),
            'faction_id' => $this->faction_id,
            'faction' => $this->relationLoaded('faction') ? $this->faction?->name : null,
            'race' => $this->race,
            'has_profile' => $this->hasProfile(),
            'wiki_url' => $this->wikiUrl(),
            'wiki_label' => $this->wikiLabel(),
            'profile_url' => route('operators.show', $this),
        ];
    }
}
