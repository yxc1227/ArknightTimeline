@extends('layouts.app')

@section('title', $world->label().'地名 · 明日方舟时间线')
@section('page', 'places')

@section('content')
    {{-- ============================ 检索与筛选侧栏 ============================ --}}
    <aside class="sidebar">
        {{--
            世界切换器与时间线、干员简介同形。
            地名是四个大类里**唯一分世界**的一类（四号谷地不在泰拉），因此只有这一页需要它。
        --}}
        <div class="world-switch">
            @foreach ($worlds as $option)
                @php $isActive = $world->value === $option['value']; @endphp

                <a class="world-switch__item" href="{{ route('places.index', ['world' => $option['value']]) }}"
                   data-active="{{ $isActive ? '1' : '0' }}"
                   style="--world-accent: {{ $option['accent'] }}"
                   title="{{ $option['description'] }}">
                    <x-icon name="{{ $option['value'] === 'talos' ? 'world-talos' : 'world-terra' }}" class="icon--lg"/>
                    <span class="world-switch__label">{{ $option['label'] }}</span>
                    <span class="world-switch__meta mono">
                        {{ $option['english'] }} ·
                        {{ $isActive ? count($places).' 处地名' : '' }}
                    </span>
                </a>
            @endforeach
        </div>

        <p class="world-switch__tagline faint small">{{ $world->tagline() }}</p>

        <form method="GET" action="{{ route('places.index') }}" class="sidebar__form">
            {{-- 世界必须随表单回传：改关键词 / 类型时不能被送回另一个世界 --}}
            <input type="hidden" name="world" value="{{ $world->value }}">

            <x-filter-head :reset-url="route('places.index', ['world' => $world->value])"
                           placeholder="搜索名称 / 别名 / 说明"
                           :q="$filters['q']"/>

            <div class="sidebar__scroll">
                <details class="filter-group" open>
                    <summary data-en="Kind">地名类型</summary>
                    <div class="filter-group__body">
                        {{-- 别名是数据而不是匹配规则，因此搜索同样认别名：
                             条目里写「乌萨斯」时，读者就该能靠它搜到「乌萨斯帝国」 --}}
                        <select name="kind" data-autosubmit>
                            <option value="">全部类型</option>
                            @foreach ($kinds as $value => $label)
                                <option value="{{ $value }}" @selected($filters['kind'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </details>
            </div>
        </form>
    </aside>

    {{-- ============================ 地名主栏 ============================ --}}
    <main class="main">
        <div class="panel">
            <div class="panel__title">
                <span data-en="Places">{{ $world->label() }}地名</span>
                <span class="faint small mono">CNT {{ str_pad((string) count($places), 2, '0', STR_PAD_LEFT) }}</span>
            </div>

            <div class="alert alert--info small">
                地名是<strong>有疆域、有上下层级</strong>的实体：条目挂在最具体的那个地名上，
                按上级筛选会把下辖的条目一并带出来。
                政体（维多利亚）与地域（文明环带）也在这里 —— 它们和街区一样回答「在哪里」。
                <br>
                层级与隶属只收录<strong>出处里明确写过</strong>的，不做行政区划的推演；
                没有上级的地名不是没有上级，只是出处里还没读到。
            </div>

            @if ($places === [])
                <div class="empty">
                    @if ($filtered)
                        {{-- 「没搜到」与「还没收录」要分开说：前者该调筛选，后者是语料的缺口 --}}
                        没有符合筛选条件的地名。<br>
                        <span class="small">换个关键词，或点侧栏「重置」看全部。</span>
                    @else
                        尚未收录{{ $world->label() }}的地名。
                    @endif
                </div>
            @else
                <table class="tbl">
                    <thead>
                    <tr>
                        <th>Place</th>
                        <th style="width:96px">Kind</th>
                        <th style="width:140px">Polity</th>
                        <th style="width:84px">Entries</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($places as $node)
                        @php $place = $node['place']; @endphp
                        <tr>
                            {{-- 按深度缩进：层级是这一栏存在的理由，压平成一样的缩进就白做了 --}}
                            <td id="place-{{ $place->slug }}">
                                <div style="padding-left:{{ $node['depth'] * 14 }}px">
                                    @if ($node['depth'] > 0)
                                        <span class="faint mono">└</span>
                                    @endif
                                    <strong>{{ $place->name }}</strong>
                                    {{-- 别名是数据而不是匹配规则，因此要摆在读者看得见的地方：
                                         条目里写「乌萨斯」时，读者得能认出它就是这里说的「乌萨斯帝国」 --}}
                                    @if (filled($place->aliases))
                                        <span class="faint small">又称 {{ implode('、', $place->aliases) }}</span>
                                    @endif
                                    @if ($node['depth'] === 0 && $place->children->isNotEmpty())
                                        @unless ($filtered)
                                            {{-- 这个数字说的是「全部下辖」；树被筛过之后行数对不上它，
                                                 与其让读者对着行数数不齐，不如在筛选中把它收起来 --}}
                                            <span class="faint small">（{{ $place->children->count() }} 个下辖）</span>
                                        @endunless
                                    @endif
                                </div>
                                @if (filled($place->description))
                                    <div class="faint small" style="padding-left:{{ $node['depth'] * 14 }}px">
                                        {{ $place->description }}
                                    </div>
                                @endif
                            </td>
                            <td><span class="badge">{{ \App\Models\Place::KINDS[$place->kind] ?? $place->kind }}</span></td>
                            <td class="small">{{ $place->faction?->name ?? '—' }}</td>
                            <td>
                                @if ($place->events_count > 0)
                                    {{-- 入口挂到时间线的地点筛选上，而不是只给一个数字 --
                                         数字本身不可点，读者就只能自己去搜地名，那等于没做结构化 --}}
                                    <a class="mono" href="{{ route('timeline.index', ['world' => $world->value, 'place_id' => $place->id]) }}">
                                        {{ $place->events_count }} 条
                                    </a>
                                @else
                                    <span class="faint mono">0</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </main>
@endsection
