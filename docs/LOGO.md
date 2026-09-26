# 项目标记（logo）· 规范

几何：`app/Support/Logo.php`（单一信息源）· 页面内渲染：`<x-logo>` 组件 · 样式：`app.css`「标识」段。

标记就是顶栏左下角那一枚：**斜切黄方块 + 墨色源石晶体 + 基准轴**。
本文说明它是怎么来的、能怎么用、改的时候要动哪些地方。与 `Icons.php` / `ICONS.md` 同一套分工：
**形状在 PHP，颜色在 CSS，规范在文档。**

---

## 1. 由来与决定

标记经历了两代。第一代顶栏标记是 `background + clip-path + 等宽字` 拼出来的「AT」两个字，
后来升级为按网格画的原创几何字组（Arknights Timeline 的缩写）—— 那一代解决了「标记不是资产」的问题，
但字形绑死了旧站名。

2026-09-24 站点更名为**「源石纪年 / Originium Chronicle」**，「AT」不再对应任何现实缩写，
于是第二代标记把字组换成了**源石晶体**：

- **晶体 = 源石**：本站考据时间线的核心对象就是源石与它驱动的一切事件，品牌锚点比字母缩写更直接；
  且晶体天然是直线折角几何，比「OC」这类带曲线的字母更贴全站「直角、无圆滑」的形状语言；
- **基准轴 = 纪年**：晶体下方的横线沿用第一代的「时间轴」语义，托着纪元里的每一个事件；
- 同一份几何同时供给顶栏、标签页图标、iOS 主屏图标与文档 —— 一处改，处处对（沿袭第一代）。

**不使用任何官方素材，也不描摹鹰角网络的标识。** 这是全站的硬红线（`DESIGN.md` §1.4），
晶体只是「用同一套工业/仪表语言重新画了一遍源石」——六边形双锥是通用制图/晶体学语法，不是官方图形。

## 2. 三个母题（造型不是凭空来的）

| 母题 | 来源 | 落在标记上的样子 |
| --- | --- | --- |
| **斜切方块** | `.btn--primary` 的 clip-path | 外框是 32 网格上切角 9 单位的方块，切角比例 0.28 与主按钮的 0.29 一致；切角只在右下，全站唯一 |
| **双锥晶体 + 晶棱** | 源石（Originium）的晶体形态 | 直立六边形双锥（宽 11.6 / 高 15.4，上下尖对称），中央一道两端收尖的菱形晶棱（evenodd 挖空露出标志黄）把晶体分成左右两个晶面 —— 晶棱是「晶体」读感的关键，实心六边形会被读成盾牌/螺母 |
| **基准轴** | 时间轴本身（沿袭第一代） | 晶体下方一条贯通的细横线（1.4 单位厚，距晶体 1.5），跨度略宽于晶体（每侧探出 2 单位）—— 读作承托晶体的基座：时间轴托着源石纪元里的事件 |

两处造型决策的否决记录，值得留一句：

- **轴上的「节点」**（第一代）改过两版都失败：嵌在笔画里 → 字母读成残字；横跨的刻度 → 读成「‡」。
  结论是语义细节不能动主体字形，只能另起元素承载 —— 基准轴因此而生，并沿用至今。
- **晶体的比例**（第二代）：首版宽 14 ≈ 高 15 的六边形被读成**房子**（尖顶 + 直墙 + 浅底尖）；
  收窄拉长为宽 11.6 / 高 15.4、上下尖对称后才立住「晶体」的读感。

## 3. 几何与配色

| 项 | 值 |
| --- | --- |
| 画布 | `viewBox="0 0 32 32"`，整幅铺满（无透明留白），因此可直接当 favicon 几何 |
| 外框 | `M0 0H32V23L23 32H0Z`，右下 9 单位斜切 |
| 晶体 | 高 15.4（y 6.4–21.8），宽 11.6（x 10.2–21.8）；晶棱为两端收尖的菱形（最宽 1.6），与外轮廓**同处一条 path** 靠 evenodd 挖空 |
| 基准轴 | y 23.3–24.7，跨度 8.2–23.8（略宽于晶体，每侧探出 2 单位） |
| 墨迹留白 | 上 6.4 / 下 7.3 / 左 8.2 / 右 8.2（尖顶光学减重，故上留白略小） |
| 方块色 | `--accent` `#ffd400` |
| 晶体色 | `--accent-ink` `#0d0d0d` |

**evenodd 是硬约束**：晶棱若单独成一条 `<path>`，与外轮廓之间**不会**发生异或，画出来是实心六边形
（实测验证过）；「外轮廓 + 晶棱」必须写进同一条 path 的两个子路径 —— 与第一代 A 的内孔同一机制。

**配色不可改**：黄底墨晶是唯一标准色。深色区块上要用反白变体，不要自己调色。

## 4. 尺寸与净空

| 尺寸 | 用途 | 实现 |
| --- | --- | --- |
| 28px | 顶栏（默认） | `.logo`；布局挂钩类 `.brand__mark` |
| 56px | 登录卡片页头 / 空状态引导 | `.logo--lg`（= 28 × 2） |
| 16px | 浏览器标签页 | `public/favicon.svg` / `.ico` |
| 180px | iOS 添加到主屏 | `public/apple-touch-icon.png` |

- 标记是矢量，**任意尺寸都清晰**，但界面上只用上面这几档（都是 28 的倍数关系，别另取数值）。
- **最小净空 = 边长的 1/8**（32 网格上即 4 单位）。标记是满幅方块，与相邻元素、页面边缘的距离不得小于该值；
  斜切角一旦被压住，形状语言就失效了。示意见 `docs/logo-preview.html` 的「净空与网格」。
- 与站点名同排时，标记与文字之间由 `.brand` 的 `gap: 11px` 决定，不要另外加 margin。

## 5. 用法

```blade
{{-- 顶栏：标记是装饰，站点名就在旁边，重复朗读只是噪音 --}}
<a class="brand" href="{{ route('timeline.index') }}">
    <x-logo class="brand__mark"/>
    <span>源石纪年</span>
    <span class="brand__sub">Originium Chronicle ://</span>
</a>

{{-- 独立出现（登录卡片、关于页）：给 label --}}
<x-logo class="logo--lg" label="源石纪年"/>

{{-- 单色：印刷、水印、浅底区块 --}}
<x-logo class="logo--ink"/>

{{-- 反白：深色区块的强调位 --}}
<x-logo class="logo--knockout"/>
```

**不要**：拉伸压扁（始终等比）；改配色；加描边、阴影、圆角；把斜切角换到别的角；在标记上叠字或叠图标。

## 6. 文件清单与重新生成

| 文件 | 角色 | 谁生成 |
| --- | --- | --- |
| `app/Support/Logo.php` | 几何唯一信息源 + `faviconSvg()` | 手写 |
| `resources/views/components/logo.blade.php` | `<x-logo>` 组件 | 手写 |
| `public/assets/app.css`「标识」段 | 尺寸、配色挂钩、变体 | 手写 |
| `resources/views/layouts/app.blade.php` | 顶栏集成 + 三行 `<link rel="icon">` | 手写 |
| `public/favicon.svg` | 浏览器标签图标（矢量） | `php artisan logo:export` |
| `public/favicon.ico` | 旧浏览器兜底（内含 16/32/48 三个原生尺寸） | 一次性产物，见下 |
| `public/apple-touch-icon.png` | iOS 主屏（180×180） | 一次性产物，见下 |
| `docs/logo-preview.html` | 评审页（锁排 / 尺寸 / 变体 / 净空） | 由 `Logo.php` 渲染，见下 |

改了几何之后：

```bash
php artisan logo:export        # 重新导出 favicon.svg（容器内即 docker exec -w /Arknight php_8.4.8 php artisan logo:export）
```

`favicon.ico` 与 `apple-touch-icon.png` 是**光栅图**，容器里的 GD 不能栅格化 SVG，
因此它们由同一份几何经 headless Chrome 逐尺寸原生渲染、再按 ICO 规范封装：

```bash
# 1) 取几何（width/height 换成 100% 以便按窗口尺寸渲染）
docker exec php_8.4.8 php -r 'require "/Arknight/vendor/autoload.php"; echo App\Support\Logo::faviconSvg();' \
  | grep -o '<svg[^>]*>.*</svg>' | sed 's/width="32" height="32"/width="100%" height="100%"/' > /tmp/logo.svg

# 2) 逐尺寸原生渲染（16/32/48 供 .ico，180 供 apple-touch-icon）
for s in 16 32 48 180; do
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu \
    --hide-scrollbars --force-device-scale-factor=1 --screenshot=/tmp/logo-$s.png \
    --window-size=$s,$s "file:///tmp/logo.svg"
done
```

再把 16/32/48 三张 PNG 按 ICO 规范封装成 `public/favicon.ico`（PNG 负载，三个原生尺寸，不做二次缩放）、
把 180 存为 `public/apple-touch-icon.png`。这一步只在几何真的改了才需要重跑 ——
标记是稳定的资产，不是日常迭代对象。
