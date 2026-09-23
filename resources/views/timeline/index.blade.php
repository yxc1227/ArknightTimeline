@extends('layouts.app')

@section('title', '时间线 · 泰拉统一事件年表')
@section('page', 'timeline')

@php
    $statuses = \App\Enums\EventStatus::options();
    $confidences = collect(\App\Enums\DateConfidence::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]);
    $sourceTypes = \App\Enums\SourceType::options();
@endphp

@section('content')
    {{-- ============================ 筛选侧栏 ============================ --}}
    <aside class="sidebar" id="filters">
        {{-- 头部固定：标题、重置、关键词输入不随筛选组滚动 --}}
        <div class="sidebar__head">
            <div class="filter-head">
                <strong data-en="Filter">检索与筛选</strong>
                <button class="btn btn--ghost btn--sm" id="filters-reset">重置</button>
            </div>

            <div class="field" style="margin-bottom:0">
                <input type="search" data-filter="q" placeholder="搜索标题 / 描述 / 纪年 / 地点">
            </div>
        </div>

        {{-- 中间是唯一的滚动区：桌面端全站只有这一条筛选滚动条 --}}
        <div class="sidebar__scroll">
            <details class="filter-group" open>
                <summary data-en="Scale">时间轴</summary>
                <div class="filter-group__body">
                    <canvas id="scrubber" title="拖动选择时间范围"></canvas>
                    <div class="filter-head" style="margin-top:6px">
                        <span class="mono small" id="range-label">全时段</span>
                        <button class="btn btn--ghost btn--sm" id="clear-range">清除</button>
                    </div>
                    <label class="check" style="margin-top:8px">
                        <input type="checkbox" data-filter="only_unanchored"> 只看「时间未定」条目
                    </label>
                </div>
            </details>

            <details class="filter-group">
                <summary data-en="Era">纪元</summary>
                <div class="filter-group__body">
                    <select data-filter="era_id">
                        <option value="">全部纪元</option>
                        @foreach ($eras as $era)
                            <option value="{{ $era->id }}" @selected($activeEra === $era->slug)>{{ $era->name }}</option>
                        @endforeach
                    </select>
                </div>
            </details>

            <details class="filter-group">
                <summary data-en="Source">出处</summary>
                <div class="filter-group__body">
                    <div class="field">
                        <select data-filter="source_type">
                            <option value="">全部载体类型</option>
                            @foreach ($sourceTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <select data-filter="source_id">
                            <option value="">全部出处</option>
                            @foreach ($filterOptions['sources'] as $source)
                                <option value="{{ $source['id'] }}">{{ $source['name'] }}{{ $source['code'] ? ' · '.$source['code'] : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </details>

            <details class="filter-group">
                <summary data-en="Faction / Character">阵营与人物</summary>
                <div class="filter-group__body">
                    <div class="field">
                        <select data-filter="faction_id">
                            <option value="">全部阵营</option>
                            @foreach ($filterOptions['factions'] as $faction)
                                <option value="{{ $faction['id'] }}">{{ $faction['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <select data-filter="character_id">
                            <option value="">全部人物</option>
                            @foreach ($filterOptions['characters'] as $character)
                                <option value="{{ $character['id'] }}">{{ $character['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </details>

            <details class="filter-group">
                <summary data-en="Tag">标签</summary>
                <div class="filter-group__body">
                    <div class="tag-picker">
                        @foreach ($filterOptions['tags'] as $tag)
                            <label class="check">
                                <input type="checkbox" data-filter="tag_ids" value="{{ $tag['id'] }}">
                                {{ $tag['name'] }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </details>

            <details class="filter-group">
                <summary data-en="Status">条目状态</summary>
                <div class="filter-group__body">
                    <div class="field">
                        <select data-filter="status">
                            <option value="">全部状态</option>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <select data-filter="confidence">
                            <option value="">全部可信度</option>
                            @foreach ($confidences as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="check">
                        <input type="checkbox" data-filter="only_with_anomalies"> 只看有一致性告警的条目
                    </label>
                </div>
            </details>
        </div>

        {{-- 底部固定：新增入口始终可见，不会被滚走 --}}
        @auth
            <div class="sidebar__foot">
                <button class="btn btn--primary" id="new-event" style="width:100%">+ 新增事件条目</button>
            </div>
        @endauth
    </aside>

    {{-- ============================ 时间线主体 ============================ --}}
    <main class="main">
        <div class="panel">
            <div class="panel__title">
                <span data-en="Timeline">统一事件时间表</span>
                <span class="stats-line" id="stats-line"><span>LOADING ......</span></span>
            </div>
            <div class="faint small">
                排序依据是游戏内纪元时间的归一化区间；粒度不足时（如「1097年冬」）以区间形式参与排序与检索，
                不会伪造精确日期。未定位时间的条目单独归入「时间未定」泳道。
            </div>
        </div>

        <div id="timeline-host"></div>
    </main>

    {{-- ============================ 详情抽屉 ============================ --}}
    <div class="drawer-mask" id="drawer-mask"></div>
    <aside class="drawer" id="drawer">
        <div class="drawer__head">
            <div style="flex:1;min-width:0">
                <h2 class="drawer__title" id="drawer-title">—</h2>
                <div class="drawer__date" id="drawer-date"></div>
            </div>
            <button class="btn btn--ghost btn--icon" id="detail-close" title="关闭">✕</button>
        </div>
        <div class="drawer__body" id="drawer-body"></div>
        <div class="drawer__foot" id="drawer-foot">
            <button class="btn btn--ghost" id="drawer-close">关闭</button>
        </div>
    </aside>

    {{-- ============================ 冲突合并模态 ============================ --}}
    <div class="modal-mask" id="conflict-mask">
        <div class="modal">
            <div class="modal__head" data-en="Conflict">编辑冲突 · 字段级合并</div>
            <div class="modal__body" id="conflict-body"></div>
            <div class="modal__foot">
                <button class="btn" id="conflict-cancel">稍后再说</button>
                <button class="btn btn--primary" id="conflict-submit">合并并保存</button>
            </div>
        </div>
    </div>
@endsection

@push('boot')
    <script>
        Object.assign(window.APP, {
            options: @json($filterOptions),
            anomalySummary: @json($anomalySummary)
        });
    </script>
@endpush
