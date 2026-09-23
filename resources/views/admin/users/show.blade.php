@extends('layouts.app')

@section('title', $user->displayLabel().' · 账号详情')
@section('page', 'user-show')

@section('content')
    <main class="main" style="max-width:1100px;margin:0 auto">
        {{-- ============================ 概要 ============================ --}}
        <div class="panel">
            <div class="profile-head">
                <span class="avatar avatar--lg" data-status="{{ $user->trashed() ? 'trashed' : $user->status()->value }}"
                      aria-hidden="true">{{ $user->initials() }}</span>

                <div style="flex:1;min-width:0">
                    <div class="row" style="align-items:center;gap:9px;flex-wrap:wrap">
                        <h1 style="margin:0;font-size:19px;letter-spacing:.02em">{{ $user->displayLabel() }}</h1>
                        <span class="badge {{ $user->role()->atLeast(\App\Enums\UserRole::Reviewer) ? 'badge--info' : 'badge--muted' }}">
                            {{ $user->role()->label() }}
                        </span>
                        @if ($user->trashed())
                            <span class="badge badge--danger">已删除</span>
                        @else
                            <span class="badge {{ $user->status()->badgeClass() }}">{{ $user->status()->label() }}</span>
                        @endif
                        @if ($user->id === $currentUser->id)
                            <span class="badge badge--ok">当前登录</span>
                        @endif
                    </div>
                    <div class="faint small mono" style="margin-top:5px">
                        {{ $user->name }} // {{ $user->email }}
                    </div>
                </div>

                {{-- 动作按钮：先用策略过滤，再由服务层裁决不变量（如「最后一个管理员」） --}}
                <div class="row-actions">
                    @if ($user->trashed())
                        @can('restore', \App\Models\User::class)
                            <button class="btn btn--ok btn--sm" type="button" data-restore="{{ $user->id }}">恢复账号</button>
                        @endcan
                    @else
                        @can('update', $user)
                            <button class="btn btn--sm" type="button" data-edit="{{ $user->id }}"
                                    data-payload="{{ json_encode($user->toApiArray(), JSON_UNESCAPED_UNICODE) }}">编辑资料</button>
                        @endcan
                        @can('resetPassword', $user)
                            <button class="btn btn--sm" type="button" data-reset="{{ $user->id }}"
                                    data-name="{{ $user->displayLabel() }}">重置密码</button>
                        @endcan
                        @can('toggleActive', $user)
                            <button class="btn btn--sm" type="button" data-toggle="{{ $user->id }}"
                                    data-active="{{ $user->isActive() ? '1' : '0' }}"
                                    data-name="{{ $user->displayLabel() }}">{{ $user->isActive() ? '禁用' : '启用' }}</button>
                        @endcan
                        @can('delete', $user)
                            <button class="btn btn--danger btn--sm" type="button" data-delete="{{ $user->id }}"
                                    data-name="{{ $user->displayLabel() }}">删除</button>
                        @endcan
                    @endif
                </div>
            </div>

            @if ($user->trashed())
                <div class="alert alert--danger" style="margin:14px 0 0">
                    该账号已删除（{{ $user->deleted_at?->format('Y // m / d H:i') }}）。
                    删除是软删除：其创建的条目、版本记录与操作日志全部保留，恢复后即可继续使用。
                </div>
            @endif

            @if (! $user->isActive() && ! $user->trashed())
                <div class="alert alert--warn" style="margin:14px 0 0">
                    该账号已被禁用，无法登录。已登录的会话会在下一次请求时被强制退出。
                </div>
            @endif
        </div>

        <div class="detail-grid">
            {{-- ============================ 基本资料 ============================ --}}
            <div class="panel">
                <div class="panel__title">
                    <span data-en="Profile">基本资料</span>
                </div>

                <dl class="kv">
                    <dt>登录名</dt><dd class="mono">{{ $user->name }}</dd>
                    <dt>显示名</dt><dd>{{ $user->display_name ?: '—' }}</dd>
                    <dt>邮箱</dt><dd class="mono" style="word-break:break-all">{{ $user->email }}</dd>
                    <dt>角色</dt><dd>{{ $user->role()->label() }} <span class="faint small">（等级 {{ $user->role()->level() }}）</span></dd>
                    <dt>状态</dt><dd>{{ $user->trashed() ? '已删除' : $user->status()->label() }}</dd>
                    <dt>出处范围</dt>
                    <dd>
                        {{ $user->enforcesSourceScope() ? '限制在自己的出处内' : '不限制（可修改全部条目）' }}
                    </dd>
                    <dt>注册时间</dt><dd class="mono">{{ $user->created_at?->format('Y // m / d H:i') ?? '—' }}</dd>
                    <dt>最后登录</dt>
                    <dd class="mono">
                        @if ($user->last_login_at)
                            {{ $user->last_login_at->format('Y // m / d H:i') }}
                            <span class="faint small">{{ $user->last_login_ip ?? '未记录 IP' }}</span>
                        @else
                            <span class="faint">从未登录</span>
                        @endif
                    </dd>
                    <dt>最后活跃</dt><dd class="mono">{{ $user->last_seen_at?->format('Y // m / d H:i') ?? '—' }}</dd>
                </dl>
            </div>

            {{-- ============================ 贡献与归属 ============================ --}}
            <div class="panel">
                <div class="panel__title">
                    <span data-en="Contribution">贡献与归属</span>
                </div>

                <div class="stat-grid">
                    <div class="stat">
                        <span class="stat__value mono">{{ str_pad($stats['events'], 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="stat__label">创建条目</span>
                    </div>
                    <div class="stat">
                        <span class="stat__value mono">{{ str_pad($stats['revisions'], 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="stat__label">版本记录</span>
                    </div>
                    <div class="stat">
                        <span class="stat__value mono">{{ str_pad($stats['annotations'], 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="stat__label">提交标注</span>
                    </div>
                    <div class="stat">
                        <span class="stat__value mono">{{ str_pad($stats['logs'], 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="stat__label">操作日志</span>
                    </div>
                </div>

                <div class="section-label" data-en="Scope">负责的出处</div>
                <div class="chips">
                    @forelse ($ownedSources as $source)
                        <a class="chip" href="{{ route('sources.show', $source) }}">{{ $source->name }}</a>
                    @empty
                        <span class="faint small">
                            {{ $user->enforcesSourceScope() ? '尚未分配出处，因此无法修改任何条目。' : '未分配出处（也不受范围限制）。' }}
                        </span>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ============================ 操作日志 ============================ --}}
        <div class="panel">
            <div class="panel__title">
                <span data-en="Activity">操作日志</span>
                <span class="faint small mono">仅追加 · 不可修改</span>
            </div>

            @forelse ($logs as $log)
                <div class="log-row">
                    <div class="log-row__head">
                        <span class="badge {{ $log->action->badgeClass() }}">{{ $log->action->label() }}</span>
                        <span class="mono small faint">{{ $log->created_at?->format('Y // m / d H:i') }}</span>
                        <span class="faint small">操作人：{{ $log->actorLabel() }}</span>
                        @if ($log->ip_address)
                            <span class="faint small mono">{{ $log->ip_address }}</span>
                        @endif
                        @if ($log->action->isSensitive())
                            <span class="badge badge--warn">敏感操作</span>
                        @endif
                    </div>

                    <div style="margin-top:5px">{{ $log->description }}</div>

                    @if ($rows = $log->changeRows())
                        <div class="diff-list">
                            @foreach ($rows as $row)
                                <div class="diff-list__row">
                                    <span class="diff-list__field">{{ $row['field'] }}</span>
                                    <span class="diff-list__from">{{ $row['from'] }}</span>
                                    <span class="diff-list__arrow">→</span>
                                    <span class="diff-list__to">{{ $row['to'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="empty">
                    该账号还没有操作记录。<br>
                    <span class="small">登录、资料修改、禁用、重置密码等动作都会自动留痕。</span>
                </div>
            @endforelse

            {{ $logs->links() }}
        </div>

        <div class="btn-row" style="margin-bottom:20px">
            <a class="btn" href="{{ route('admin.users.index') }}">← 返回账号列表</a>
        </div>
    </main>

    @include('admin.users.partials.modals')
@endsection
