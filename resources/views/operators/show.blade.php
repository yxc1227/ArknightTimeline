@extends('layouts.app')

@section('title', $character->name.' · '.$character->world()->label().'人员简介 · 明日方舟时间线')
@section('page', 'operator-show')

@section('content')
    <main class="main main--wide">
        {{-- ============================ 概要 ============================ --}}
        <div class="panel">
            <div class="profile-head">
                <span class="operator-mark" aria-hidden="true">{{ mb_substr($character->name, 0, 1) }}</span>

                <div style="flex:1;min-width:0">
                    <div class="row" style="align-items:center;gap:9px;flex-wrap:wrap">
                        <h1 style="margin:0;font-size:19px;letter-spacing:.02em">{{ $character->name }}</h1>
                        @if (filled($character->codename))
                            <span class="mono faint">{{ $character->codename }}</span>
                        @endif
                        @unless ($character->hasProfile())
                            <span class="badge badge--muted">简介待补</span>
                        @endunless
                    </div>

                    <div class="chips" style="margin-top:7px">
                        {{-- 世界是最先要告诉读者的：泰拉与塔卫二的人各有一套名单与维基 --}}
                        <span class="chip chip--accent" style="--world-accent:{{ $character->world()->accent() }}">
                            {{ $character->world()->label() }} · {{ $character->world()->englishLabel() }}
                        </span>
                        @if (filled($character->faction?->name))
                            <a class="chip" href="{{ route('operators.index', ['world' => $character->world()->value, 'faction' => $character->faction_id]) }}">
                                {{ $character->faction->name }}
                            </a>
                        @endif
                        @if (filled($character->raceName()))
                            <a class="chip" data-clickable="1"
                               href="{{ route('races.index') }}#race-{{ $character->race?->slug }}">
                                {{ $character->raceName() }}
                            </a>
                        @endif
                        @if (filled($character->title))
                            <span class="chip chip--accent">{{ $character->title }}</span>
                        @endif
                        <span class="chip">{{ $eventCount }} 条相关条目</span>
                    </div>
                </div>

                <div class="row-actions">
                    {{-- 权威资料的外链放在最显眼的位置：本页只给「与时间线相关的那一行」。
                         历史人物只在显式指定了对方条目名时才给链接 —— 拿本名去猜多半是死链。 --}}
                    @if ($character->showsWikiLink())
                        <a class="btn btn--primary btn--sm" href="{{ $character->wikiUrl() }}"
                           target="_blank" rel="noopener noreferrer">
                            在 {{ $character->wikiLabel() }} 查看完整资料 ↗
                        </a>
                    @endif
                    <a class="btn btn--ghost btn--sm"
                       href="{{ route('operators.index', ['world' => $character->world()->value, 'kind' => $character->kind]) }}">
                        <x-icon name="back"/>返回列表
                    </a>
                </div>
            </div>
        </div>

        {{-- ============================ 简介 ============================ --}}
        <div class="panel">
            <div class="panel__title">
                <span data-en="Profile">简介</span>
                <span class="faint small mono">
                    {{ $character->hasProfile() ? '本仓库撰写' : '尚未撰写 · 以下为结构化字段' }}
                </span>
            </div>

            <p style="margin:0;line-height:1.9">{{ $character->profileText() }}</p>

            @if ($character->needsProfile())
                <div class="alert alert--warn" style="margin:14px 0 0">
                    本仓库还没有为这位人物写过简介 —— 上面那段是由阵营、种族与条目数拼出来的事实卡，
                    不是考据结论。完整的{{ $character->world()->label() }}人物档案请见
                    <a href="{{ $character->wikiUrl() }}" target="_blank" rel="noopener noreferrer">{{ $character->wikiLabel() }}</a>。
                </div>
            @endif

            <dl class="kv" style="margin-top:16px">
                <dt>名称</dt><dd>{{ $character->name }}</dd>
                <dt>代号</dt><dd class="mono">{{ $character->codename ?: '—' }}</dd>
                <dt>所属世界</dt><dd>{{ $character->world()->label() }}（{{ $character->world()->englishLabel() }}）</dd>
                <dt>阵营</dt><dd>{{ $character->faction?->name ?? '—' }}</dd>
                <dt>种族</dt>
                <dd>
                    @if (filled($character->raceName()))
                        <a href="{{ route('races.index') }}#race-{{ $character->race?->slug }}">
                            {{ $character->raceName() }}
                        </a>
                    @else
                        —
                    @endif
                    {{-- 留空有两种情形：字典没收录，或官方资料本身就写着「未公开」
                         （阿戈尔系与炎-岁那几位都是后者）。两者都不该被填上 --}}
                    <span class="faint small">（字典未收录、或官方资料标为「未公开」时留空 —— 宁可缺失也不要写错）</span>
                </dd>

                @if (filled($character->title) || $character->reign_start_index !== null)
                    {{-- 头衔与在位期只对历史人物有意义；区间只有一端时如实说「起于 / 止于」 --}}
                    <dt>头衔</dt><dd>{{ $character->title ?: '—' }}</dd>
                    <dt>在位</dt>
                    <dd>
                        {{ $character->reignLabel() ?: '—' }}
                        <span class="faint small">（书里未载的一端不补，宁可缺失也不要写错）</span>
                    </dd>
                @endif

                <dt>外部资料</dt>
                <dd style="word-break:break-all">
                    @if ($character->showsWikiLink())
                        <a href="{{ $character->wikiUrl() }}" target="_blank" rel="noopener noreferrer">
                            {{ $character->wikiUrl() }}
                        </a>
                    @else
                        <span class="faint">历史人物不猜对方站点的条目名 —— 本名与称号、译名往往并不一致。</span>
                    @endif
                </dd>
            </dl>
        </div>

        {{-- ============================ 相关条目 ============================ --}}
        <div class="panel">
            <div class="panel__title">
                <span data-en="Related">相关时间线条目</span>
                <span class="faint small mono">{{ $eventCount }} EVENTS</span>
            </div>

            @forelse ($eventsByWorld as $worldValue => $events)
                @php $world = \App\Enums\World::from($worldValue); @endphp

                <div class="section-label">
                    {{ $world->label() }} · {{ $world->calendarLabel() }}
                </div>

                <div class="event-rows">
                    @foreach ($events as $event)
                        <a class="event-row" href="{{ route('events.show', $event) }}">
                            <span class="event-row__date mono">{{ $event->date_display }}</span>
                            <span class="event-row__title">{{ $event->title }}</span>
                            @if ($event->era)
                                <span class="chip">{{ $event->era->name }}</span>
                            @endif
                            <span class="badge {{ $event->date_confidence->badgeClass() }}">
                                {{ $event->date_confidence->label() }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @empty
                <div class="empty">
                    本仓库还没有收录与 {{ $character->name }} 相关的时间线条目。<br>
                    <span class="small">条目里一旦出现这个名字，就会自动出现在这里。</span>
                </div>
            @endforelse
        </div>
    </main>
@endsection
