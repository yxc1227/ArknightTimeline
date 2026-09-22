@extends('layouts.app')

@section('title', '登录 · 泰拉时间线')
@section('page', 'login')

@section('content')
    <div class="login-wrap">
        <div class="login-card">
            <h1 style="margin:0 0 4px;font-size:20px">登录</h1>
            <p class="faint small" style="margin:0 0 18px">
                浏览时间线无需登录；登录后可编辑条目（编辑者）、审核 AI 提案与处置冲突（审核员）。
            </p>

            @if ($errors->any())
                <div class="alert alert--danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login.store') }}">
                @csrf
                <div class="field">
                    <label>邮箱</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus>
                </div>
                <div class="field">
                    <label>密码</label>
                    <input type="password" name="password" required>
                </div>
                <label class="check" style="margin:10px 0 16px">
                    <input type="checkbox" name="remember"> 记住我
                </label>
                <button type="submit" class="btn btn--primary" style="width:100%">登录</button>
            </form>

            <div class="section-label">演示账号（Seeder 预置）</div>
            <div class="faint small mono" style="line-height:1.9">
                admin@terra.local / terra-admin（管理员）<br>
                reviewer@terra.local / terra-reviewer（审核员）<br>
                editor@terra.local / terra-editor（编辑者）<br>
                viewer@terra.local / terra-viewer（访客）
            </div>
        </div>
    </div>
@endsection
