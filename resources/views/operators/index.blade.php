@extends('layouts.app')

@section('title', $world->label().'人员简介 · 明日方舟时间线')
@section('page', 'operators')

@section('content')
    <main class="main main--wide">
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

            <form method="GET" action="{{ route('operators.index') }}" class="filter-head" style="gap:10px;flex-wrap:wrap">
                {{-- 世界必须随表单一起回传，否则筛选一下就被送回另一个世界 --}}
                <input type="hidden" name="world" value="{{ $world->value }}">

                <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="搜索名称 / 代号 / 种族"
                       style="flex:1 1 220px;min-width:180px">

                <select name="faction" style="flex:0 1 190px" onchange="this.form.submit()">
                    <option value="">全部阵营</option>
                    @foreach ($factions as $faction)
                        <option value="{{ $faction->id }}" @selected($filters['faction'] === $faction->id)>
                            {{ $faction->name }}
                        </option>
                    @endforeach
                </select>

                <button class="btn btn--sm" type="submit">筛选</button>

                @if ($filters['q'] || $filters['faction'])
                    <a class="btn btn--ghost btn--sm" href="{{ route('operators.index', ['world' => $world->value]) }}">重置</a>
                @endif
            </form>
        </div>

        @if ($characters->isEmpty())
            <div class="panel">
                <div class="empty">
                    这里还没有收录{{ $world->label() }}的人员。<br>
                    <span class="small">
                        @if ($counters['other_world'] > 0)
                            另一个世界已经收录了 {{ $counters['other_world'] }} 位，
                            可用上方切换器查看。
                        @elseif ($filters['q'] || $filters['faction'])
                            换个关键词，或点「重置」看全部 {{ $counters['total'] }} 位。
                        @endif
                    </span>
                </div>
            </div>
        @else
            <div class="operator-grid">
                @foreach ($characters as $character)
                    <article class="operator-card">
                        <header class="operator-card__head">
                            <div style="min-width:0">
                                <a class="operator-card__name" href="{{ route('operators.show', $character) }}">
                                    {{ $character->name }}
                                </a>
                                @if (filled($character->codename))
                                    <div class="operator-card__code mono">{{ $character->codename }}</div>
                                @endif
                            </div>

                            @unless ($character->hasProfile())
                                {{-- 没有人工简介是常态而不是缺陷：如实标出来，读者才知道这一行的分量 --}}
                                <span class="badge badge--muted" title="本仓库尚未为该人物撰写简介">待补</span>
                            @endunless
                        </header>

                        <div class="operator-card__meta">
                            @if (filled($character->faction?->name))
                                <span class="chip">{{ $character->faction->name }}</span>
                            @endif
                            @if (filled($character->race))
                                <span class="chip">{{ $character->race }}</span>
                            @endif
                            <span class="chip">{{ $character->events_count }} 条条目</span>
                        </div>

                        <p class="operator-card__profile">{{ $character->profileText() }}</p>

                        <footer class="operator-card__foot">
                            <a class="btn btn--ghost btn--sm" href="{{ route('operators.show', $character) }}">简介</a>
                            <a class="btn btn--sm" href="{{ $character->wikiUrl() }}"
                               target="_blank" rel="noopener noreferrer">
                                {{ $character->wikiLabel() }} ↗
                            </a>
                        </footer>
                    </article>
                @endforeach
            </div>

            {{ $characters->links() }}
        @endif
    </main>
@endsection
