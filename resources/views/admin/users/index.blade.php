@extends('layouts.app')

@section('title', '账号管理 · 源石纪年')
@section('page', 'users')

@php
    /**
     * 排序表头。逻辑放在视图里而不是控制器：它只影响呈现，
     * 并且需要同时知道当前筛选条件（换列排序时不能把筛选丢掉）。
     */
    $sortLink = function (string $key, string $label) use ($sort, $direction, $filters): string {
        $isActive = $sort === $key;
        $next = $isActive && $direction === 'asc' ? 'desc' : 'asc';

        $url = route('admin.users.index', array_filter([
            'q' => $filters['q'],
            'role' => $filters['role'],
            'status' => $filters['status'],
            'trashed' => $filters['trashed'],
            'sort' => $key,
            'direction' => $next,
        ]));

        $arrow = $isActive ? ($direction === 'asc' ? '▲' : '▼') : '';

        return '<a class="th-sort'.($isActive ? ' is-active' : '').'" href="'.e($url).'">'
            .e($label).($arrow !== '' ? ' <span class="th-sort__arrow">'.$arrow.'</span>' : '')
            .'</a>';
    };
@endphp

@section('content')
    {{-- ============================ 搜索与筛选 ============================ --}}
    <aside class="sidebar">
        {{-- data-loading-target：提交前给表格盖半透明，加载态只能在这个阶段表达（通用绑定读取它） --}}
        <form method="GET" action="{{ route('admin.users.index') }}" class="sidebar__form"
              data-loading-target="#users-table-wrap">
            {{-- 排序状态随筛选一起提交，否则改动筛选会把排序重置掉 --}}
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">

            <x-filter-head :reset-url="route('admin.users.index')"
                           placeholder="登录名 / 昵称 / 邮箱"
                           :q="$filters['q']"/>

            <div class="sidebar__scroll">
                <details class="filter-group" open>
                    <summary data-en="Role">角色</summary>
                    <div class="filter-group__body">
                        <select name="role" data-autosubmit>
                            <option value="">全部角色</option>
                            @foreach ($roles as $value => $label)
                                <option value="{{ $value }}" @selected($filters['role'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </details>

                <details class="filter-group" open>
                    <summary data-en="Status">账号状态</summary>
                    <div class="filter-group__body">
                        <select name="status" data-autosubmit>
                            <option value="">全部状态</option>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </details>

                <details class="filter-group" open>
                    <summary data-en="Recycle">回收状态</summary>
                    <div class="filter-group__body">
                        <select name="trashed" data-autosubmit>
                            <option value="" @selected($filters['trashed'] === null)>仅未删除（默认）</option>
                            <option value="with" @selected($filters['trashed'] === 'with')>含已删除</option>
                            <option value="only" @selected($filters['trashed'] === 'only')>只看已删除</option>
                        </select>
                        <div class="faint small" style="margin-top:8px">
                            删除是软删除：账号从列表消失，但其条目归属与操作日志全部保留，可随时恢复。
                        </div>
                    </div>
                </details>
            </div>
        </form>

        <div class="sidebar__foot">
            <button class="btn btn--primary" style="width:100%" data-new-user>+ 新建账号</button>
        </div>
    </aside>

    {{-- ============================ 账号列表 ============================ --}}
    <main class="main">
        <div class="panel">
            <div class="panel__title">
                <span data-en="Account">账号管理</span>
                <span class="stats-line">
                    <span>TOTAL {{ str_pad($counters['total'], 2, '0', STR_PAD_LEFT) }}</span>
                    <span>ACTIVE {{ str_pad($counters['active'], 2, '0', STR_PAD_LEFT) }}</span>
                    <span>DISABLED {{ str_pad($counters['disabled'], 2, '0', STR_PAD_LEFT) }}</span>
                    <span>ADMIN {{ str_pad($counters['admins'], 2, '0', STR_PAD_LEFT) }}</span>
                    <span>TRASHED {{ str_pad($counters['trashed'], 2, '0', STR_PAD_LEFT) }}</span>
                </span>
            </div>

            @if ($counters['admins'] <= 1)
                <div class="alert alert--info">
                    系统中仅剩 <strong>{{ $counters['admins'] }}</strong> 个启用中的管理员。
                    为保证始终有人能管理账号，最后一个管理员无法被降级、禁用或删除。
                </div>
            @endif
        </div>

        <div class="panel">
            {{-- 批量操作栏：仅在勾选后出现，避免平时占用视觉重量 --}}
            <div class="bulk-bar" id="bulk-bar">
                <strong>已选 <span id="bulk-count">0</span> 项</strong>
                <button class="btn btn--sm" type="button" data-bulk="activate">批量启用</button>
                <button class="btn btn--sm" type="button" data-bulk="deactivate">批量禁用</button>
                <button class="btn btn--danger btn--sm" type="button" data-bulk="delete">批量删除</button>
                <button class="btn btn--ghost btn--sm" type="button" id="bulk-clear">取消选择</button>
            </div>

            <div class="table-wrap" id="users-table-wrap">
                <table class="tbl tbl--stack">
                    <thead>
                    <tr>
                        <th style="width:38px">
                            <input type="checkbox" id="select-all" aria-label="全选本页账号">
                        </th>
                        {{-- 昵称与登录名是分离的两个标识，两个都可以排序 --}}
                        <th style="width:230px">
                            {!! $sortLink('nickname', 'User') !!}
                            <span class="faint">/</span>
                            {!! $sortLink('name', 'ID') !!}
                        </th>
                        <th style="width:210px">{!! $sortLink('email', 'Email') !!}</th>
                        <th style="width:100px">{!! $sortLink('role', 'Role') !!}</th>
                        <th style="width:110px">{!! $sortLink('is_active', 'Status') !!}</th>
                        <th style="width:160px">{!! $sortLink('last_login_at', 'Last Login') !!}</th>
                        <th style="width:110px">{!! $sortLink('created_at', 'Created') !!}</th>
                        <th style="width:230px">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($users as $user)
                        @php $isSelf = $user->id === $currentUser->id; @endphp
                        <tr data-user-id="{{ $user->id }}" class="{{ $user->trashed() ? 'is-trashed' : '' }}">
                            <td data-label="Select">
                                <input type="checkbox" class="row-check" value="{{ $user->id }}"
                                       aria-label="选择 {{ $user->displayLabel() }}"
                                       @disabled($user->trashed())>
                            </td>
                            <td data-label="User">
                                <div class="user-cell">
                                    <x-avatar :user="$user" size="md"/>

                                    <div style="min-width:0">
                                        <a href="{{ route('admin.users.show', $user) }}" class="user-cell__name">
                                            {{ $user->nickname }}
                                        </a>
                                        <div class="faint small mono nowrap" style="overflow:hidden;text-overflow:ellipsis">
                                            {{ $user->name }}
                                            @if ($user->identities->isNotEmpty())
                                                <span class="badge badge--muted">{{ $user->identities->count() }} LINKED</span>
                                            @endif
                                            @if ($isSelf) · SELF @endif
                                            @if ($user->trashed()) · DELETED @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Email" class="mono small" style="word-break:break-all">{{ $user->email }}</td>
                            <td data-label="Role">
                                <span class="badge {{ $user->role()->atLeast(\App\Enums\UserRole::Reviewer) ? 'badge--info' : 'badge--muted' }}">
                                    {{ $user->role()->label() }}
                                </span>
                            </td>
                            <td data-label="Status">
                                @if ($user->trashed())
                                    <span class="badge badge--danger">已删除</span>
                                @else
                                    <span class="badge {{ $user->status()->badgeClass() }}">{{ $user->status()->label() }}</span>
                                @endif
                            </td>
                            <td data-label="Last Login" class="mono small">
                                @if ($user->last_login_at)
                                    <span title="{{ $user->last_login_ip ?? '未记录 IP' }}">
                                        {{ $user->last_login_at->format('Y // m / d H:i') }}
                                    </span>
                                @else
                                    <span class="faint">从未登录</span>
                                @endif
                            </td>
                            <td data-label="Created" class="mono small faint">
                                {{ $user->created_at?->format('Y // m / d') }}
                            </td>
                            <td data-label="Action">
                                <div class="row-actions">
                                    @if ($user->trashed())
                                        @can('restore', \App\Models\User::class)
                                            <button class="btn btn--sm btn--ok" type="button" data-restore="{{ $user->id }}">恢复账号</button>
                                        @endcan
                                    @else
                                        @can('update', $user)
                                            <button class="btn btn--sm" type="button"
                                                    data-edit="{{ $user->id }}"
                                                    data-payload="{{ json_encode($user->toApiArray(), JSON_UNESCAPED_UNICODE) }}">
                                                编辑
                                            </button>
                                        @endcan

                                        @can('resetPassword', $user)
                                            <button class="btn btn--sm" type="button" data-reset="{{ $user->id }}"
                                                    data-name="{{ $user->displayLabel() }}">重置密码</button>
                                        @endcan

                                        @can('toggleActive', $user)
                                            <button class="btn btn--sm" type="button" data-toggle="{{ $user->id }}"
                                                    data-active="{{ $user->isActive() ? '1' : '0' }}"
                                                    data-name="{{ $user->displayLabel() }}">
                                                {{ $user->isActive() ? '禁用' : '启用' }}
                                            </button>
                                        @endcan

                                        @can('delete', $user)
                                            <button class="btn btn--danger btn--sm" type="button" data-delete="{{ $user->id }}"
                                                    data-name="{{ $user->displayLabel() }}">删除</button>
                                        @endcan

                                        @if ($isSelf && ! auth()->user()->can('delete', $user))
                                            <span class="faint small">当前登录账号</span>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="empty">
                                    没有符合条件的账号。<br>
                                    <span class="small">可以放宽筛选条件，或新建一个账号。</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{ $users->links() }}
        </div>
    </main>

    @include('admin.users.partials.modals')
@endsection
