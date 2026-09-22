@extends('layouts.app')

@section('title', '出处与语料 · 泰拉时间线')
@section('page', 'sources')

@section('content')
    <div style="flex:1;min-width:0">
        <div class="panel">
            <div class="panel__title">
                <span>出处与语料库</span>
                <span class="faint small">出处是溯源链的根：AI 抽取的每条引用都要能回到这里的原文定位</span>
            </div>

            <div class="alert alert--info small">
                「原文」字段是整个溯源机制的基础 —— 提交引用时记录的字符偏移就是相对这段文本计算的。
                为了让人工能核验 AI 的产出，建议把主线章节、活动剧情文本、设定集段落完整录入。
            </div>

            <form method="GET" class="row" style="align-items:flex-end">
                <div class="field" style="max-width:220px">
                    <label>载体类型</label>
                    <select name="type" onchange="this.form.submit()">
                        <option value="">全部</option>
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
        </div>

        <div class="panel">
            <table class="tbl">
                <thead>
                <tr>
                    <th>出处</th>
                    <th style="width:110px">类型</th>
                    <th style="width:130px">编号 / 章节</th>
                    <th style="width:80px">收录条目</th>
                    <th style="width:100px">原文语料</th>
                    <th style="width:80px"></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($sources as $source)
                    <tr>
                        <td>
                            <strong>{{ $source->name }}</strong>
                            @if ($source->description)
                                <div class="faint small">{{ \Illuminate\Support\Str::limit($source->description, 90) }}</div>
                            @endif
                        </td>
                        <td><span class="badge">{{ $source->type->label() }}</span></td>
                        <td class="mono small">
                            {{ $source->code ?: '—' }}
                            @if ($source->chapter) <div class="faint">{{ $source->chapter }}</div> @endif
                        </td>
                        <td class="mono">{{ $source->events_count }}</td>
                        <td>
                            @if ($source->raw_text)
                                <span class="badge badge--ok">已录入</span>
                            @else
                                <span class="badge badge--muted">缺失</span>
                            @endif
                        </td>
                        <td>
                            <a class="btn btn--sm" href="{{ route('sources.show', $source) }}">打开</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="faint" style="padding:30px;text-align:center">还没有登记出处。</td></tr>
                @endforelse
                </tbody>
            </table>

            {{ $sources->links() }}
        </div>
    </div>
@endsection
