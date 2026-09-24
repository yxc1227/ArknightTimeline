# 项目标记（logo）· 规范

几何：`app/Support/Logo.php`（单一信息源）· 页面内渲染：`<x-logo>` 组件 · 样式：`app.css`「标识」段。

标记就是顶栏左下角那一枚：**斜切黄方块 + 墨色「AT」**。
本文说明它是怎么来的、能怎么用、改的时候要动哪些地方。与 `Icons.php` / `ICONS.md` 同一套分工：
**形状在 PHP，颜色在 CSS，规范在文档。**

---

## 1. 由来与决定

原来的顶栏标记是 `background + clip-path + 等宽字` 拼出来的「AT」两个字 —— 它只有一个问题：
**它不是资产**。它只存在于那一个 CSS 类里，换个位置（登录页、标签页、文档、分享图）就要重新拼一遍，
而每拼一次都可能长得不一样；浏览器标签页图标当时甚至是空的（`public/favicon.ico` 是 0 字节）。

因此这次做的事是**把「AT」从一段样式升级为一枚矢量标记**，而不是换一个符号：

- 保留「AT」的识别度（Arknights Timeline），读者不会认不出是同一处入口；
- 字形改为按网格画出来的**原创几何**，不再是字体渲染的字；
- 同一份几何同时供给顶栏、标签页图标、iOS 主屏图标与文档 —— 一处改，处处对。

**不使用任何官方素材，也不描摹鹰角网络的标识。** 这是全站的硬红线（`DESIGN.md` §1.4），
标记只是「用同一套工业/仪表语言重新画了一遍自己的名字」。

## 2. 三个母题（造型不是凭空来的）

| 母题 | 来源 | 落在标记上的样子 |
| --- | --- | --- |
| **斜切方块** | `.btn--primary` 的 clip-path | 外框是 32 网格上切角 9 单位的方块，切角比例 0.28 与主按钮的 0.29 一致；切角只在右下，全站唯一 |
| **平顶的 A** | 斜切母题在字内的重现 | A 的顶点不留尖角，切平 3.5 单位；两条腿水平厚度恒为 3.0，任意高度下线宽一致 |
| **基准轴** | 时间轴本身 | 字组下方一条贯通全宽的细横线（1.4 单位厚，距基线 1.8），时间轴托着事件；它同时补掉了字组下方的空白，让整枚标记在方格里立得住 |

关于第三点值得留一句：它改过两版。
先是把「节点」画成轴上嵌一个方块并留空隙 —— 竖笔一断，`T` 立刻被读成「T ！」这样的残字；
再改成横跨竖笔的刻度 —— 又变成两道横笔，`T` 读成「‡」。
**结论是语义细节不能动字母本身，只能另起一个元素承载它**，于是有了这条基准轴。

## 3. 几何与配色

| 项 | 值 |
| --- | --- |
| 画布 | `viewBox="0 0 32 32"`，整幅铺满（无透明留白），因此可直接当 favicon 几何 |
| 外框 | `M0 0H32V23L23 32H0Z`，右下 9 单位斜切 |
| 字面 | 高 14（y 7.5–21.5），A 腿厚 3.0、横笔 2.8、T 横笔 2.8、竖笔 3.2 |
| 基准轴 | y 23.3–24.7，跨度 6.15–26（= 字组宽度） |
| 墨迹留白 | 上 7.5 / 下 7.3 / 左 6.15 / 右 6.0 |
| 方块色 | `--accent` `#ffd400` |
| 字母色 | `--accent-ink` `#0d0d0d` |

**配色不可改**：黄底墨字是唯一标准色。深色区块上要用反白变体，不要自己调色。

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
