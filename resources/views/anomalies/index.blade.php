@extends('layouts.app')

@section('title', '一致性收件箱 · 泰拉时间线')
@section('page', 'anomalies')

@section('content')
    <div style="flex:1;min-width:0">
        <div class="panel">
            <div class="panel__title">
                <span>时间线一致性收件箱</span>
                @if ($canReview)
                    <button class="btn btn--sm" id="scan">全量体检</button>
                @endif
            </div>

            <div class="alert alert--info small">
                一致性不靠「编辑时加锁」保证，而靠「写入后立即体检 + 持续巡检」。绝大多数不一致是语义层的
                （比如 A 把事件定在 1097 年、B 把它的起因定在 1099 年），只能在写入之后用规则收敛。
                本轮巡检未复现的问题会自动销案，因此这里剩下的每一条都代表**此刻仍然存在**的问题。
            </div>

            <div class="stats-line" style="margin-bottom:12px">
                @foreach ($summary as $severity => $total)
                    <span>{{ $severity }}: {{ $total }}</span>
                @endforeach
                @if (empty($summary)) <span>当前没有未处置异常</span> @endif
            </div>

            <form method="GET" class="row" style="align-items:flex-end">
                <div class="field">
                    <label>类型</label>
                    <select name="type" onchange="this.form.submit()">
                        <option value="">全部</option>
                        @foreach (\App\Enums\AnomalyType::cases() as $type)
                            <option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>级别</label>
                    <select name="severity" onchange="this.form.submit()">
                        <option value="">全部</option>
                        @foreach (\App\Enums\AnomalySeverity::cases() as $severity)
                            <option value="{{ $severity->value }}" @selected(request('severity') === $severity->value)>{{ $severity->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>状态</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="open" @selected(request('status', 'open') === 'open')>未处置</option>
                        <option value="resolved" @selected(request('status') === 'resolved')>已解决</option>
                        <option value="ignored" @selected(request('status') === 'ignored')>已忽略</option>
                        <option value="all" @selected(request('status') === 'all')>全部</option>
                    </select>
                </div>
            </form>
        </div>

        <div class="panel">
            <table class="tbl">
                <thead>
                <tr>
                    <th style="width:78px">级别</th>
                    <th style="width:96px">类型</th>
                    <th>说明</th>
                    <th style="width:230px">涉及条目</th>
                    <th style="width:140px">操作</th>
                </tr>
                </thead>
                <tbody id="anomalies-host">
                @forelse ($anomalies as $anomaly)
                    @php $payload = $anomaly->toApiArray(); @endphp
                    <tr>
                        <td><span class="badge {{ $payload['severity_badge'] }}">{{ $payload['severity_label'] }}</span></td>
                        <td class="small">{{ $payload['type_label'] }}</td>
                        <td class="small">{{ $anomaly->message }}</td>
                        <td class="small">
                            @if ($payload['event'])
                                <a href="{{ route('timeline.index') }}?q={{ urlencode($payload['event']['title']) }}" target="_blank">
                                    {{ $payload['event']['title'] }}
                                </a>
                                <span class="faint mono">{{ $payload['event']['date_display'] }}</span>
                            @endif
                            @if ($payload['related_event'])
                                <div class="faint">↳ 关联：{{ $payload['related_event']['title'] }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($canReview && $anomaly->status === 'open')
                                <div class="btn-row">
                                    <button class="btn btn--sm btn--ok" data-anomaly="{{ $anomaly->id }}" data-status="resolved">已解决</button>
                                    <button class="btn btn--sm" data-anomaly="{{ $anomaly->id }}" data-status="ignored">忽略</button>
                                </div>
                            @else
                                <span class="faint small">{{ $anomaly->status }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="faint" style="padding:30px;text-align:center">没有符合条件的异常。</td></tr>
                @endforelse
                </tbody>
            </table>

            {{ $anomalies->links() }}
        </div>
    </div>
@endsection
