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
        <span class="brand__sub">Terra Unified Timeline</span>
    </a>

    <nav class="nav">
        <a href="{{ route('timeline.index') }}" class="{{ request()->routeIs('timeline.*') ? 'is-active' : '' }}">
            <span class="nav__label">时间线</span>
        </a>

        @auth
            <a href="{{ route('sources.index') }}" class="{{ request()->routeIs('sources.*') ? 'is-active' : '' }}">
                <span class="nav__label">出处与语料</span>
            </a>
            <a href="{{ route('proposals.index') }}" class="{{ request()->routeIs('proposals.*') ? 'is-active' : '' }}">
                <span class="nav__label">AI 审核台</span>
                @if (($proposalPending ?? 0) > 0)
                    <span class="nav__count">{{ $proposalPending }}</span>
                @endif
            </a>
            <a href="{{ route('anomalies.index') }}" class="{{ request()->routeIs('anomalies.*') ? 'is-active' : '' }}">
                <span class="nav__label">一致性收件箱</span>
                @if (($anomalyOpen ?? 0) > 0)
                    <span class="nav__count">{{ $anomalyOpen }}</span>
                @endif
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
            <span class="faint small">只读浏览中</span>
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
