<?php

namespace App\Support;

/**
 * 全站图标注册表 —— 图标的唯一信息源。
 *
 * ## 造型语言（对齐 docs/DESIGN.md §1.4）
 *
 * 图标不是从图标库挑一圈凑数，而是把整套视觉系统的母题翻译成 24×24 的线稿：
 *
 *   1. 单色线稿：stroke 全部取 currentColor，图标永远跟随所在文字的颜色
 *      （.badge--danger 里的图标自动是红的，.btn--primary 里自动是黑墨），
 *      因此注册表里不出现任何色值 —— 上色是 CSS 的事，这里只有形状。
 *   2. 直角 + 单角斜切：容纳盒一律直角，唯一允许的弧线是几何体本身
 *      （圆、行星环），容器类形状（卡片、锁体、状态框）统一在右下角做斜切，
 *      与标记的斜切方块（App\Support\Logo）/ .btn--primary 的 clip-path 同一母题。
 *   3. 「//」与「[方括号]」母题入图：出处 = 文档内两个平行斜杠，
 *      审核台 = 方括号包一枚对勾，告警 = 方括号包一条脉冲线 ——
 *      让图标和文字层讲同一种语言。
 *   4. 线宽 1.5、端点 square、拐角 miter：小尺寸下依然保持「仪表刻线」的锐利感，
 *      与等宽微标签、网格底纹观感一致。
 *
 * ## 使用方式
 *
 * 一律经由视图组件 `resources/views/components/icon.blade.php` 渲染
 * （`<x-icon name="search" />`），不要在模板里手写 <svg> ——
 * 否则 stroke 属性、无障碍语义与尺寸约定会各处漂移。
 *
 * 新增图标的顺序：先在本表登记（含一行用途注释），再在 docs/ICONS.md
 * 的清单里补一行。注册表与文档漂移时以本文件为准。
 */
final class Icons
{
    /**
     * 键为 kebab-case 图标名，值为 24×24 viewBox 的**内部**标记
     * （不含 <svg> 外壳，外壳属性由组件统一给出）。
     *
     * 分组即清单（详见 docs/ICONS.md）：
     *   导航 / 世界 / 操作 / 状态 / 界面控件。
     */
    public const ICONS = [
        // ---- 导航：顶栏五个工作区 + 账号 ------------------------------------
        'timeline' => '<path d="M3 12h18"/><path d="M7 8.5V12M16.5 8.5V12"/><rect x="10.25" y="9.75" width="3.5" height="3.5"/>',
        'operators' => '<path d="M4 5.5h16v10L16.5 19H4z"/><circle cx="10" cy="11" r="2.4"/><path d="M6.2 16.5c.6-1.7 2-2.8 3.8-2.8s3.2 1.1 3.8 2.8"/><path d="M14.5 9h2.5M14.5 12h2.5"/>',
        'sources' => '<path d="M6.5 3.5h11v17h-11z"/><path d="M9 6.5h6"/><path d="M12.2 9.5l-2.4 6M15.2 9.5l-2.4 6"/>',
        'review' => '<path d="M7 4.5H4.5v15H7M17 4.5h2.5v15H17"/><path d="M9 12.3l2.3 2.3 4.7-5.6"/>',
        'inbox' => '<path d="M4 19.5v-6h4.5l2 3h3l2-3H20v6z"/><path d="M6.5 9.5h2.5l1.5-4 2.6 7 1.6-3h2.8"/>',
        'account' => '<circle cx="9.5" cy="8.5" r="3.2"/><path d="M4 19.5c.6-3 2.8-5 5.5-5s4.9 2 5.5 5"/><path d="M15.5 5.6a3.2 3.2 0 0 1 0 5.8"/><path d="M17.5 14.9c1.7.8 2.9 2.4 3.3 4.6"/>',

        // ---- 世界：世界切换器、按世界分组的详情区 ---------------------------
        'world-terra' => '<path d="M12 3l7.5 4.3v9.4L12 21l-7.5-4.3V7.3z"/><path d="M4.5 7.3L12 11.6l7.5-4.3"/><path d="M12 11.6V21"/>',
        'world-talos' => '<circle cx="12" cy="12.5" r="5.5"/><path d="M2.5 13.5c2.5 3.8 16.5 3.8 19-1"/><rect x="16.5" y="4" width="3.5" height="3.5"/>',

        // ---- 操作：按钮、抽屉、表格行内动作 ---------------------------------
        'search' => '<circle cx="11" cy="11" r="5.5"/><path d="M15.3 15.3L20 20"/>',
        'filter' => '<path d="M4 7h3.2M10.8 7H20"/><rect x="7.2" y="5.4" width="3.6" height="3.2"/><path d="M4 12h8.2M15.8 12H20"/><rect x="12.2" y="10.4" width="3.6" height="3.2"/><path d="M4 17h2.2M8.8 17H20"/><rect x="6.2" y="15.4" width="3.6" height="3.2"/>',
        'reset' => '<path d="M20.5 12a8.5 8.5 0 1 1-8.5-8.5c2.38 0 4.54.94 6.14 2.48L20.5 8.3"/><path d="M20.5 3.5v4.8h-4.8"/>',
        'edit' => '<path d="M5 19l1.3-4.4 9.3-9.3 3.1 3.1-9.3 9.3z"/><path d="M13.5 7.4l3.1 3.1"/>',
        'trash' => '<path d="M4.5 6.5h15"/><path d="M9.5 6.5V4h5v2.5"/><path d="M6.5 6.5l1 13.5h9l1-13.5"/><path d="M10 10.5v6M14 10.5v6"/>',
        'plus' => '<path d="M12 4.5v15M4.5 12h15"/>',
        'merge' => '<path d="M4 6.5h4.2L13 12M4 17.5h4.2L13 12M13 12h8"/><path d="M18.3 9.3L21 12l-2.7 2.7"/>',
        'check' => '<path d="M4.5 12.5l4.7 4.7L19.5 7"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'lock' => '<path d="M6 10.5h12V18.5L16 20H6z"/><path d="M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3"/><path d="M12 14v2.5"/>',
        'unlock' => '<path d="M6 10.5h12V18.5L16 20H6z"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 6.9-.9"/><path d="M12 14v2.5"/>',
        'external' => '<path d="M13.5 4.5H4.5v15h15v-9"/><path d="M11 13L19.5 4.5"/><path d="M14 4.5h5.5V10"/>',
        'copy' => '<path d="M15 3.5H4v11h2"/><path d="M8.5 8.5h12v9.5l-2 2h-10z"/>',
        'download' => '<path d="M12 4v9.5"/><path d="M8 9.5l4 4 4-4"/><path d="M4 16.5v3.5h16v-3.5"/>',
        'scan' => '<path d="M4 8.5V4h4.5M15.5 4H20v4.5M20 15.5V20h-4.5M8.5 20H4v-4.5"/><path d="M7.5 12h3.4M13.1 12h3.4"/><rect x="10.9" y="10.9" width="2.2" height="2.2" fill="currentColor" stroke="none"/>',
        'history' => '<path d="M3.5 12a8.5 8.5 0 1 0 8.5-8.5c-2.4 0-4.6 1-6.2 2.5L3.5 8.4"/><path d="M3.5 3.5v4.9h4.9"/><path d="M12 7.5V12l3.2 1.9"/>',
        'comment' => '<path d="M4 4.5h16v11.5h-8.5l-4 4V16H4z"/><path d="M8 9h8M8 12h5"/>',
        'back' => '<path d="M19.5 12h-15"/><path d="M10 6.5L4.5 12l5.5 5.5"/>',
        'spark' => '<path d="M12 3.5l2.2 6.3 6.3 2.2-6.3 2.2L12 20.5l-2.2-6.3-6.3-2.2 6.3-2.2z"/>',

        // ---- 状态：徽章、提示条、时间轴节点 ---------------------------------
        'status-pending' => '<rect x="5" y="5" width="14" height="14"/><rect x="10.75" y="10.75" width="2.5" height="2.5" fill="currentColor" stroke="none"/>',
        'status-ok' => '<rect x="4.5" y="4.5" width="15" height="15"/><path d="M8.5 12.3l2.4 2.4 4.8-5.4"/>',
        'status-warn' => '<path d="M12 4.5L21 19.5H3z"/><path d="M12 10.5v4"/><rect x="11.6" y="16.4" width="0.8" height="1.7" fill="currentColor" stroke="none"/>',
        'status-danger' => '<path d="M8.2 4.5h7.6l3.7 3.7v7.6l-3.7 3.7H8.2l-3.7-3.7V8.2z"/><path d="M9.5 9.5l5 5M14.5 9.5l-5 5"/>',
        'status-info' => '<rect x="4.5" y="4.5" width="15" height="15"/><rect x="11.6" y="7.6" width="0.8" height="1.7" fill="currentColor" stroke="none"/><path d="M12 11v5.5"/>',
        'status-anomaly' => '<path d="M6.5 4.5H4v15h2.5M17.5 4.5H20v15h-2.5"/><path d="M6.5 12h2.3l1.5-3.5 2.6 7 1.6-3.5h2.5"/>',

        // ---- 界面控件：抽屉、下拉、折叠、排序 -------------------------------
        'chevron-down' => '<path d="M6.5 9.5l5.5 5.5 5.5-5.5"/>',
        'chevron-left' => '<path d="M14.5 6.5L9 12l5.5 5.5"/>',
        'sort' => '<path d="M8.5 9.5L12 6l3.5 3.5M8.5 14.5L12 18l3.5-3.5"/>',
    ];

    /**
     * 取单个图标的内部标记。名字不存在时直接抛异常：
     * 缺图标是模板写错了名字，静默输出空 <svg> 只会把错误推迟到肉眼发现。
     */
    public static function get(string $name): string
    {
        if (! isset(self::ICONS[$name])) {
            throw new \InvalidArgumentException(
                "未注册的图标「{$name}」。可用图标见 App\\Support\\Icons::ICONS 或 docs/ICONS.md。"
            );
        }

        return self::ICONS[$name];
    }

    /** 图标是否存在（供条件渲染使用，避免调用方 try/catch）。 */
    public static function has(string $name): bool
    {
        return isset(self::ICONS[$name]);
    }
}
