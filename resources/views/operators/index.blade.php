@extends('layouts.app')

@section('title', $world->label().'人员简介 · 源石纪年')
@section('page', 'operators')

@section('content')
    {{-- ============================ 检索与筛选侧栏 ============================ --}}
    {{-- 与时间线同形：固定头（标题 + 重置 + 关键词）+ 滚动筛选组，名单在右侧主栏 --}}
    <aside class="sidebar">
        {{--
            世界切换器。
            与时间线同形、同语言：两个世界的名单是**两套独立的人名单**，
            混在一个列表里读者没法判断某个名字该去哪边找资料。
            同样用服务端渲染的链接而不是 JS 切换 —— 名单、阵营选项与
            外链目标都要在首屏就按正确的世界渲染。
        --}}
        <div class="world-switch">
            @foreach ($worlds as $option)
                @php $isActive = $world->value === $option['value']; @endphp

                <a class="world-switch__item" href="{{ route('operators.index', ['world' => $option['value']]) }}"
                   data-active="{{ $isActive ? '1' : '0' }}"
                   style="--world-accent: {{ $option['accent'] }}"
                   title="{{ $option['description'] }}">
                    <x-icon name="{{ $option['value'] === 'talos' ? 'world-talos' : 'world-terra' }}" class="icon--lg"/>
                    <span class="world-switch__label">{{ $option['label'] }}</span>
                    <span class="world-switch__meta mono">
                        {{ $option['english'] }} ·
                        {{ $isActive ? $counters['total'].' 人' : '' }}
                    </span>
                </a>
            @endforeach
        </div>

        <p class="world-switch__tagline faint small">{{ $world->tagline() }}</p>

        <form method="GET" action="{{ route('operators.index') }}" class="sidebar__form">
            {{-- 世界与人物类型必须随表单回传：改关键词 / 阵营时不能被送回另一个世界、另一类名单 --}}
            <input type="hidden" name="world" value="{{ $world->value }}">
            <input type="hidden" name="kind" value="{{ $filters['kind']->value }}">

            <x-filter-head
                :reset-url="route('operators.index', ['world' => $world->value, 'kind' => $filters['kind']->value])"
                placeholder="搜索名称 / 代号 / 头衔 / 种族"
                :q="$filters['q']"/>

            <div class="sidebar__scroll">
                <details class="filter-group" open>
                    <summary data-en="Kind">人物类型</summary>
                    <div class="filter-group__body">
                        {{--
                            人物分三档，页面上分开列：干员有代号、在役于某支队伍；
                            历史人物有头衔与在位期；剧情人物是当代但非干员的人（组织创办者一类）。
                            混在一个网格里，读者会分不清谁还在名单上 —— 这正是当初把 kind 分出来的原因。
                            档数变了时这里不该是第二个要改的地方：选项由枚举生成，不在视图里再抄一遍标签与取值。
                        --}}
                        <div class="chips">
                            @foreach (\App\Enums\CharacterKind::cases() as $option)
                                <a class="chip" data-clickable="1"
                                   data-active="{{ $filters['kind']->value === $option->value ? '1' : '0' }}"
                                   href="{{ route('operators.index', ['world' => $world->value, 'kind' => $option->value]) }}">
                                    {{ $option->label() }}
                                    {{ str_pad((string) $counters['kinds'][$option->value], 2, '0', STR_PAD_LEFT) }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </details>

                <details class="filter-group" open>
                    <summary data-en="Faction">阵营</summary>
                    <div class="filter-group__body">
                        {{-- 只列「该世界里这类人物确实归属」的阵营：列一个点进去是空列表的选项没有意义。
                             阵营选项跟着当前的类型走：历史人物几乎没有阵营归属，
                             给历史人物列表挂一份「用不到的阵营下拉」只是噪音。 --}}
                        <select name="faction" data-autosubmit>
                            <option value="">全部阵营</option>
                            @foreach ($factions as $faction)
                                <option value="{{ $faction->id }}" @selected($filters['faction'] === $faction->id)>
                                    {{ $faction->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </details>
            </div>
        </form>
    </aside>

    {{-- ============================ 名单主栏 ============================ --}}
    <main class="main">
        <div class="panel">
            <div class="panel__title">
                <span data-en="Operators">{{ $world->label() }}人员简介</span>
                <span class="faint small mono">
                    {{ str_pad((string) $counters['total'], 2, '0', STR_PAD_LEFT) }} PROFILED
                    / {{ str_pad((string) $counters['with_profile'], 2, '0', STR_PAD_LEFT) }} WRITTEN
                </span>
            </div>

            {{-- 把定位与「去哪找完整资料」都写在读者看得见的地方 --}}
            <div class="alert alert--info">
                这里只维护**与时间线相关的一行简介**；{{ $world->label() }}人员的完整档案在
                <a href="{{ rtrim((string) config('timeline.character.wikis.'.$world->value.'.base'), '/') }}"
                   target="_blank" rel="noopener noreferrer">
                    {{ config('timeline.character.wikis.'.$world->value.'.label') }} ↗
                </a>
                —— 每张卡片与详情页都有对应链接。本仓库不复制对方的内容，
                因为一份落后于对方更新的人物档案，恰恰是本项目最无法追溯的东西。
            </div>
        </div>

        @if ($characters->isEmpty())
            <div class="panel">
                <div class="empty">
                    @if ($filters['q'] || $filters['faction'])
                        {{-- 「没搜到」与「根本没有人」要分开说：前者该调筛选，后者该去别的世界看 --}}
                        没有符合筛选条件的{{ $world->label() }}人员。<br>
                        <span class="small">换个关键词，或点侧栏「重置」看全部。</span>
                    @else
                        这里还没有收录{{ $world->label() }}的人员。<br>
                        <span class="small">
                            @if ($counters['other_world'] > 0)
                                另一个世界已经收录了 {{ $counters['other_world'] }} 位，
                                可用侧栏切换器查看。
                            @endif
                        </span>
                    @endif
                </div>
            </div>
        @else
            <div class="operator-grid">
                @foreach ($characters as $character)
                    <article class="operator-card">
                        <header class="operator-card__head">
                            <div class="operator-card__id">
                                @if ($character->avatarUrl())
                                    {{-- 头像是来源维基的头像图：名字就在旁边，alt 留空免得读屏重复 --}}
                                    <img class="operator-card__avatar" src="{{ $character->avatarUrl() }}"
                                         alt="" loading="lazy" width="52" height="52">
                                @endif
                                <div style="min-width:0">
                                    <a class="operator-card__name" href="{{ route('operators.show', $character) }}">
                                        {{ $character->name }}
                                    </a>
                                    @if (filled($character->codename))
                                        <div class="operator-card__code mono">{{ $character->codename }}</div>
                                    @elseif (filled($character->reignLabel()))
                                        {{-- 历史人物没有代号，第二行给头衔与在位期 ——
                                             这正是它们与干员最关键的区别，也是能在时间线上对齐的事实 --}}
                                        <div class="operator-card__code mono">{{ $character->reignLabel() }}</div>
                                    @endif
                                </div>
                            </div>

                            @unless ($character->hasProfile())
                                {{-- 没有人工简介是常态而不是缺陷：如实标出来，读者才知道这一行的分量 --}}
                                <span class="badge badge--muted" title="本仓库尚未为该人物撰写简介">待补</span>
                            @endunless
                        </header>

                        <div class="operator-card__meta">
                            {{-- 归属可以有多个：她是深海猎人，深海猎人又属阿戈尔，两条都列出来。
                                 每个都能点着筛 —— 多记的那一条若点不动，读者读不出它的用处。 --}}
                            @foreach ($character->factions as $faction)
                                <a class="chip" data-clickable="1"
                                   href="{{ route('operators.index', ['world' => $world->value, 'kind' => $filters['kind']->value, 'faction' => $faction->id]) }}">
                                    {{ $faction->name }}
                                </a>
                            @endforeach
                            @if (filled($character->raceName()))
                                {{-- 种族链到资料集：读者看到「菲林」想知道那是什么，这里就该能点过去 --}}
                                <a class="chip" data-clickable="1"
                                   href="{{ route('races.index') }}#race-{{ $character->race?->slug }}">
                                    {{ $character->raceName() }}
                                </a>
                            @endif
                            <span class="chip">{{ $character->events_count }} 条条目</span>
                        </div>

                        <p class="operator-card__profile">{{ $character->profileText() }}</p>

                        <footer class="operator-card__foot">
                            <a class="btn btn--ghost btn--sm" href="{{ route('operators.show', $character) }}">简介</a>
                            @if ($character->showsWikiLink())
                                <a class="btn btn--sm" href="{{ $character->wikiUrl() }}"
                                   target="_blank" rel="noopener noreferrer">
                                    {{ $character->wikiLabel() }} ↗
                                </a>
                            @endif
                        </footer>
                    </article>
                @endforeach
            </div>

            {{ $characters->links() }}
        @endif
    </main>
@endsection
