@extends('layouts.app')

@section('title', $world->label().'地名 · 源石纪年')
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
    @php
        /*
         * 命中关键词的片段包成 <mark class="hit">（与全站既有高亮同一支笔）。
         * 这里是**自己拼 HTML**，所以每一段文本都必须自己过 e() —— 高亮不能成为转义的缺口。
         */
        $hit = function (?string $text) use ($filters): string {
            $needle = (string) $filters['q'];

            if ($text === null || $text === '' || $needle === '') {
                return e((string) $text);
            }

            $length = mb_strlen($needle);
            $out = '';
            $cursor = 0;

            while (($position = mb_stripos($text, $needle, $cursor)) !== false) {
                $out .= e(mb_substr($text, $cursor, $position - $cursor));
                $out .= '<mark class="hit">'.e(mb_substr($text, $position, $length)).'</mark>';
                $cursor = $position + $length;
            }

            return $out.e(mb_substr($text, $cursor));
        };
    @endphp

    <main class="main">
        <div class="panel">
            <div class="panel__title">
                <span data-en="Places">{{ $world->label() }}地名</span>
                <span class="row" style="gap:7px;align-items:center">
                    <span class="faint small mono">CNT {{ str_pad((string) count($places), 2, '0', STR_PAD_LEFT) }}</span>
                    {{-- 层级是这一页的信息主体：给整棵树两个一键位，逐个点开着看太慢 --}}
                    <button type="button" class="btn btn--ghost btn--sm" data-place-expand>全部展开</button>
                    <button type="button" class="btn btn--ghost btn--sm" data-place-collapse>全部折叠</button>
                </span>
            </div>

            <div class="alert alert--info small">
                地名是<strong>有疆域、有上下层级</strong>的实体：条目挂在最具体的那个地名上，
                按上级筛选会把下辖的条目一并带出来。
                政体（维多利亚）与地域（文明环带）也在这里 —— 它们和街区一样回答「在哪里」。
                <br>
                层级与隶属只收录<strong>出处里明确写过</strong>的，不做行政区划的推演；
                没有上级的地名不是没有上级，只是出处里还没读到。
                <br>
                左侧色块是<strong>级别标记</strong>（越宏观越亮，虚线框是地理实体这类非行政层级），
                类型名以 Kind 列的文字为准；点行可选中，地址栏会记下 <code>#place-…</code> 便于分享。
            </div>

            {{--
                快速导航（与时间线同款）：粘在顶栏之下的一条顶层节点目录。
                容器留在这里，条目由 JS 按当前行集生成 —— 筛选是服务端做的，
                行集变了目录跟着变，不会留下跳不到的入口；顶层节点不足三条时整条收起。
            --}}
            <nav class="quick-nav" id="place-nav" aria-label="地名快速导航" hidden></nav>

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
                {{-- 表格包一层横向滚动容器：窄屏上列换行会把缩进层级彻底揉碎，滚动反而可读 --}}
                <div class="table-wrap">
                    <table class="tbl place-tree" data-place-tree>
                        <thead>
                        <tr>
                            <th>Place</th>
                            <th style="width:96px">Kind</th>
                            <th style="width:140px" class="col-polity">Polity</th>
                            <th style="width:84px">Entries</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($places as $node)
                            @php
                                $place = $node['place'];
                                // 行序是深度优先的（父必在子前）：下一个更深 = 有下辖，过滤后同样成立
                                $hasChildren = ($places[$loop->index + 1]['depth'] ?? -1) > $node['depth'];
                                $isHit = isset($matched[$place->id]);
                            @endphp
                            <tr data-place-row data-slug="{{ $place->slug }}"
                                data-depth="{{ $node['depth'] }}"
                                data-parent="{{ $place->parent_id ?? '' }}"
                                @if ($filtered) data-hit="{{ $isHit ? '1' : '0' }}" @endif>
                                {{-- 按深度缩进（--depth × CSS 里的步长）：层级是这一栏存在的理由 --}}
                                <td id="place-{{ $place->slug }}" style="--depth: {{ $node['depth'] }}">
                                    <div class="place-row">
                                        {{-- 折叠钮只给有下辖的行；叶子留同宽占位，名称左沿才对得齐。
                                             字形沿用全站 details 的 `+ / −`（等宽），不是新造一套箭头 --}}
                                        @if ($hasChildren)
                                            <button type="button" class="place-toggle" data-place-toggle
                                                    aria-expanded="true" aria-label="折叠或展开下辖"></button>
                                        @else
                                            {{-- 占位是独立的布局盒子，**不复用** .place-toggle：共用类名会让
                                                 按钮的边框、字形、悬停、手型光标逐条渗过来（踩过一次） --}}
                                            <span class="place-gutter" aria-hidden="true"></span>
                                        @endif

                                        <span class="place-kind" data-kind="{{ $place->kind }}"
                                              title="{{ \App\Models\Place::KINDS[$place->kind] ?? $place->kind }}"></span>

                                        @if ($place->logoUrl())
                                            {{-- 徽记：来源维基的国徽 / 地区标志。名字就在旁边，alt 留空免得读屏重复 --}}
                                            <img class="emblem" src="{{ $place->logoUrl() }}"
                                                 alt="" loading="lazy" width="52" height="52">
                                        @endif

                                        <div class="place-row__main">
                                            @if ($node['depth'] > 0)
                                                <span class="faint mono">└</span>
                                            @endif
                                            <strong>{!! $hit($place->name) !!}</strong>
                                            {{-- 别名是数据而不是匹配规则，因此要摆在读者看得见的地方：
                                                 条目里写「乌萨斯」时，读者得能认出它就是这里说的「乌萨斯帝国」 --}}
                                            @if (filled($place->aliases))
                                                {{-- 高亮直接作用在拼接后的整串上：命中落在哪个别名里都会亮，
                                                     也避免把 @if / @foreach 连着写（Blade 的指令正则要求 @ 前不是单词字符） --}}
                                                <span class="faint small">又称 {!! $hit(implode('、', $place->aliases)) !!}</span>
                                            @endif
                                            @if ($node['depth'] === 0 && $place->children->isNotEmpty() && ! $filtered)
                                                {{-- 这个数字说的是「全部下辖」；树被筛过之后行数对不上它，
                                                     与其让读者对着行数数不齐，不如在筛选中把它收起来 --}}
                                                <span class="faint small">（{{ $place->children->count() }} 个下辖）</span>
                                            @endif

                                            @if (filled($place->description))
                                                {{-- 说明跟着名称走，不再单独补缩进：它已经在被缩进的那一列里了 --}}
                                                <div class="faint small">{!! $hit($place->description) !!}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge">{{ \App\Models\Place::KINDS[$place->kind] ?? $place->kind }}</span></td>
                                <td class="small col-polity">{{ $place->faction?->name ?? '—' }}</td>
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
                </div>
            @endif
        </div>
    </main>
@endsection
