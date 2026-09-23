{{--
    项目标记（logo）组件 —— 页面内渲染标记的唯一入口。

    几何数据全部在 App\Support\Logo（单一信息源），本组件只负责外壳与配色挂钩：
    形状在 PHP，颜色在 CSS（.logo__box / .logo__ink），与图标系统同一分工 ——
    因此这里不出现任何色值，换肤只改 CSS 变量。

    用法：
        <x-logo/>                              装饰用（默认，顶栏这类「旁边已有文字」的场景）
        <x-logo class="logo--lg"/>             放大（登录页、空状态，尺寸见 app.css「标识」段）
        <x-logo label="明日方舟时间线"/>        独立出现时给语义标签

    无障碍：标记在顶栏里紧挨站点名文字，重复朗读反而啰嗦，故默认 aria-hidden；
    只有脱离文字的独立展示才传 label —— 与 <x-icon> 同一套约定。
--}}
@props(['label' => null, 'class' => ''])

<svg viewBox="{{ \App\Support\Logo::VIEW_BOX }}" class="logo {{ $class }}"
     @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif
>
    <path class="logo__box" d="{{ \App\Support\Logo::CONTAINER }}"/>
    <g class="logo__ink" fill-rule="evenodd">
        @foreach (\App\Support\Logo::GLYPH as $d)
            <path d="{{ $d }}"/>
        @endforeach
    </g>
</svg>
