<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '源石纪年 · 统一事件年表')</title>

    {{--
        标记同时供给三处，几何同源（app/Support/Logo.php）：
        SVG 给支持矢量图标的浏览器（任意缩放都清晰），.ico 给不支持的旧浏览器兜底，
        apple-touch-icon 是 iOS 添加到主屏时的图标（该处只认 PNG，故由同一几何导出）。
        三行顺序有意义：现代浏览器优先取 SVG。
    --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
</head>
<body data-page="@yield('page', 'timeline')">

<header class="topbar">
    <a class="brand" href="{{ route('timeline.index') }}">
        {{-- 标记本身是装饰：站点名就在旁边，重复朗读只是噪音，故不传 label --}}
        <x-logo class="brand__mark"/>
        <span>源石纪年</span>
        <span class="brand__sub">Originium Chronicle ://</span>
    </a>

    {{-- 导航为「英文大写微标签 + 中文」的上下双语结构 --}}
    <nav class="nav">
        <a href="{{ route('timeline.index') }}" class="{{ request()->routeIs('timeline.*') ? 'is-active' : '' }}">
            <x-icon name="timeline" class="icon--lg"/>
            <span class="nav__en">Timeline</span>
            <span class="nav__zh">时间线</span>
        </a>

        {{-- 干员简介与时间线同属公开内容：读者顺着条目里的名字就能点进来 --}}
        <a href="{{ route('operators.index') }}" class="{{ request()->routeIs('operators.*') ? 'is-active' : '' }}">
            <x-icon name="operators" class="icon--lg"/>
            <span class="nav__en">Operators</span>
            <span class="nav__zh">干员简介</span>
        </a>

        {{--
            四个词典大类各自成入口，而不是挤在一个「资料集」里。
            读者在条目里碰到的是**具体一类**名词：地点、组织、种族、术语各是一个问题，
            给它们各一个入口，才不用先猜它被归在哪一类。

            四枚图标与四类一一对应，且按「提问」画而不是画成同一个「词典」的样子
            （地名 = 定位、组织 = 旗帜、种族 = 谱系、词条 = 条目）——
            它们在这里并排出现，若都长成一本书，图标就只是装饰了。
            下方列表里其余部分刻意**不**配图标，理由见各自的注释。
        --}}
        <a href="{{ route('places.index') }}" class="{{ request()->routeIs('places.*') ? 'is-active' : '' }}">
            <x-icon name="places" class="icon--lg"/>
            <span class="nav__en">Places</span>
            <span class="nav__zh">地名</span>
        </a>

        <a href="{{ route('organizations.index') }}" class="{{ request()->routeIs('organizations.*') ? 'is-active' : '' }}">
            <x-icon name="organizations" class="icon--lg"/>
            <span class="nav__en">Org</span>
            <span class="nav__zh">组织</span>
        </a>

        <a href="{{ route('races.index') }}" class="{{ request()->routeIs('races.*') ? 'is-active' : '' }}">
            <x-icon name="races" class="icon--lg"/>
            <span class="nav__en">Races</span>
            <span class="nav__zh">种族</span>
        </a>

        <a href="{{ route('terms.index') }}" class="{{ request()->routeIs('terms.*') ? 'is-active' : '' }}">
            <x-icon name="terms" class="icon--lg"/>
            <span class="nav__en">Terms</span>
            <span class="nav__zh">词条</span>
        </a>

        @auth
            <a href="{{ route('sources.index') }}" class="{{ request()->routeIs('sources.*') ? 'is-active' : '' }}">
                <x-icon name="sources" class="icon--lg"/>
                <span class="nav__en">Source</span>
                <span class="nav__zh">出处与语料</span>
            </a>
            <a href="{{ route('proposals.index') }}" class="{{ request()->routeIs('proposals.*') ? 'is-active' : '' }}">
                <x-icon name="review" class="icon--lg"/>
                <span class="nav__en">AI Review</span>
                <span class="nav__zh">
                    AI 审核台
                    @if (($proposalPending ?? 0) > 0)
                        <span class="nav__count">{{ $proposalPending }}</span>
                    @endif
                </span>
            </a>
            <a href="{{ route('anomalies.index') }}" class="{{ request()->routeIs('anomalies.*') ? 'is-active' : '' }}">
                <x-icon name="inbox" class="icon--lg"/>
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
                    <x-icon name="account" class="icon--lg"/>
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
        // 世界是页面级上下文，由查询串决定；解析一次交给 JS，避免各模块各写一遍
        world: @json(\App\Enums\World::fromRequest(request()->string('world')->value())->value),
        worldCalendar: @json(\App\Enums\World::fromRequest(request()->string('world')->value())->calendarLabel()),
        user: @json(optional(auth()->user())->toApiArray()),
        perPage: {{ (int) config('timeline.collaboration.per_page', 40) }},
        // 头像体积上限交给前端，让它在选文件时就能给出提示，不必等一次失败的往返
        avatarMaxKb: {{ (int) config('identity.avatar.max_kilobytes', 2048) }},
        urls: {
            timeline: @json(route('timeline.feed')),
            timelinePage: @json(route('timeline.index')),
            places: @json(route('places.index')),
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
<script>
    /*
        锚点前缀 → 所在页面。

        四个词典大类各自成页之后，站外的引用与旧书签里的 `#place-…` / `#race-…`
        可能落在别的页面上（旧的「资料集」更是把四类挤在同一页）。
        这里按前缀纠正一次 —— 否则读者看到的是「链接打开了，却停在页面顶端」，
        那与坏链接没有区别。同页的锚点不动，交给浏览器原生行为。
    */
    (() => {
        const prefix = location.hash.slice(1).split('-')[0];
        const target = {
            place: @json(route('places.index')),
            org: @json(route('organizations.index')),
            race: @json(route('races.index')),
            term: @json(route('terms.index')),
        }[prefix];

        if (!target) return;

        const url = new URL(location.href);
        const path = new URL(target, location.origin).pathname;

        if (url.pathname === path) return;

        // 保留查询串（世界等上下文）与片段，只换路径
        url.pathname = path;
        location.replace(url.toString());
    })();
</script>

@stack('boot')
<script src="{{ asset('assets/app.js') }}"></script>
@stack('scripts')

</body>
</html>
