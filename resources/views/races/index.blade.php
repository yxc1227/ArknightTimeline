@extends('layouts.app')

@section('title', '种族 · 明日方舟时间线')
@section('page', 'races')

@section('content')
    <main class="main main--wide">
        <div class="panel">
            <div class="panel__title">
                <span data-en="Races">种族</span>
                <span class="faint small mono">CNT {{ str_pad((string) $races->count(), 2, '0', STR_PAD_LEFT) }}</span>
            </div>

            <div class="alert alert--info small">
                种族是出自《大地巡旅》第四章「泰拉种族」的字典，人物挂在它上面。
                它也是<strong>共享维度</strong> —— 同一种族可以出现在两个世界的历史里，
                因此这一页没有世界切换器，人物数是两个世界的合计。
                <br>
                书里未单独立目的写法（人物数据里实际用到、但书中没有专门词条的那些）
                只登记名称与人物归属，描述留空 —— <strong>宁可缺失也不要写错</strong>。
            </div>

            @forelse ($races as $race)
                <div class="card" id="race-{{ $race->slug }}">
                    <div class="row" style="align-items:baseline">
                        <strong>{{ $race->name }}</strong>
                        @if (filled($race->english))
                            <span class="faint small mono">{{ $race->english }}</span>
                        @endif
                        @if ($race->characters_count > 0)
                            <span class="chip">{{ $race->characters_count }} 位人物</span>
                        @endif
                    </div>

                    @if (filled($race->description))
                        <p class="muted small" style="margin:6px 0 0">{{ $race->description }}</p>
                    @else
                        {{-- 缺口如实呈现，而不是留白：留白会让人以为「书里没有这个种族」 --}}
                        <p class="faint small" style="margin:6px 0 0">
                            书里第四章未为这一种族单独立目，本仓库只登记了名称与人物归属。
                        </p>
                    @endif
                </div>
            @empty
                <div class="empty">尚未收录任何种族。</div>
            @endforelse
        </div>
    </main>
@endsection
