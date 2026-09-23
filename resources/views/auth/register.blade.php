@extends('layouts.app')

@section('title', '注册 · 明日方舟时间线')
@section('page', 'register')

@section('content')
    <div class="login-wrap">
        <div class="login-card login-card--wide">
            <h1 data-en="Register" style="margin:0 0 6px;font-size:19px;letter-spacing:.02em">注册</h1>
            <p class="faint small" style="margin:0 0 18px">
                新账号的初始角色是<strong>预备干员</strong>：可以浏览完整时间线、提交标注建议（纠错 / 存疑 / 复核）。
                需要编辑条目时由管理员调整角色。
            </p>

            @if ($errors->any())
                <div class="alert alert--danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('register.store') }}">
                @csrf

                <div class="field">
                    <label>登录名</label>
                    <input type="text" name="name" value="{{ old('name') }}"
                           required maxlength="30" pattern="[A-Za-z][A-Za-z0-9_-]{2,29}"
                           autocomplete="username" autofocus>
                    <div class="faint small" style="margin-top:6px">
                        用于登录，全服唯一。字母开头，只允许字母、数字、下划线与连字符 ——
                        不含中文是为了避免同形字（西里尔字母 «а» 与拉丁 «a» 看起来一样，
                        却能注册出两个账号）。
                    </div>
                </div>

                <div class="field">
                    <label>昵称</label>
                    <input type="text" name="nickname" value="{{ old('nickname') }}" required maxlength="60">
                    <div class="faint small" style="margin-top:6px">
                        你在时间线上对外显示的名字，全服唯一，之后可随时修改。中文可以。
                    </div>
                </div>

                <div class="field">
                    <label>邮箱</label>
                    <input type="email" name="email" value="{{ old('email') }}" required
                           maxlength="190" autocomplete="email">
                    <div class="faint small" style="margin-top:6px">
                        全站唯一，也是登录凭据之一。本站还没有邮件验证链路，因此资料页上
                        会如实把它显示为「未验证」，而不是假装已经验证过。
                    </div>
                </div>

                <div class="field">
                    <label>密码</label>
                    <input type="password" name="password" required minlength="8"
                           autocomplete="new-password">
                </div>

                <div class="field">
                    <label>再输一次</label>
                    <input type="password" name="password_confirmation" required minlength="8"
                           autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn--primary" style="width:100%;margin-top:4px">
                    创建账号
                </button>
            </form>

            @if ($providers !== [])
                <div class="section-label" data-en="Or">或者用外部渠道注册</div>

                <div class="provider-buttons">
                    @foreach ($providers as $provider)
                        <a class="btn" href="{{ route('identity.redirect', $provider->value) }}">
                            <span class="provider-card__mark">{{ $provider->short() }}</span>
                            {{ $provider->label() }}
                        </a>
                    @endforeach
                </div>

                <p class="faint small" style="margin:10px 0 0">
                    走外部渠道时不需要填密码：先在对方站点完成授权，再回来确认登录名与昵称即可。
                </p>
            @endif

            <p class="faint small" style="margin:16px 0 0">
                已经有账号了？<a href="{{ route('login') }}">去登录</a>。
            </p>
        </div>
    </div>
@endsection
