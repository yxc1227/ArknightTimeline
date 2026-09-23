<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '泰拉时间线 · 明日方舟统一事件年表')</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
</head>
<body data-page="@yield('page', 'timeline')">

<header class="topbar">
    <a class="brand" href="{{ route('timeline.index') }}">
        <span class="brand__mark">TL</span>
        <span>泰拉时间线</span>
        <span class="brand__sub">Terra Timeline ://</span>
    </a>

    {{-- 导航为「英文大写微标签 + 中文」的上下双语结构 --}}
    <nav class="nav">
        <a href="{{ route('timeline.index') }}" class="{{ request()->routeIs('timeline.*') ? 'is-active' : '' }}">
            <span class="nav__en">Timeline</span>
            <span class="nav__zh">时间线</span>
        </a>

        @auth
            <a href="{{ route('sources.index') }}" class="{{ request()->routeIs('sources.*') ? 'is-active' : '' }}">
                <span class="nav__en">Source</span>
                <span class="nav__zh">出处与语料</span>
            </a>
            <a href="{{ route('proposals.index') }}" class="{{ request()->routeIs('proposals.*') ? 'is-active' : '' }}">
                <span class="nav__en">AI Review</span>
                <span class="nav__zh">
                    AI 审核台
                    @if (($proposalPending ?? 0) > 0)
                        <span class="nav__count">{{ $proposalPending }}</span>
                    @endif
                </span>
            </a>
            <a href="{{ route('anomalies.index') }}" class="{{ request()->routeIs('anomalies.*') ? 'is-active' : '' }}">
                <span class="nav__en">Consistency</span>
                <span class="nav__zh">
                    一致性收件箱
                    @if (($anomalyOpen ?? 0) > 0)
                        <span class="nav__count">{{ $anomalyOpen }}</span>
                    @endif
                </span>
            </a>
        @endauth
    </nav>

    <div class="spacer"></div>

    <div class="userbox">
        @auth
            <span class="role-chip" data-role="{{ auth()->user()->role()->value }}">{{ auth()->user()->role()->label() }}</span>
            <span>{{ auth()->user()->displayLabel() }}</span>
            <form method="POST" action="{{ route('logout') }}" style="margin:0">
                @csrf
                <button class="btn btn--ghost btn--sm" type="submit">退出</button>
            </form>
        @else
            <span class="faint small mono">READ ONLY</span>
            <a class="btn btn--sm" href="{{ route('login') }}">登录</a>
        @endauth
    </div>
</header>

<div class="shell">
    @yield('content')
</div>

<div class="toasts" id="toasts"></div>

<script>
    window.APP = {
        csrf: @json(csrf_token()),
        user: @json(auth()->user()?->toApiArray()),
        perPage: {{ (int) config('timeline.collaboration.per_page', 40) }},
        urls: {
            timeline: @json(route('timeline.feed')),
            events: @json(url('/events')),
            proposals: @json(url('/proposals')),
            proposalsBulk: @json(route('proposals.bulk-approve')),
            synthesize: @json(route('ai.synthesize')),
            anomalies: @json(url('/anomalies')),
            anomaliesScan: @json(route('anomalies.scan')),
            sources: @json(url('/sources'))
        }
    };
</script>
@stack('boot')
<script src="{{ asset('assets/app.js') }}"></script>
@stack('scripts')

</body>
</html>
