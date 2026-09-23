@extends('layouts.app')

@section('title', ($data['event']['title'] ?? '条目').' · 时间线条目 · 明日方舟时间线')
@section('page', 'event-show')

@section('content')
    @php
        // 抽屉里同一份「详情」面板的静态版：可分享的直链页面（从人物页或外部链接点进来）。
        // 这里只渲染只读视图，编辑 / 标注 / 版本留在时间线页的抽屉里（需要登录与权限）。
        $ev = $data['event'];
        $date = $ev['date'] ?? [];
        $status = $ev['status'] ?? [];
    @endphp

    <main class="main main--wide">
        {{-- ============================ 概要 ============================ --}}
        <div class="panel">
            <div class="profile-head">
                <div style="flex:1;min-width:0">
                    <div class="chips" style="margin-bottom:7px">
                        <a class="chip chip--accent" href="{{ route('timeline.index', ['world' => $ev['world']]) }}">
                            {{ $ev['world_label'] ?? '泰拉' }}
                        </a>
                        <span class="chip">{{ $date['display'] ?? '' }}</span>
                    </div>
                    <h1 style="margin:0;font-size:21px;letter-spacing:.02em">{{ $ev['title'] }}</h1>
                </div>

                <div class="row-actions">
                    <a class="btn btn--ghost btn--sm" href="{{ route('timeline.index', ['world' => $ev['world']]) }}">
                        <x-icon name="back"/>返回时间线
                    </a>
                </div>
            </div>
        </div>

        {{-- ============================ 一致性告警 ============================ --}}
        @if (! empty($data['anomalies']))
            <div class="panel">
                <div class="panel__title"><span data-en="CONSISTENCY">一致性告警</span></div>
                @foreach ($data['anomalies'] as $a)
                    <div class="alert {{ $a['severity'] === 'error' ? 'alert--danger' : 'alert--warn' }}" style="margin-top:10px">
                        <span class="badge {{ $a['severity'] === 'error' ? 'badge--danger' : 'badge--warn' }}"><x-icon name="{{ $a['severity'] === 'error' ? 'status-danger' : 'status-warn' }}" class="icon--sm"/>{{ $a['type_label'] ?? '' }}</span>
                        {{ $a['message'] ?? '' }}
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================ 详情 ============================ --}}
        <div class="panel">
            <div class="panel__title"><span data-en="DETAIL">详情</span></div>

            <dl class="kv">
                <dt>游戏内纪元</dt>
                <dd class="mono">
                    {{ $date['display'] ?? '—' }}
                    <span class="faint">（{{ $date['precision_label'] ?? '' }} · {{ $date['confidence_label'] ?? '' }}）</span>
                </dd>

                <dt>所属纪元</dt>
                <dd>
                    @if (! empty($ev['era']))
                        {{ $ev['era']['name'] }}
                        <span class="faint small">{{ $ev['era']['date_label'] ?? '' }}</span>
                    @else
                        <span class="faint">未归属</span>
                    @endif
                </dd>

                <dt>发生地</dt><dd>{{ $ev['location'] ?: '—' }}</dd>

                <dt>状态</dt>
                <dd>
                    <span class="badge {{ $status['badge'] ?? 'badge--muted' }}">{{ $status['label'] ?? '' }}</span>
                    @if (! empty($ev['is_locked']))
                        <span class="badge badge--muted">已锁定</span>
                    @endif
                </dd>

                <dt>版本</dt>
                <dd class="mono">VER {{ str_pad((string) ($ev['version'] ?? 0), 3, '0', STR_PAD_LEFT) }} // {{ $ev['updated_at'] ?? '' }}</dd>
            </dl>

            <div class="section-label" data-en="DESCRIPTION">简要描述</div>
            <p style="margin:0">{{ $ev['summary'] ?: '—' }}</p>

            @if (! empty($ev['details']))
                <div class="section-label" data-en="DETAILS">详述</div>
                <p style="margin:0;white-space:pre-wrap">{{ $ev['details'] }}</p>
            @endif

            {{-- ---------------- 出处 ---------------- --}}
            <div class="section-label" data-en="SOURCE">出处来源</div>
            @if (! empty($ev['sources']))
                @foreach ($ev['sources'] as $s)
                    @php
                        $locator = collect([$s['type_label'] ?? null, $s['code'] ?? null, $s['chapter'] ?? null, $s['stage_code'] ?? null])
                            ->filter()->implode(' // ');
                    @endphp
                    <div class="card">
                        <div class="row" style="align-items:baseline">
                            <strong>{{ $s['name'] }}</strong>
                            @if (! empty($s['is_primary']))
                                <span class="badge badge--ok"><x-icon name="status-ok" class="icon--sm"/>主要出处</span>
                            @endif
                            @if (($s['source_line'] ?? 0) > 0)
                                {{-- 有行号说明这条引文已在语料中逐字定位（L3 闸门通过） --}}
                                <span class="chip mono">原文第 {{ $s['source_line'] }} 行</span>
                            @endif
                            @if (! empty($s['is_annotation']))
                                <span class="chip">编者按</span>
                            @endif
                        </div>
                        @if ($locator)
                            <div class="faint small mono" style="margin-top:3px">{{ $locator }}</div>
                        @endif
                        @if (! empty($s['quote']))
                            <div class="quote" style="margin-top:7px">{{ $s['quote'] }}</div>
                            @if (($s['source_line'] ?? 0) === 0)
                                <div class="faint small" style="margin-top:4px">该引文未能在语料中定位 —— 与原文不一致，待核对。</div>
                            @endif
                        @else
                            <div class="faint small" style="margin-top:7px">该出处尚未附引文 —— 待录入原文后补齐，或直接标注说明依据。</div>
                        @endif
                    </div>
                @endforeach
            @else
                <div class="faint small">尚未挂载出处。时间线的可信度取决于出处，建议补齐。</div>
            @endif

            {{-- ---------------- 相关人物 ---------------- --}}
            <div class="section-label" data-en="CHARACTER">相关人物</div>
            <div class="chips">
                @if (! empty($ev['characters']))
                    @foreach ($ev['characters'] as $c)
                        <span class="chip">{{ $c['name'] }}@if (! empty($c['role']))<span class="faint">·{{ $c['role'] }}</span>@endif</span>
                    @endforeach
                @else
                    <span class="faint small">—</span>
                @endif
            </div>

            {{-- ---------------- 相关阵营 ---------------- --}}
            <div class="section-label" data-en="FACTION">相关阵营</div>
            <div class="chips">
                @if (! empty($ev['factions']))
                    @foreach ($ev['factions'] as $f)
                        <span class="chip">
                            <span class="chip__dot" style="background:{{ $f['color'] ?? '#888' }}"></span>
                            {{ $f['name'] }}@if (! empty($f['role']))<span class="faint">·{{ $f['role'] }}</span>@endif
                        </span>
                    @endforeach
                @else
                    <span class="faint small">—</span>
                @endif
            </div>

            {{-- ---------------- 标签 ---------------- --}}
            <div class="section-label" data-en="TAG">标签</div>
            <div class="chips">
                @if (! empty($ev['tags']))
                    @foreach ($ev['tags'] as $t)
                        <span class="chip" style="border-color:{{ ($t['color'] ?? '#888') }}55">{{ $t['name'] }}</span>
                    @endforeach
                @else
                    <span class="faint small">—</span>
                @endif
            </div>
        </div>

        {{-- ============================ 标注（只读） ============================ --}}
        @if (! empty($data['annotations']))
            <div class="panel">
                <div class="panel__title">
                    <span data-en="NOTE">标注</span>
                    <span class="faint small mono">{{ count($data['annotations']) }} NOTES</span>
                </div>
                @foreach ($data['annotations'] as $note)
                    <div class="card" style="margin-top:10px">
                        <div class="row" style="align-items:baseline">
                            <strong>{{ $note['author'] ?? '访客' }}</strong>
                            <span class="badge badge--{{ $note['status'] === 'resolved' ? 'ok' : ($note['status'] === 'rejected' ? 'muted' : 'warn') }}">
                                {{ $note['type_label'] ?? '' }} · {{ $note['status'] }}
                            </span>
                        </div>
                        @if (! empty($note['body']))
                            <div style="margin-top:6px;white-space:pre-wrap">{{ $note['body'] }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <p class="faint small" style="text-align:center;margin:18px 0">
            需要编辑、纠错或查看版本历史？<a href="{{ route('timeline.index', ['world' => $ev['world']]) }}">在时间线页中打开本条目</a>。
        </p>
    </main>
@endsection
