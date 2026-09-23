{{--
    全站图标组件 —— 唯一的图标渲染入口。

    形状数据全部在 App\Support\Icons（单一信息源），本组件只负责外壳：
    统一的 viewBox、线宽、端点与无障碍语义，保证任何页面里的图标
    不会出现线宽漂移或语义缺失。

    用法：
        <x-icon name="search" />                          内联装饰（默认，aria-hidden）
        <x-icon name="external" label="前往 PRTS 维基"/>   有语义时给 label → role="img"
        <x-icon name="history" class="icon--lg"/>          尺寸用修饰类，见 app.css「图标」段

    上色：图标永远 currentColor，跟随所在文字。语义色直接复用徽章的类
    （.badge--ok / .badge--warn / .badge--danger / .badge--info）即可，
    不要在模板里写死色值 —— 配色纪律只有 CSS 一处说了算。
--}}
@props(['name', 'label' => null, 'class' => ''])

@php
    /** @var string $inner 内部标记由 App\Support\Icons 严格校验，未知名字在此快速失败 */
    $inner = \App\Support\Icons::get($name);
@endphp

<svg viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="1.5"
     stroke-linecap="square" stroke-linejoin="miter"
     class="icon {{ $class }}"
     @if ($label)
         role="img" aria-label="{{ $label }}"
     @else
         aria-hidden="true"
     @endif
>{!! $inner !!}</svg>
