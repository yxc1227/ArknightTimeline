@extends('layouts.app')

@section('title', '出处与语料 · 源石纪年')
@section('page', 'sources')

@section('content')
    {{-- ============================ 检索与筛选侧栏 ============================ --}}
    <aside class="sidebar">
        <form method="GET" action="{{ route('sources.index') }}" class="sidebar__form">
            <x-filter-head :reset-url="route('sources.index')"
                           placeholder="搜索名称 / 编号 / 说明"
                           :q="$filters['q']"/>

            <div class="sidebar__scroll">
                <details class="filter-group" open>
                    <summary data-en="Type">载体类型</summary>
                    <div class="filter-group__body">
                        <select name="type" data-autosubmit>
                            <option value="">全部</option>
                            @foreach ($types as $value => $label)
                                <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </details>
            </div>
        </form>
    </aside>

    {{-- ============================ 语料库主栏 ============================ --}}
    <main class="main">
        <div class="panel">
            <div class="panel__title">
                <span data-en="Source Library">出处与语料库</span>
                <span class="faint small mono">CORPUS // 溯源链的根</span>
            </div>

            <div class="alert alert--info small">
                「原文」字段是整个溯源机制的基础 —— 提交引用时记录的字符偏移就是相对这段文本计算的。
                为了让人工能核验 AI 的产出，建议把主线章节、活动剧情文本、设定集段落完整录入。
            </div>
        </div>

        <div class="panel">
            <table class="tbl">
                <thead>
                <tr>
                    <th>Source</th>
                    <th style="width:110px">Type</th>
                    <th style="width:130px">Code / Chapter</th>
                    <th style="width:88px">Entries</th>
                    <th style="width:100px">Corpus</th>
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
    </main>
@endsection
