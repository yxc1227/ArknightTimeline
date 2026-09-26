<?php

namespace App\Support;

/**
 * 项目标记（logo）的几何定义 —— 唯一信息源。
 *
 * ## 是什么
 *
 * 一个 32×32 的矢量标记：整幅斜切方块 + 墨色源石晶体 + 基准轴。
 * 2026-09-24 站点更名为「源石纪年 / Originium Chronicle」后，
 * 原字母组合「AT」不再对应任何现实缩写，标记改以**源石晶体**承载品牌 ——
 * 「源石」是本站考据时间线的核心对象，「纪年」由晶体下方的基准轴承接。
 *
 * ## 三个母题（都来自 docs/DESIGN.md §1.4，不是凭空造型）
 *
 *   1. **斜切方块**：外框右下角 9 单位的斜切，比例与 `.btn--primary` 的
 *      clip-path（28px 上切 8px ≈ 0.29）一致 —— 标记与界面是同一套形状语言。
 *   2. **源石晶体**：直立双锥六边形（宽 11.6 / 高 15.4，上下尖对称），
 *      中央一道两端收尖的菱形**晶棱**（evenodd 挖空，露出标志黄）——
 *      晶棱把晶体分成左右两个晶面，是「晶体」读感的关键；实心六边形
 *      会被读成盾牌/六角螺母。上下都收尖是为了不读成「房子」（首版
 *      尖顶 + 直墙 + 浅底尖的构图就是栽在这里）。
 *      全部直线折角，无弧线 —— 与全站「直角、无圆滑」同一条纪律。
 *   3. **基准轴**：晶体下方一条贯通的细横线（1.4 单位厚，与晶体留 1.5 单位空隙），
 *      跨度略宽于晶体（每侧探出 2 单位）—— 读作「承托晶体的基座」，
 *      语义 = 时间轴托着源石纪元里的每一个事件。
 *      （这一元素自「AT」时代沿用：当时它托着字母，现在托着晶体。）
 *
 * ## 边界
 *
 * 全部为原创几何，**不描摹鹰角网络的任何官方标识**（与全站「不使用官方素材」同一条红线）。
 *
 * ## 改动须知
 *
 * 改这里的坐标，等于改全站标记：顶栏（`<x-logo>` 组件）、
 * 浏览器标签图标（`public/favicon.svg` / `favicon.ico`）与
 * `docs/logo-preview.html` 都由它派生。改完请重新生成后两者（见 docs/LOGO.md §6）。
 */
final class Logo
{
    /** 画布：32×32，整幅铺满（无透明留白），因此可直接当 favicon 几何用。 */
    public const VIEW_BOX = '0 0 32 32';

    /** 外框：右下角 9 单位斜切，与 .btn--primary 的切角比例一致。 */
    public const CONTAINER = 'M0 0H32V23L23 32H0Z';

    /**
     * 字形 path 列表。**每一项都渲染成独立的 <path>**，这一点是硬约束：
     * 组件对整组字形施加 fill-rule="evenodd"，但 evenodd 只在**同一条 path
     * 的子路径之间**做异或 —— 跨 path 不挖空（实测验证过：晶棱单独成 path
     * 时画出来是实心）。因此：
     *   - 晶体「外轮廓 + 晶棱」必须写进**同一条 path**（两个子路径），
     *     靠 evenodd 挖出晶棱 —— 与旧版 A「外轮廓 + 内孔」同一机制；
     *   - 基准轴是实心独立形状，单独一条 path。
     */
    public const GLYPH = [
        // 晶体：外轮廓 + 中央晶棱（两个子路径，evenodd 挖出黄色晶棱）
        'M16 6.4L21.8 11L21.8 17.2L16 21.8L10.2 17.2L10.2 11Z'
            . 'M16 7.6L16.8 14.1L16 20.6L15.2 14.1Z',
        // 基准轴：纪年轴，跨度略宽于晶体（8.2–23.8，每侧探出 2 单位）
        'M8.2 23.3H23.8V24.7H8.2Z',
    ];

    /** 方块色 = 标志黄（与 --accent 同值）。 */
    public const COLOR_BOX = '#ffd400';

    /** 晶体色 = 近黑墨色（与 --accent-ink 同值）。 */
    public const COLOR_INK = '#0d0d0d';

    /**
     * 生成独立可用的 favicon SVG（色值内联）。
     *
     * 为什么单独生成而不是直接引用组件：`public/*.svg` 是脱离页面的静态文件，
     * 拿不到 CSS 变量与 currentColor，因此这里把配色写死为常量；
     * 页面内的标记仍由组件的 CSS 类上色。两处颜色都取自下面的常量，不会分叉。
     */
    public static function faviconSvg(): string
    {
        $paths = '';
        foreach (self::GLYPH as $d) {
            $paths .= '<path d="' . $d . '"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . self::VIEW_BOX . '" width="32" height="32">'
            . '<!-- 由 App\Support\Logo 生成：改标记请改该文件后重新生成（docs/LOGO.md §6） -->'
            . '<path fill="' . self::COLOR_BOX . '" d="' . self::CONTAINER . '"/>'
            . '<g fill="' . self::COLOR_INK . '" fill-rule="evenodd">' . $paths . '</g>'
            . '</svg>';
    }
}
