@extends('layouts.app')

@section('title', $world->label().'词条 · 明日方舟时间线')
@section('page', 'terms')

@section('content')
    <main class="main main--wide">
        {{-- 世界切换器与时间线、地名、组织页同形 --}}
        <div class="world-switch">
            @foreach ($worlds as $option)
                @php $isActive = $world->value === $option['value']; @endphp

                <a class="world-switch__item" href="{{ route('terms.index', ['world' => $option['value']]) }}"
                   data-active="{{ $isActive ? '1' : '0' }}"
                   style="--world-accent: {{ $option['accent'] }}"
                   title="{{ $option['description'] }}">
                    <x-icon name="{{ $option['value'] === 'talos' ? 'world-talos' : 'world-terra' }}" class="icon--lg"/>
                    <span class="world-switch__label">{{ $option['label'] }}</span>
                    <span class="world-switch__meta mono">
                        {{ $option['english'] }} ·
                        {{ $isActive ? $visible.' 条词条' : '' }}
                    </span>
                </a>
            @endforeach
        </div>

        <p class="world-switch__tagline faint small">{{ $world->tagline() }}</p>

        <div class="panel">
            <div class="panel__title">
                <span data-en="Terms">{{ $world->label() }}词条</span>
                <span class="faint small mono">CNT {{ str_pad((string) $visible, 2, '0', STR_PAD_LEFT) }}</span>
            </div>

            {{-- 词条不附引文，因此不受「出处可定位」那条闸门约束。这件事必须写在页面上：
                 读者有权知道哪一段是逐字引文、哪一段是本仓库的转写 --}}
            <div class="alert alert--info small">
                词条是原书里给出专门解释的术语与专名。释义是本仓库据相应章节写下的
                <strong>转写概括</strong>，不是原文摘录，因此不附引文
                （时间线条目里的引文才是逐字核对过的）。
                每条都标注了出处章节，供读者自行回查。
                <br>
                词条<strong>按世界分列</strong>：一条词条要么属于某一个世界，要么是两边都成立的
                <strong>通用</strong>概念 —— 后者会带「通用」标记，并在两页都列出
                {{ $shared > 0 ? '（现有 '.$shared.' 条）' : '' }}。
            </div>

            @forelse ($terms as $category => $group)
                <div class="section-label">{{ \App\Models\Term::CATEGORIES[$category] ?? $category }}</div>

                @foreach ($group as $term)
                    <div class="card" id="term-{{ $term->slug }}">
                        <div class="row" style="align-items:baseline">
                            <strong>{{ $term->name }}</strong>
                            @if (filled($term->origin))
                                <span class="faint small mono">{{ $term->origin }}</span>
                            @endif
                            @if ($term->isShared())
                                {{-- 通用词条必须在**两页**都标出来：否则读者在另一页看到它，
                                     会以为这一页漏了它 --}}
                                <span class="badge badge--info" title="两个世界都成立的概念">通用</span>
                            @endif
                        </div>
                        <p class="muted small" style="margin:6px 0 0">{{ $term->definition }}</p>
                    </div>
                @endforeach
            @empty
                {{-- 空页要说清**为什么空**：否则读者会以为这一页坏了 --}}
                <div class="empty">
                    本仓库尚未收录{{ $world->label() }}的词条。<br>
                    <span class="small">
                        现有条目全部出自《大地巡旅》—— 一部<strong>泰拉视角</strong>的著作，
                        因此都归在泰拉名下；塔卫二的词条等对应的出处录入后补充。
                    </span>
                </div>
            @endforelse
        </div>
    </main>
@endsection
