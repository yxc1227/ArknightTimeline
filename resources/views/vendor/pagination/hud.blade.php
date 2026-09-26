{{--
    分页视图。
    Laravel 默认视图输出的是 Tailwind 类名，而本项目没有引入 Tailwind（零构建），
    因此默认视图会退化成一组裸链接。这里换成与全站一致的分页母题：
    直角方块 + 等宽编号 + 「当前页 // 总页数」的计数仪表。
--}}
@if ($paginator->hasPages())
    <nav class="pagination" aria-label="分页导航">
        @if ($paginator->onFirstPage())
            <span class="is-disabled" aria-disabled="true">PREV</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">PREV</a>
        @endif

        @foreach ($elements as $element)
            {{-- 「…」省略号 --}}
            @if (is_string($element))
                <span class="is-disabled">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page === $paginator->currentPage())
                        <span aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">NEXT</a>
        @else
            <span class="is-disabled" aria-disabled="true">NEXT</span>
        @endif

        <span class="pagination__meta">
            {{ $paginator->currentPage() }} // {{ $paginator->lastPage() }}
            · 共 {{ $paginator->total() }} 条
        </span>
    </nav>
@endif
