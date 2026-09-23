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

            {{-- 账号管理只对管理员可见：非管理员连入口都不必看到 --}}
            @if (auth()->user()->isAdmin())
                <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">
                    <span class="nav__en">Account</span>
                    <span class="nav__zh">账号管理</span>
                </a>
            @endif
        @endauth
    </nav>

    <div class="spacer"></div>

    <div class="userbox">
        @auth
            <span class="role-chip" data-role="{{ auth()->user()->role()->value }}">{{ auth()->user()->role()->label() }}</span>

            {{--
                头像即「我的」入口：全站最自然的位置就是右上角这一小块。
                刻意不为它单独加一个导航项 —— 导航已经承担了四个工作页面的分流，
                再塞一个「账号设置」只会让分组变模糊。
            --}}
            <a class="userbox__me" href="{{ route('settings.profile') }}" title="账号设置">
                <x-avatar :user="auth()->user()" size="sm" title="账号设置"/>
                <span>{{ auth()->user()->displayLabel() }}</span>
            </a>

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

{{--
    一次性提示条。
    只渲染 success 类型的 flash（session('status')）：错误提示由各页自己渲染，
    因为「错在哪个字段」需要就地展示，放成全页横幅反而看不出是哪个输入框的问题。
--}}
@if (session('status'))
    <div class="flash-bar">
        <div class="alert alert--ok">{{ session('status') }}</div>
    </div>
@endif

<div class="shell">
    @yield('content')
</div>

<div class="toasts" id="toasts"></div>

<script>
    window.APP = {
        csrf: @json(csrf_token()),
        user: @json(auth()->user()?->toApiArray()),
        perPage: {{ (int) config('timeline.collaboration.per_page', 40) }},
        // 头像体积上限交给前端，让它在选文件时就能给出提示，不必等一次失败的往返
        avatarMaxKb: {{ (int) config('identity.avatar.max_kilobytes', 2048) }},
        urls: {
            timeline: @json(route('timeline.feed')),
            events: @json(url('/events')),
            proposals: @json(url('/proposals')),
            proposalsBulk: @json(route('proposals.bulk-approve')),
            synthesize: @json(route('ai.synthesize')),
            anomalies: @json(url('/anomalies')),
            anomaliesScan: @json(route('anomalies.scan')),
            sources: @json(url('/sources')),
            users: @json(url('/admin/users')),
            usersBulk: @json(route('admin.users.bulk')),
            avatarDestroy: @json(route('settings.profile.avatar.destroy'))
        }
    };
</script>
@stack('boot')
<script src="{{ asset('assets/app.js') }}"></script>
@stack('scripts')

</body>
</html>
