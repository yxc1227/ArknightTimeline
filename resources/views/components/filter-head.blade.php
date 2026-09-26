{{--
    服务端筛选侧栏的固定头，与时间线 #filters 的头部同构：标题 + 重置 + 关键词输入。
    重置用普通链接而不是按钮：服务端页的「重置」就是回到不带查询串的列表，
    没有客户端状态要清，交给 URL 才是最诚实的实现。
    search 设为 false 可省去关键词框（页面上没有任何能按关键词搜的字段时才这样做）。
--}}
@props(['resetUrl', 'placeholder' => '', 'q' => null, 'search' => true])

<div class="sidebar__head">
    <div class="filter-head">
        <strong data-en="Filter">检索与筛选</strong>
        <a class="btn btn--ghost btn--sm" href="{{ $resetUrl }}"><x-icon name="reset"/>重置</a>
    </div>

    @if ($search)
        <div class="field" style="margin-bottom:0">
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ $placeholder }}" data-autosubmit>
        </div>
    @endif
</div>
