@extends('layouts.app')

@section('title', '账号设置 · 明日方舟时间线')
@section('page', 'settings')

@php
    $maxKilobytes = (int) config('identity.avatar.max_kilobytes', 2048);
    // 登录方式计数：密码算一种，每个已绑定的外部渠道算一种。
    // 这个数字不是装饰 —— 它等于 1 时下面的解绑按钮会被服务层拒绝
    $loginMethodCount = ($user->hasUsablePassword() ? 1 : 0) + $identities->count();
@endphp

@section('content')
    <main class="main main--narrow">
        {{-- ============================ 概要 ============================ --}}
        <div class="panel">
            <div class="profile-head">
                <x-avatar :user="$user" size="xl"/>

                <div style="flex:1;min-width:0">
                    <div class="row" style="align-items:center;gap:9px;flex-wrap:wrap">
                        <h1 style="margin:0;font-size:19px;letter-spacing:.02em">{{ $user->nickname }}</h1>
                        <span class="badge {{ $user->role()->atLeast(\App\Enums\UserRole::Reviewer) ? 'badge--info' : 'badge--muted' }}">
                            {{ $user->role()->label() }}
                        </span>
                        <span class="badge {{ $user->status()->badgeClass() }}">{{ $user->status()->label() }}</span>
                    </div>
                    <div class="faint small mono" style="margin-top:5px">
                        {{ $user->name }} // {{ $user->email }}
                    </div>
                    <div class="faint small" style="margin-top:6px">{{ $user->role()->description() }}</div>
                </div>
            </div>
        </div>

        {{-- ============================ 头像与昵称 ============================ --}}
        <div class="panel">
            <div class="panel__title">
                <span data-en="Profile">基本资料</span>
            </div>

            <div class="detail-grid">
                {{-- ---- 头像 ---- --}}
                <div>
                    <div class="section-label" data-en="Avatar">头像</div>

                    <form method="POST" action="{{ route('settings.profile.avatar') }}"
                          enctype="multipart/form-data" data-avatar-form>
                        @csrf

                        <label class="dropzone" data-avatar-dropzone>
                            <input type="file" name="avatar" accept="image/jpeg,image/png" required
                                   data-avatar-input hidden>
                            <span class="dropzone__preview">
                                <x-avatar :user="$user" size="xl" title="当前头像"/>
                            </span>
                            <span class="dropzone__text">
                                <strong>选择一张图片</strong>
                                <span class="faint small">JPG / PNG · 不超过 {{ $maxKilobytes }} KB</span>
                            </span>
                        </label>

                        <div class="faint small" style="margin:10px 0">
                            上传后会被居中裁剪成正方形并**重新编码**：EXIF（含拍摄位置）与任何
                            附加数据都不会保留，因此头像里不会夹带原始文件的其他内容。
                        </div>

                        <div class="btn-row">
                            <button class="btn btn--primary btn--sm" type="submit">上传头像</button>
                            @if ($user->hasLocalAvatar())
                                <button class="btn btn--danger btn--sm" type="button" data-remove-avatar>移除头像</button>
                            @endif
                        </div>
                    </form>

                    @error('avatar')
                        <div class="alert alert--danger" style="margin-top:10px">{{ $message }}</div>
                    @enderror
                </div>

                {{-- ---- 昵称 ---- --}}
                <div>
                    <div class="section-label" data-en="Nickname">昵称</div>

                    <form method="POST" action="{{ route('settings.profile.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <input type="text" name="nickname" value="{{ old('nickname', $user->nickname) }}"
                                   maxlength="60" required>
                        </div>

                        @error('nickname')
                            <div class="alert alert--danger" style="margin-bottom:10px">{{ $message }}</div>
                        @enderror

                        <button class="btn btn--primary btn--sm" type="submit">保存昵称</button>
                    </form>

                    <dl class="kv" style="margin-top:16px">
                        <dt>登录名</dt>
                        <dd class="mono">{{ $user->name }}</dd>
                        <dt>邮箱</dt>
                        <dd class="mono" style="word-break:break-all">
                            {{ $user->email }}
                            @unless ($user->email_verified_at)
                                <span class="badge badge--warn">未验证</span>
                            @endunless
                        </dd>
                        <dt>注册于</dt>
                        <dd class="mono faint">{{ $user->created_at?->format('Y // m / d H:i') }}</dd>
                    </dl>

                    <div class="faint small" style="margin-top:10px">
                        昵称是你在时间线上对外显示的名字（全服唯一，可随时修改）。
                        登录名与邮箱属于账号凭据，如需修改请联系管理员 —— 在本站
                        还没有邮箱验证流程之前，允许自行改邮箱等于开了一条账号劫持的捷径。
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ 登录与安全 ============================ --}}
        <div class="panel">
            <div class="panel__title">
                <span data-en="Security">登录与安全</span>
                <span class="faint small mono">{{ $loginMethodCount }} 种登录方式</span>
            </div>

            @if ($loginMethodCount <= 1 && $identities->isNotEmpty())
                <div class="alert alert--warn">
                    你目前只有一种登录方式。若要解绑它，请先在下面设置登录密码 ——
                    否则账号将无法再登录（外部渠道注册的账号在设置密码之前，
                    系统里存的是一个连你自己都不知道的随机密码）。
                </div>
            @endif

            {{-- ---- 密码 ---- --}}
            <div class="section-label" data-en="Password">登录密码</div>

            @if ($user->hasUsablePassword())
                <p class="faint small" style="margin:0 0 12px">
                    已设置密码，可以用「邮箱或登录名 + 密码」登录。修改密码需要先验证当前密码。
                </p>
            @else
                <p class="faint small" style="margin:0 0 12px">
                    你是通过外部渠道注册的，尚未设置过密码 ——
                    因此现在只能从外部渠道登录。设置密码后即可用邮箱或登录名登录。
                </p>
            @endif

            <form method="POST" action="{{ route('settings.profile.password') }}" style="max-width:420px">
                @csrf
                @method('PUT')

                @if ($user->hasUsablePassword())
                    <div class="field">
                        <label>当前密码</label>
                        <input type="password" name="current_password" autocomplete="current-password" required>
                        @error('current_password')
                            <div class="alert alert--danger" style="margin-top:8px">{{ $message }}</div>
                        @enderror
                    </div>
                @endif

                <div class="field">
                    <label>{{ $user->hasUsablePassword() ? '新密码' : '设置密码' }}</label>
                    <input type="password" name="password" autocomplete="new-password" required minlength="8">
                    @error('password')
                        <div class="alert alert--danger" style="margin-top:8px">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field">
                    <label>再输一次</label>
                    <input type="password" name="password_confirmation" autocomplete="new-password" required minlength="8">
                </div>

                <button class="btn btn--primary btn--sm" type="submit">
                    {{ $user->hasUsablePassword() ? '修改密码' : '设置密码' }}
                </button>
            </form>
        </div>

        {{-- ============================ 外部账号绑定 ============================ --}}
        <div class="panel">
            <div class="panel__title">
                <span data-en="Linked Accounts">外部账号绑定</span>
                <span class="faint small mono">{{ $identities->count() }} 个已绑定</span>
            </div>

            <div class="alert alert--info">
                绑定只会把外部身份接到本账号上，<strong>不会</strong>让你把外部账号的密码交给本站 ——
                授权始终在你自己的浏览器里完成，本站也不保存任何外部令牌。
            </div>

            @foreach ($providers as $provider)
                @php
                    $identity = $identities->first(
                        fn ($item) => $item->provider()->value === $provider->value
                    );
                @endphp

                <div class="provider-card">
                    <div class="provider-card__head">
                        <span class="provider-card__mark">{{ $provider->short() }}</span>

                        <div style="flex:1;min-width:0">
                            <strong>{{ $provider->label() }}</strong>
                            <div class="faint small">{{ $provider->blurb() }}</div>
                        </div>

                        @if ($identity)
                            <span class="badge {{ $identity->status()->badgeClass() }}">{{ $identity->status()->label() }}</span>
                        @endif
                    </div>

                    @if ($identity)
                        <div class="identity-line">
                            <span class="mono">{{ $identity->displayAccount() }}</span>
                            <span class="faint small">{{ $identity->verificationNote() }}</span>
                            @if ($identity->linked_at)
                                <span class="faint small mono">绑定于 {{ $identity->linked_at->format('Y // m / d') }}</span>
                            @endif
                        </div>

                        <div class="btn-row">
                            <button class="btn btn--danger btn--sm" type="button"
                                    data-unlink-identity="{{ route('identity.unlink', $provider->value) }}"
                                    data-provider-label="{{ $provider->label() }}"
                                    data-last-method="{{ $loginMethodCount <= 1 ? '1' : '0' }}">
                                解绑
                            </button>
                        </div>
                    @elseif ($provider->isEnabled())
                        <div class="btn-row">
                            <a class="btn btn--primary btn--sm" href="{{ route('identity.bind', $provider->value) }}">
                                绑定{{ $provider->label() }}
                            </a>
                        </div>
                    @elseif ($provider->allowsManual())
                        {{--
                            正式授权通道未接入时的可用路径。
                            措辞必须诚实：这是「自助登记 + 人工核验」，不是官方认证。
                        --}}
                        <div class="alert alert--warn" style="margin:0 0 12px">
                            {{ $provider->label() }}未提供面向第三方的公开授权接口，因此官方授权通道暂未接入。
                            你可以先手工登记{{ $provider->manualLabel() }}：登记后状态为「待核验」，
                            管理员确认后才转为「已核验」—— 界面上两者始终分开显示。
                        </div>

                        <form method="POST" action="{{ route('identity.claim', $provider->value) }}" style="max-width:420px">
                            @csrf
                            <div class="field">
                                <label>{{ $provider->manualLabel() }}</label>
                                <input type="text" name="provider_user_id" required maxlength="64"
                                       pattern="[A-Za-z0-9_-]{4,64}" placeholder="例如 12345678">
                                <div class="faint small" style="margin-top:6px">{{ $provider->manualHint() }}</div>
                            </div>
                            <button class="btn btn--sm" type="submit">登记并等待核验</button>
                        </form>
                    @else
                        <div class="faint small">
                            该渠道尚未启用。管理员配置好授权凭据后即可使用。
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ============================ 最近操作 ============================ --}}
        <div class="panel">
            <div class="panel__title">
                <span data-en="Activity">我最近的操作</span>
                <span class="faint small mono">仅追加 · 不可修改</span>
            </div>

            @forelse ($recentLogs as $log)
                <div class="log-row">
                    <div class="log-row__head">
                        <span class="badge {{ $log->action->badgeClass() }}">{{ $log->action->label() }}</span>
                        <span class="mono small faint">{{ $log->created_at?->format('Y // m / d H:i') }}</span>
                        @if ($log->ip_address)
                            <span class="faint small mono">{{ $log->ip_address }}</span>
                        @endif
                    </div>
                    <div style="margin-top:5px">{{ $log->description }}</div>
                </div>
            @empty
                <div class="empty">还没有操作记录。</div>
            @endforelse
        </div>
    </main>
@endsection
