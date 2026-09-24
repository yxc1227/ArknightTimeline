@extends('layouts.app')

@section('title', '登录 · 源石纪年')
@section('page', 'login')

@php
    $enabledProviders = \App\Enums\IdentityProvider::enabled();
    // 可自助登记的渠道也要露面，否则用户会以为绑定功能不存在
    $manualProviders = collect(\App\Enums\IdentityProvider::all())
        ->reject(fn ($p) => $p->isEnabled())
        ->filter(fn ($p) => $p->allowsManual());
@endphp

@section('content')
    <div class="login-wrap">
        <div class="login-card">
            <h1 data-en="Login" style="margin:0 0 6px;font-size:19px;letter-spacing:.02em">登录</h1>
            <p class="faint small" style="margin:0 0 18px">
                浏览时间线无需登录；登录后可编辑条目（干员）、审核 AI 提案与处置冲突（精英干员）。
            </p>

            @if ($errors->any())
                <div class="alert alert--danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login.store') }}">
                @csrf
                <div class="field">
                    <label>邮箱或登录名</label>
                    {{-- 两者都全服唯一，因此同一个输入框就能承担两种凭据 --}}
                    <input type="text" name="identifier" value="{{ old('identifier') }}" required autofocus
                           autocomplete="username" maxlength="190">
                </div>
                <div class="field">
                    <label>密码</label>
                    <input type="password" name="password" required autocomplete="current-password">
                </div>
                <label class="check" style="margin:10px 0 16px">
                    <input type="checkbox" name="remember"> 记住我
                </label>
                <button type="submit" class="btn btn--primary" style="width:100%">登录</button>
            </form>

            @if (config('identity.registration.enabled', true))
                <p class="faint small" style="margin:14px 0 0">
                    还没有账号？<a href="{{ route('register') }}">注册一个</a>@if ($enabledProviders !== []) ，或使用下方任一外部渠道 @endif。
                </p>
            @else
                <p class="faint small" style="margin:14px 0 0">
                    本站当前未开放自助注册，需要账号请联系管理员。
                </p>
            @endif

            @if ($enabledProviders !== [])
                <div class="section-label" data-en="External">通过外部渠道登录或注册</div>

                <div class="provider-buttons">
                    @foreach ($enabledProviders as $provider)
                        <a class="btn" href="{{ route('identity.redirect', $provider->value) }}">
                            <span class="provider-card__mark">{{ $provider->short() }}</span>
                            {{ $provider->label() }}
                        </a>
                    @endforeach
                </div>

                <p class="faint small" style="margin:10px 0 0">
                    尚未在本站注册过？用外部渠道登录时会先让你确认登录名与昵称，然后自动建号。
                </p>
            @endif

            @if ($manualProviders->isNotEmpty())
                <div class="section-label" data-en="Fallback">其他可绑定的渠道</div>
                <p class="faint small" style="margin:0">
                    @foreach ($manualProviders as $provider)
                        {{ $provider->label() }}
                        @if (! $loop->last) 与 @endif
                    @endforeach
                    的官方授权通道尚未接入（对方未提供面向第三方的公开接口）。
                    若你已经有本站账号，可以登录后在「账号设置」里手工登记并等待核验。
                </p>
            @endif

            <div class="section-label" data-en="Demo Accounts">演示账号</div>
            <div class="faint small mono" style="line-height:2">
                admin@terra.local // terra-admin · 博士<br>
                reviewer@terra.local // terra-reviewer · 精英干员<br>
                editor@terra.local // terra-editor · 干员<br>
                viewer@terra.local // terra-viewer · 预备干员
            </div>
        </div>
    </div>
@endsection
