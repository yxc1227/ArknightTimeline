@extends('layouts.app')

@section('title', '完善账号资料 · 泰拉时间线')
@section('page', 'register')

@section('content')
    <div class="login-wrap">
        <div class="login-card login-card--wide">
            <h1 data-en="Register" style="margin:0 0 6px;font-size:19px;letter-spacing:.02em">完善账号资料</h1>
            <p class="faint small" style="margin:0 0 18px">
                已确认你对该{{ $profile->provider->label() }}账号的持有权。
                下面三项是本站自己的命名空间，需要由你当场确定。
            </p>

            {{-- 明确展示「确认到的是哪个外部账号」：用户必须能核对是不是自己那一个 --}}
            <div class="provider-card">
                <div class="provider-card__head">
                    <span class="provider-card__mark">{{ $profile->provider->short() }}</span>
                    <div style="flex:1;min-width:0">
                        <strong>{{ $profile->provider->label() }}</strong>
                        <div class="mono small">{{ $profile->displayAccount() }}</div>
                    </div>
                    <span class="badge badge--ok">已授权</span>
                </div>
            </div>

            @if ($errors->any())
                <div class="alert alert--danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('identity.register.store') }}">
                @csrf

                <div class="field">
                    <label>登录名</label>
                    <input type="text" name="name" value="{{ old('name', $suggestedHandle) }}"
                           required maxlength="30" pattern="[A-Za-z][A-Za-z0-9_-]{2,29}" autocomplete="username">
                    <div class="faint small" style="margin-top:6px">
                        用于登录，全服唯一。字母开头，只允许字母、数字、下划线与连字符 ——
                        不含中文是为了避免同形字（西里尔字母 «а» 与拉丁 «a» 看起来一样，
                        却能注册出两个账号）。
                    </div>
                </div>

                <div class="field">
                    <label>昵称</label>
                    <input type="text" name="nickname" value="{{ old('nickname', $suggestedNickname) }}"
                           required maxlength="60">
                    <div class="faint small" style="margin-top:6px">
                        在时间线上对外显示的名字，全服唯一，之后可随时修改。中文可以。
                    </div>
                </div>

                <div class="field">
                    <label>邮箱</label>
                    <input type="email" name="email" value="{{ old('email', $profile->email) }}"
                           required maxlength="190" autocomplete="email">
                    <div class="faint small" style="margin-top:6px">
                        本站的登录凭据之一，全服唯一。本站在没有邮件验证流程之前不会声称它已验证，
                        因此资料页上会如实显示为「未验证」。
                    </div>
                </div>

                <button type="submit" class="btn btn--primary" style="width:100%;margin-top:4px">
                    创建账号并登录
                </button>
            </form>

            <p class="faint small" style="margin:14px 0 0">
                新账号的初始角色是<strong>预备干员</strong>（只读，可提交标注建议）。
                需要编辑权限请让管理员调整。
            </p>

            <p class="faint small" style="margin:10px 0 0">
                不想用外部渠道？<a href="{{ route('register') }}">改用邮箱注册</a>，或
                <a href="{{ route('login') }}">返回登录</a>。
            </p>
        </div>
    </div>
@endsection
