@extends('layouts.app')

@section('title', '种族 · 源石纪年')
@section('page', 'races')

@section('content')
    {{-- ============================ 检索与筛选侧栏 ============================ --}}
    {{-- 种族是共享维度、不分世界，侧栏因此只有关键词检索，没有切换器 --}}
    <aside class="sidebar">
        <form method="GET" action="{{ route('races.index') }}" class="sidebar__form">
            <x-filter-head :reset-url="route('races.index')"
                           placeholder="搜索名称 / 英文名 / 说明"
                           :q="$filters['q']"/>
        </form>
    </aside>

    {{-- ============================ 种族主栏 ============================ --}}
    <main class="main">
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

            @if ($races->isEmpty())
                <div class="empty">
                    @if ($filters['q'])
                        {{-- 「没搜到」与「还没收录」要分开说：前者该换关键词，后者是语料的缺口 --}}
                        没有符合筛选条件的种族。<br>
                        <span class="small">换个关键词，或点侧栏「重置」看全部。</span>
                    @else
                        尚未收录任何种族。
                    @endif
                </div>
            @endif
        </div>

        {{--
            与干员简介同一套块式：卡片网格（磨砂玻璃 + 入场错峰都随 .operator-card 自动生效）。
            卡片必须放在 .panel 之外 —— 面板是不透明底，卡片浮在它上面时玻璃就透不出背后的光。
            种族没有徽记也没有详情页：头部从名字开始，说明直接全文展示（它本身就是内容）。
        --}}
        <div class="operator-grid">
            @foreach ($races as $race)
                <article class="operator-card" id="race-{{ $race->slug }}">
                    <header class="operator-card__head">
                        <div class="operator-card__id">
                            <div style="min-width:0">
                                <strong class="operator-card__name">{{ $race->name }}</strong>
                                @if (filled($race->english))
                                    <div class="operator-card__code mono">{{ $race->english }}</div>
                                @endif
                            </div>
                        </div>
                    </header>

                    @if ($race->characters_count > 0)
                        <div class="chips">
                            <span class="chip">{{ $race->characters_count }} 位人物</span>
                        </div>
                    @endif

                    @if (filled($race->description))
                        <p class="operator-card__profile operator-card__profile--full">{{ $race->description }}</p>
                    @else
                        {{-- 缺口如实呈现，而不是留白：留白会让人以为「书里没有这个种族」 --}}
                        <p class="operator-card__profile operator-card__profile--full operator-card__profile--missing">
                            书里第四章未为这一种族单独立目，本仓库只登记了名称与人物归属。
                        </p>
                    @endif
                </article>
            @endforeach
        </div>
    </main>
@endsection
