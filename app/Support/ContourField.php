<?php

namespace App\Support;

/**
 * 终末地风格等高线全站背景。
 * ---------------------------------------------------------------------------
 *
 * 《明日方舟：终末地》的 UI 用大量地形等高线做空间底纹：线极细、极淡，
 * 不与内容争夺注意力，却让「近黑」不再是死的纯色，而是一片可阅读的地貌。
 * 本类把这套语言移植到本站——全站背景（body::before 的第三层）就是它的输出，
 * 经 `php artisan bg:contours` 写入 public/assets/bg-contours.svg。
 *
 * ## 为什么是程序生成，而不是一张画好的图
 *
 * 1. **可复现**：与 Logo.php 同一条规矩——资产有唯一信息源，改参数重跑即可，
 *    不会出现「图丢了/改不动了」的死资产。
 * 2. **贴母题**：线宽 1px、锐利折角、等宽小字标注，全部来自 docs/DESIGN.md §1.4
 *    的既有语言；AI 出图给不了这种逐像素的纪律。
 * 3. **零素材红线**：不描摹任何官方图形，只借「等高线」这一通用制图语法。
 *
 * ## 算法（值噪声 + marching squares）
 *
 * 1. **高度场**：两个倍频程的值噪声叠加——64px 格点定「大形势」，
 *    24px 格点给「中细节」。刻意**不加更细的 octave**：细噪声会产生满地
 *    小闭合圈，读感是「噪点」而不是「地形」。
 * 2. **线性插值**（而非 smoothstep）：等值线呈折线而非曲线，
 *    与全站「直角、无圆滑」的形状语言一致。
 * 3. **marching squares** 逐格抽取等值线段，再按端点把段链成折线，
 *    减少 path 数量并保证接点连续。
 * 4. **剔除短碎片**：长度不足 MIN_LEN 的闭合小圈直接丢弃——等高线要「势」。
 *
 * ## 视觉分级（地形图惯例移植）
 *
 * - 基础线：极淡白，占大多数；
 * - 计曲线（每 4 条一根，index contour）：略亮一档，给地图「呼吸的节奏」；
 * - 主等高线（ACCENT_IDX 一级）：标志黄，只落在足够长的大势折线上——
 *   黄是本站的「主信号」色，只此一根、若隐若现，是整片地貌的锚点。
 * - 高程标注：等宽小字伪高程，透明度压到几乎不可读——HUD 味，不抢内容。
 *
 * ## 使用约定
 *
 * 输出按 preserveAspectRatio="xMidYMid slice" 供 CSS background-size: cover
 * 裁切；所有线都带 vector-effect="non-scaling-stroke"，任意缩放下线宽恒 1px。
 * 固定 SEED 保证同参数输出字节一致；调参数请改常量后重跑 bg:contours。
 */
final class ContourField
{
    /** 输出画布（CSS 侧以 cover 裁切，超宽屏会放大但线宽不随缩放变化）。 */
    public const CANVAS_W = 1920;
    public const CANVAS_H = 1200;

    /** 固定种子：同参数永远输出同一张图（资产可 diff、可复现）。 */
    public const SEED = 20260924;

    /** 采样格 48px：折线段长短适中，太细则碎、太粗则失真。 */
    private const CELL = 48;

    /** 等值线级数：13 级在 1200px 高度上间距约 90px，疏密接近真实地形图。 */
    private const LEVELS = 13;

    /** 短于该像素长度的折线一律丢弃（去掉细噪声遗留的小闭合圈）。 */
    private const MIN_LEN = 130;

    /** 主等高线取第几级（0 基，偏高位），以及参与黄色的最小长度。 */
    private const ACCENT_IDX = 9;
    private const ACCENT_MIN_LEN = 240;

    private const W = self::CANVAS_W;
    private const H = self::CANVAS_H;

    private array $g64;
    private array $g24;

    /** 采样后的高度场与极值（用于铺 levels）。 */
    private array $field = [];
    private float $fmin = PHP_FLOAT_MAX;
    private float $fmax = -PHP_FLOAT_MAX;

    private int $nx;
    private int $ny;

    public static function svg(): string
    {
        $g = new self();

        return $g->render();
    }

    private function __construct()
    {
        mt_srand(self::SEED);

        $this->g64 = $this->lattice(64);
        $this->g24 = $this->lattice(24);

        // 采样高度场。两倍频程权重 0.66 / 0.34：大形势主导，细节只做扰动。
        $this->nx = intdiv(self::W, self::CELL) + 1;
        $this->ny = intdiv(self::H, self::CELL) + 1;
        for ($y = 0; $y < $this->ny; $y++) {
            $row = [];
            for ($x = 0; $x < $this->nx; $x++) {
                $v = $this->sample($this->g64, 64, $x * self::CELL, $y * self::CELL) * 0.66
                   + $this->sample($this->g24, 24, $x * self::CELL, $y * self::CELL) * 0.34;
                $row[] = $v;
                $this->fmin = min($this->fmin, $v);
                $this->fmax = max($this->fmax, $v);
            }
            $this->field[] = $row;
        }
    }

    /** 生成 step 尺寸网格的随机格点（含右/下边界一圈，插值时不越界）。 */
    private function lattice(int $step): array
    {
        $gw = intdiv(self::W, $step) + 2;
        $gh = intdiv(self::H, $step) + 2;
        $g = [];
        for ($y = 0; $y < $gh; $y++) {
            $row = [];
            for ($x = 0; $x < $gw; $x++) {
                $row[] = mt_rand() / mt_getrandmax();
            }
            $g[] = $row;
        }

        return $g;
    }

    /**
     * 双线性取值。刻意不做 smoothstep 平滑：线性插值让等值线呈折线，
     * 与全站「直角、无圆滑」的形状语言一致。
     */
    private function sample(array $g, int $step, float $x, float $y): float
    {
        $gx = $x / $step;
        $gy = $y / $step;
        $x0 = (int) $gx;
        $y0 = (int) $gy;
        $fx = $gx - $x0;
        $fy = $gy - $y0;

        $a = $g[$y0][$x0] + ($g[$y0][$x0 + 1] - $g[$y0][$x0]) * $fx;
        $b = $g[$y0 + 1][$x0] + ($g[$y0 + 1][$x0 + 1] - $g[$y0 + 1][$x0]) * $fx;

        return $a + ($b - $a) * $fy;
    }

    /** 等值线段端点：在边的两个格点之间按阈值线性内插。 */
    private static function edgePt(float $x0, float $y0, float $v0, float $x1, float $y1, float $v1, float $t): array
    {
        $r = ($t - $v0) / ($v1 - $v0);

        return [$x0 + ($x1 - $x0) * $r, $y0 + ($y1 - $y0) * $r];
    }

    /** 单格 marching squares：返回 0~2 条等值线段。 */
    private function cellSegments(int $cx, int $cy, float $t): array
    {
        $tl = $this->field[$cy][$cx];
        $tr = $this->field[$cy][$cx + 1];
        $bl = $this->field[$cy + 1][$cx];
        $br = $this->field[$cy + 1][$cx + 1];

        $idx = ($tl > $t ? 8 : 0) | ($tr > $t ? 4 : 0) | ($br > $t ? 2 : 0) | ($bl > $t ? 1 : 0);
        if ($idx === 0 || $idx === 15) {
            return [];
        }

        $px = $cx * self::CELL;
        $py = $cy * self::CELL;
        $top = self::edgePt($px, $py, $tl, $px + self::CELL, $py, $tr, $t);
        $right = self::edgePt($px + self::CELL, $py, $tr, $px + self::CELL, $py + self::CELL, $br, $t);
        $bottom = self::edgePt($px, $py + self::CELL, $bl, $px + self::CELL, $py + self::CELL, $br, $t);
        $left = self::edgePt($px, $py, $tl, $px, $py + self::CELL, $bl, $t);

        // 16 种角点组合 → 边对。5 / 10 的歧义情形任取一种剖分（视觉无差）。
        $table = [
            1 => [[$left, $bottom]], 2 => [[$bottom, $right]], 3 => [[$left, $right]],
            4 => [[$top, $right]], 6 => [[$top, $bottom]], 7 => [[$top, $left]],
            8 => [[$top, $left]], 9 => [[$top, $bottom]], 11 => [[$top, $right]],
            12 => [[$left, $right]], 13 => [[$bottom, $right]], 14 => [[$left, $bottom]],
            5 => [[$top, $left], [$bottom, $right]],
            10 => [[$top, $right], [$left, $bottom]],
        ];

        return $table[$idx] ?? [];
    }

    private static function key(array $p): string
    {
        return round($p[0] * 8) . ':' . round($p[1] * 8);
    }

    /**
     * 某阈值下的全部等值线：marching squares 段 → 按端点链成折线。
     * 链接保证接点连续（无 butt-cap 断点），并大幅减少 path 数量。
     */
    private function contoursAt(float $t): array
    {
        $segs = [];
        for ($y = 0; $y < $this->ny - 1; $y++) {
            for ($x = 0; $x < $this->nx - 1; $x++) {
                foreach ($this->cellSegments($x, $y, $t) as [$a, $b]) {
                    $segs[] = [$a, $b];
                }
            }
        }

        $adj = [];
        foreach ($segs as $i => [$a, $b]) {
            $adj[self::key($a)][] = [self::key($b), $i];
            $adj[self::key($b)][] = [self::key($a), $i];
        }

        $used = [];
        $lines = [];

        // 从线段一端向外延伸（双向），直到没有未使用的邻段
        $extend = function (array &$line) use (&$used, $adj, $segs): void {
            while (true) {
                $k = self::key($line[count($line) - 1]);
                $next = null;
                foreach ($adj[$k] ?? [] as [$other, $si]) {
                    if (! isset($used[$si])) {
                        $next = [$other, $si];
                        break;
                    }
                }
                if ($next === null) {
                    return;
                }
                [$other, $si] = $next;
                $used[$si] = true;
                [$a, $b] = $segs[$si];
                $line[] = self::key($a) === $k ? $b : $a;
            }
        };

        foreach ($segs as $i => [$a, $b]) {
            if (isset($used[$i])) {
                continue;
            }
            $used[$i] = true;
            $line = [$a, $b];
            $extend($line);
            $line = array_reverse($line);
            $extend($line);
            if (count($line) >= 4) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function lineLen(array $line): float
    {
        $len = 0.0;
        for ($i = 0; $i < count($line) - 1; $i++) {
            $len += hypot($line[$i + 1][0] - $line[$i][0], $line[$i + 1][1] - $line[$i][1]);
        }

        return $len;
    }

    private static function pathOf(array $line): string
    {
        $d = sprintf('M%.0f %.0f', $line[0][0], $line[0][1]);
        for ($i = 1; $i < count($line); $i++) {
            $d .= sprintf('L%.0f %.0f', $line[$i][0], $line[$i][1]);
        }

        return $d;
    }

    private function render(): string
    {
        // levels 铺在去掉两端 10% 的区间上：极值附近的等值线会退化成小点，不值得要
        $lo = $this->fmin + ($this->fmax - $this->fmin) * 0.10;
        $hi = $this->fmax - ($this->fmax - $this->fmin) * 0.10;
        $thresholds = [];
        for ($i = 0; $i < self::LEVELS; $i++) {
            $thresholds[] = $lo + ($hi - $lo) * $i / (self::LEVELS - 1);
        }

        $groups = ['base' => [], 'index' => [], 'accent' => []];
        foreach ($thresholds as $i => $t) {
            foreach ($this->contoursAt($t) as $line) {
                if (self::lineLen($line) < self::MIN_LEN) {
                    continue;
                }
                if ($i === self::ACCENT_IDX) {
                    // 黄线是「主等高线」：只落在足够长的大势折线上，短的降为基础线
                    $key = self::lineLen($line) > self::ACCENT_MIN_LEN ? 'accent' : 'base';
                    $groups[$key][] = self::pathOf($line);
                } elseif ($i % 4 === 0) {
                    $groups['index'][] = self::pathOf($line);
                } else {
                    $groups['base'][] = self::pathOf($line);
                }
            }
        }

        // 高程标注：在足够长的折线中点上放伪高程，彼此拉开 300px，至多 6 处
        $labels = [];
        $cand = [];
        foreach ($thresholds as $i => $t) {
            foreach ($this->contoursAt($t) as $line) {
                if (count($line) >= 14) {
                    $cand[] = [$i, $line];
                }
            }
        }
        shuffle($cand);
        foreach ($cand as [$i, $line]) {
            if (count($labels) >= 6) {
                break;
            }
            $p = $line[intdiv(count($line), 2)];
            $ok = true;
            foreach ($labels as [$qx, $qy]) {
                if (hypot($p[0] - $qx, $p[1] - $qy) < 300) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $labels[] = [$p[0], $p[1], (120 + $i * 40) . ' m'];
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . self::W . '" height="' . self::H
            . '" viewBox="0 0 ' . self::W . ' ' . self::H . '" preserveAspectRatio="xMidYMid slice">';
        $svg .= '<!-- 终末地风格等高线全站背景 · 由 App\Support\ContourField 生成 · '
            . 'SEED=' . self::SEED . ' · 请勿手工编辑，改参数后重跑 php artisan bg:contours -->';

        $emit = function (array $paths, string $stroke) use (&$svg): void {
            if ($paths === []) {
                return;
            }
            $svg .= '<g fill="none" stroke="' . $stroke . '">';
            foreach ($paths as $d) {
                $svg .= '<path d="' . $d . '" vector-effect="non-scaling-stroke"/>';
            }
            $svg .= '</g>';
        };

        $emit($groups['base'], 'rgba(255,255,255,0.032)');
        $emit($groups['index'], 'rgba(255,255,255,0.062)');
        $emit($groups['accent'], 'rgba(255,212,0,0.10)');

        if ($labels !== []) {
            $svg .= '<g font-family="SF Mono, JetBrains Mono, Menlo, monospace" font-size="10" '
                . 'letter-spacing="1.5" fill="rgba(255,255,255,0.13)">';
            foreach ($labels as [$x, $y, $s]) {
                $svg .= '<text x="' . sprintf('%.0f', $x) . '" y="' . sprintf('%.0f', $y) . '">' . $s . '</text>';
            }
            $svg .= '</g>';
        }

        return $svg . '</svg>';
    }
}
