{{--
    头像。

    三种回退顺序封装在这里，避免四个页面各写一遍：
    本地已上传的图片 → 外部渠道带来的头像 → 首字方块（initials）。

    首字方块不是「没图时的将就」：它就是这套视觉体系的一部分
    （等宽字体 + 直角 + 无图片依赖），因此没有头像时页面依然完整。
--}}
@props(['user', 'size' => 'md', 'status' => null, 'title' => null])

@php
    /** @var \App\Models\User $user */
    $url = $user->avatarUrl();
    $badge = $status ?? ($user->trashed() ? 'trashed' : $user->status()->value);
    $label = $title ?? $user->displayLabel();
@endphp

@if ($url)
    {{--
        referrerpolicy="no-referrer"：外部渠道的头像不下载到本地（省一次抓取与一处失败点），
        因此要避免把当前页面地址通过 Referer 泄给那个外部站点。
    --}}
    <img class="avatar avatar--{{ $size }}" src="{{ $url }}" alt="{{ $label }}"
         loading="lazy" decoding="async" referrerpolicy="no-referrer">
@else
    <span class="avatar avatar--{{ $size }}" data-status="{{ $badge }}"
          title="{{ $label }}" aria-hidden="true">{{ $user->initials() }}</span>
@endif
