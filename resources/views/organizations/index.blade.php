@extends('layouts.app')

@section('title', $world->label().'组织 · 源石纪年')
@section('page', 'organizations')

@section('content')
    {{-- ============================ 检索与筛选侧栏 ============================ --}}
    <aside class="sidebar">
        {{--
            世界切换器与时间线、地名页同形。
            组织按世界分列：泰拉与塔卫二各有一批自己的组织（莱茵生命在泰拉、终末地工业在塔卫二），
            混在一页里读者无从判断某个名字属于哪边的历史。
        --}}
        <div class="world-switch">
            @foreach ($worlds as $option)
                @php $isActive = $world->value === $option['value']; @endphp

                <a class="world-switch__item" href="{{ route('organizations.index', ['world' => $option['value']]) }}"
                   data-active="{{ $isActive ? '1' : '0' }}"
                   style="--world-accent: {{ $option['accent'] }}"
                   title="{{ $option['description'] }}">
                    <x-icon name="{{ $option['value'] === 'talos' ? 'world-talos' : 'world-terra' }}" class="icon--lg"/>
                    <span class="world-switch__label">{{ $option['label'] }}</span>
                    <span class="world-switch__meta mono">
                        {{ $option['english'] }} ·
                        {{ $isActive ? $total.' 个组织' : '' }}
                    </span>
                </a>
            @endforeach
        </div>

        <p class="world-switch__tagline faint small">{{ $world->tagline() }}</p>

        <form method="GET" action="{{ route('organizations.index') }}" class="sidebar__form">
            {{-- 世界必须随表单回传：改关键词 / 类型时不能被送回另一个世界 --}}
            <input type="hidden" name="world" value="{{ $world->value }}">

            <x-filter-head :reset-url="route('organizations.index', ['world' => $world->value])"
                           placeholder="搜索名称 / 全称 / 说明"
                           :q="$filters['q']"/>

            <div class="sidebar__scroll">
                <details class="filter-group" open>
                    <summary data-en="Kind">组织类型</summary>
                    <div class="filter-group__body">
                        {{-- 政体与地域不在此列：它们有疆域与层级，在地名页 --}}
                        <select name="kind" data-autosubmit>
                            <option value="">全部类型</option>
                            @foreach ($kindOptions as $option)
                                <option value="{{ $option->value }}" @selected($filters['kind'] === $option->value)>
                                    {{ $option->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </details>
            </div>
        </form>
    </aside>

    {{-- ============================ 组织主栏 ============================ --}}
    <main class="main">
        <div class="panel">
            <div class="panel__title">
                <span data-en="Organizations">{{ $world->label() }}组织</span>
                <span class="faint small mono">CNT {{ str_pad((string) $total, 2, '0', STR_PAD_LEFT) }}</span>
            </div>

            <div class="alert alert--info small">
                这一页收录的是<strong>有成员、有归属、但没有边界</strong>的实体：企业、团体、武装与机构。
                政体（维多利亚）与地域（文明环带）不在这里 —— 它们有疆域与层级，在
                <a href="{{ route('places.index', ['world' => $world->value]) }}">地名</a>页；
                阵营表里两者混在一起，这里按类型把它们分开。
                <br>
                归属世界是<strong>推导</strong>的，而不是另记一列：自身在本世界有条目或人物即算属于本世界，
                内部部门这类自身还没挂上东西的随其上级。因此同一个组织若同时出现在两个世界的历史里，
                会在两页<strong>都</strong>列出（罗德岛协建了终末地工业，就是这种情况），
                而不是被拆成两份。
            </div>

            @if ($groups === [])
                <div class="empty">
                    @if ($filters['q'] || $filters['kind'])
                        {{-- 「没搜到」与「还没收录」要分开说：前者该调筛选，后者是语料的缺口 --}}
                        没有符合筛选条件的组织。<br>
                        <span class="small">换个关键词，或点侧栏「重置」看全部。</span>
                    @else
                        尚未收录{{ $world->label() }}的组织。
                    @endif
                </div>
            @endif
        </div>

        {{--
            与干员简介同一套块式：卡片网格（磨砂玻璃与入场错峰随 .operator-card 自动生效）。
            网格必须在 .panel 之外 —— 面板是不透明底，卡片浮在上面时玻璃透不出背后的光。
            类型分组仍用 section-label：分组本身是这一页的信息，不是列表装饰。
        --}}
        @foreach ($groups as $group)
            <div class="section-label">{{ $group['kind']->label() }}</div>

            <div class="operator-grid">
                @foreach ($group['items'] as $org)
                    <article class="operator-card" id="org-{{ $org->slug }}">
                        <header class="operator-card__head">
                            <div class="operator-card__id">
                                @if ($org->logoUrl())
                                    {{-- 徽记：来源维基的阵营标志。名字就在旁边，alt 留空免得读屏重复 --}}
                                    <img class="emblem emblem--lg" src="{{ $org->logoUrl() }}"
                                         alt="" loading="lazy" width="72" height="72">
                                @endif
                                <div style="min-width:0">
                                    <strong class="operator-card__name">{{ $org->name }}</strong>
                                    @if (filled($org->full_name))
                                        <div class="operator-card__code mono">{{ $org->full_name }}</div>
                                    @endif
                                </div>
                            </div>
                        </header>

                        <div class="chips">
                            @if (filled($org->parent?->name))
                                {{-- 归属只作事实陈述：组织的上级多半是政体，把它套进组织树只会把政体也拖进来 --}}
                                <span class="chip">归属 {{ $org->parent->name }}</span>
                            @endif
                            @if ($org->children->isNotEmpty())
                                <span class="chip">
                                    下属 {{ $org->children->pluck('name')->take(3)->implode('、') }}
                                    @if ($org->children->count() > 3) 等 {{ $org->children->count() }} 个 @endif
                                </span>
                            @endif
                            {{-- 计数一律是**本世界**的：泰拉页上的数字不该把塔卫二的算进去 --}}
                            @if ($org->world_characters_count > 0)
                                <span class="chip">{{ $org->world_characters_count }} 位人物</span>
                            @endif
                            @if ($org->world_events_count > 0)
                                {{-- 与地名页同样的入口：数字要能点，否则读者只能自己去搜 --}}
                                <a class="chip" data-clickable="1"
                                   href="{{ route('timeline.index', ['world' => $world->value, 'faction_id' => $org->id]) }}">
                                    {{ $org->world_events_count }} 条条目
                                </a>
                            @endif
                        </div>

                        @if (filled($org->description))
                            <p class="operator-card__profile operator-card__profile--full">{{ $org->description }}</p>
                        @else
                            {{-- 缺口如实呈现：留白会让人以为「这个组织没什么可说的」 --}}
                            <p class="operator-card__profile operator-card__profile--full operator-card__profile--missing">
                                出处里没有给出这一组织的说明，本仓库只登记了名称与归属。
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endforeach
    </main>
@endsection
