<?php

namespace App\Models;

use App\Enums\CharacterKind;
use App\Enums\World;
use App\Support\TerraDate;
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
    'name', 'slug', 'world', 'codename', 'birth_place', 'birth_place_id', 'race_id',
    'kind', 'title', 'reign_start_index', 'reign_end_index',
    'description', 'wiki_slug', 'avatar', 'sort_order',
])]
class Character extends Model
{
    protected function casts(): array
    {
        return [
            'world' => World::class,
            'kind' => CharacterKind::class,
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

    /**
     * 所属阵营，**可以有多个**。
     *
     * 原先只有一列 `faction_id`，装不下「深海猎人（属阿戈尔）」这种两层归属，
     * 于是导入名单时只能取最具体的那个、把另一半丢掉 —— 而那另一半不是冗余，是事实。
     *
     * `sort_order` 由来源的层序决定（小队 → 团体 → 国别），因此越具体越靠前：
     * 界面上第一个就是这个人最常被认作的身份。
     */
    public function factions(): BelongsToMany
    {
        return $this->belongsToMany(Faction::class, 'character_faction')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('factions.id');
    }

    /** 主归属：最具体的那一个。没有归属时为空。 */
    public function primaryFaction(): ?Faction
    {
        return $this->factions->first();
    }

    /**
     * 出身地（地名节点）。
     *
     * 与 `birth_place` 那列并存：这里是能点进去的节点，那里是来源的原文写法。
     */
    public function birthPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'birth_place_id');
    }

    /**
     * 种族（字典）。
     *
     * 原先这里是一个自由字符串，写错字不会有任何东西报错；现在它是外键，
     * 「菲林」写成「菲琳」会在写入时就被字典拒绝。
     */
    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /** 种族名。没有字典条目时为 null —— 宁可缺失，也不要写错。 */
    public function raceName(): ?string
    {
        return $this->race?->name;
    }

    /**
     * 是否为历史人物。
     *
     * 书里满是君主、贵族与学者（伊戈尔、赫尔昏佐伦、科西嘉一世…），
     * 他们与干员是两类实体：干员有代号与干员页，历史人物有头衔与在位期。
     * 不区分的话，干员名单里会混进一堆几百年前的皇帝。
     */
    public function isHistorical(): bool
    {
        return $this->kind === CharacterKind::Historical;
    }

    /**
     * 头衔与在位期的展示文本，如「乌萨斯皇帝 · 在位 969 — 1077」。
     *
     * 区间只有一端时如实说「起于 / 止于」，两端都没有就只给头衔 ——
     * 书里大量在位者的即位年或退位年并未载明，这里绝不补一个看起来合理的数。
     */
    public function reignLabel(): ?string
    {
        $reign = match (true) {
            $this->reign_start_index === null && $this->reign_end_index === null => null,
            $this->reign_start_index === null => '止于 '.TerraDate::describeIndex((int) $this->reign_end_index, '泰拉历'),
            $this->reign_end_index === null => '起于 '.TerraDate::describeIndex($this->reign_start_index, '泰拉历'),
            default => '在位 '.TerraDate::describeIndex($this->reign_start_index, '泰拉历')
                .' — '.TerraDate::describeIndex((int) $this->reign_end_index, '泰拉历'),
        };

        $parts = array_filter([$this->title, $reign]);

        return $parts === [] ? null : implode(' · ', $parts);
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

        // 归属可能有多条，按由具体到笼统列出；只给第一个会丢掉「同时属于阿戈尔」这一半
        $factions = $this->relationLoaded('factions')
            ? $this->factions->pluck('name')->all()
            : [];

        $facts = array_values(array_filter([
            '所属世界：'.$this->world()->label(),
            $factions === [] ? null : '所属阵营：'.implode(' · ', $factions),
            filled($this->raceName()) ? '种族：'.$this->raceName() : null,
            filled($this->birth_place) ? '出身地：'.$this->birth_place : null,
            $this->reignLabel(),
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

    /**
     * 是否给出外部维基链接。
     *
     * 干员一律给：对方维基的条目名默认可从姓名推导，且确实普遍存在。
     * 历史人物则只在**显式指定了条目名**时才给 —— 书里那些君主、贵族在对方站点的
     * 条目名五花八门（本名 / 称号 / 译名），拿本名去猜多半会指向一个不存在的页面。
     */
    public function showsWikiLink(): bool
    {
        return ! $this->isHistorical() || filled($this->wiki_slug);
    }

    /** 外链的目标站点（用于界面上「这条链接会带你离开本站」的提示）。 */
    public function wikiHost(): string
    {
        return (string) (parse_url((string) ($this->wiki()['base'] ?? ''), PHP_URL_HOST) ?: '');
    }

    /* ------------------------------------------------------------------ 头像 */

    /**
     * 头像地址。
     *
     * 库里存的是相对路径，这里负责变成可用的 URL。名单没给图、文件缺失的人物
     * 为 null —— 视图据此退回「首字方块」，绝不渲染一张碎图出来。
     */
    public function avatarUrl(): ?string
    {
        return filled($this->avatar) ? asset((string) $this->avatar) : null;
    }

    /* ------------------------------------------------------------------ 查询 */

    /**
     * 只要干员（排除历史人物与剧情人物）。
     *
     * 「干员简介」这个模块服务的是**能出勤的干员**；其余两档混进同一份名单，
     * 读者会分不清谁还在名单上。他们仍然可以参与时间线筛选 —— 那正是他们最该出现的地方。
     */
    public function scopeOperators(Builder $query): Builder
    {
        return $query->where('kind', CharacterKind::Operator->value);
    }

    /** 关键词：名称 / 代号 / 头衔 / 种族 / 出身地。 */
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
                ->orWhereRaw('lower(coalesce(title, \'\')) like ?', [$needle])
                // 出身地搜的是来源的原文写法（「乌萨斯」「维多利亚」都在这一列里）
                ->orWhereRaw('lower(coalesce(birth_place, \'\')) like ?', [$needle])
                // 种族改走字典：用子查询而不是 join，避免与 withCount 之类的聚合互相干扰
                ->orWhereIn('race_id', Race::query()
                    ->whereRaw('lower(name) like ?', [$needle])
                    ->pluck('id'));
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
            /*
             * 归属：`factions` 是完整的清单（由具体到笼统），
             * `faction` / `faction_id` 保留主归属那一条 —— 键名沿用改造前的那两个，
             * 已有消费方（以及卡片上「按阵营筛选」的链接）不必跟着改。
             */
            'factions' => $this->relationLoaded('factions')
                ? $this->factions->map(fn (Faction $faction) => [
                    'id' => $faction->id,
                    'slug' => $faction->slug,
                    'name' => $faction->name,
                ])->values()->all()
                : [],
            'faction_id' => $this->primaryFaction()?->id,
            'faction' => $this->relationLoaded('factions') ? $this->primaryFaction()?->name : null,
            'birth_place' => $this->birth_place,
            'birth_place_place' => $this->relationLoaded('birthPlace') && $this->birthPlace
                ? [
                    'id' => $this->birthPlace->id,
                    'slug' => $this->birthPlace->slug,
                    'name' => $this->birthPlace->name,
                ]
                : null,
            'race_id' => $this->race_id,
            // 前端一直用 race 这个键拿种族名，保留键名以免接口消费方被无谓地打断
            'race' => $this->raceName(),
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'title' => $this->title,
            'reign_label' => $this->reignLabel(),
            'has_profile' => $this->hasProfile(),
            'wiki_url' => $this->wikiUrl(),
            'wiki_label' => $this->wikiLabel(),
            'profile_url' => route('operators.show', $this),
            'avatar' => $this->avatarUrl(),
        ];
    }
}
