<?php

use App\Services\EventLockService;
use App\Services\TimelineConsistencyChecker;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 时间线一致性与协作的维护命令
|--------------------------------------------------------------------------
*/

Artisan::command('timeline:scan {--rebuild : 同时按纪元区间重算未归属条目的 era_id}', function () {
    /** @var TimelineConsistencyChecker $checker */
    $checker = app(TimelineConsistencyChecker::class);

    if ($this->option('rebuild')) {
        $rebuilt = $checker->reindexEraAssignments();
        $this->info("已按纪元区间补全 {$rebuilt} 条条目的纪元归属。");
    }

    $result = $checker->checkAll(function (int $checked) {
        if ($checked % 500 === 0) {
            $this->line("已体检 {$checked} 条…");
        }
    });

    $this->info("体检完成：{$result['checked']} 条条目，产出 {$result['anomalies']} 项异常。");

    foreach ($checker->openSummary() as $severity => $total) {
        $this->line("  未处置 [{$severity}]：{$total}");
    }

    return self::SUCCESS;
})->purpose('全量巡检时间线一致性，结果汇入异常收件箱');

Artisan::command('timeline:purge-locks', function () {
    $removed = app(EventLockService::class)->purgeExpired();
    $this->info("已回收 {$removed} 个过期编辑租约。");

    return self::SUCCESS;
})->purpose('回收过期的编辑租约');

/*
|--------------------------------------------------------------------------
| 引文定位：L3「出处可定位」硬闸门的运维入口
|--------------------------------------------------------------------------
|
| 引文的字符偏移与行号是**相对 sources.raw_text 现算**的，所以语料一旦被改写，
| 既有引用就会整批失效 —— 出处页对此只有一句 WARN，维护者却无从知道坏了多少条。
| 本命令就是那个出口：报告有多少条引文已无法定位，并可用 --fix 把偏移与行号重算回写。
|
| --fix 刻意**不碰引文本身**：定位不到只说明这条引文不是逐字抄的，
| 而「该改成什么」必须人去核对原文，机器不能替它决定。
|
| 关于直接写 event_source：这里是重算**派生数据**（引文在语料中的坐标），
| 不是编辑条目内容，因此不走 EventWriter —— 否则每重算一次就会留下一批
| 内容没有任何变化的版本快照，把真实的编辑历史淹掉。
*/

Artisan::command('citations:verify {--fix : 重算并回写偏移与行号（不改变引文内容）}', function () {
    $sources = \App\Models\Source::whereNotNull('raw_text')->with('events')->get();

    if ($sources->isEmpty()) {
        $this->warn('没有任何出处录入过原文语料，无从核对。');

        return self::SUCCESS;
    }

    $checked = 0;
    $located = 0;
    $fixed = 0;
    $missing = [];

    foreach ($sources as $source) {
        $locator = \App\Support\CorpusLocator::forText((string) $source->raw_text);

        foreach ($source->events as $event) {
            $quote = $event->pivot->quote;

            if (blank($quote)) {
                continue;
            }

            $checked++;
            $hit = $locator->locate((string) $quote);

            if ($hit === null) {
                $missing[] = "  [{$source->slug}] {$event->title}：{$quote}";

                continue;
            }

            $located++;

            $stale = $hit->charOffset !== $event->pivot->quote_offset
                || $hit->line !== $event->pivot->source_line;

            if ($stale && $this->option('fix')) {
                DB::table('event_source')
                    ->where('event_id', $event->id)
                    ->where('source_id', $source->id)
                    ->update(['quote_offset' => $hit->charOffset, 'source_line' => $hit->line]);
                $fixed++;
            }
        }
    }

    $this->info("核对完成：{$checked} 条引文，可定位 {$located} 条，无法定位 ".count($missing).' 条。');

    if ($fixed > 0) {
        $this->info("已重算 {$fixed} 条引文的偏移与行号。");
    }

    if ($missing === []) {
        return self::SUCCESS;
    }

    $this->newLine();
    $this->warn('以下引文无法在语料中定位 —— 它们不是逐字抄录，需要人工核对原文后改写引文：');

    foreach ($missing as $line) {
        $this->line($line);
    }

    return self::FAILURE;
})->purpose('核对全部引文能否在出处语料中逐字定位');

/*
|--------------------------------------------------------------------------
| 标识（logo）导出
|--------------------------------------------------------------------------
|
| 标记的几何只在 App\Support\Logo 一处定义，但要用到它的地方分三类，
| 其中两类是「静态文件」——拿不到 Blade 与 CSS，因此必须导出：
|
|   1. 页面内：<x-logo> 组件直接读几何，不需要导出；
|   2. public/favicon.svg：本命令导出（浏览器直接取这个文件）；
|   3. public/favicon.ico 与 apple-touch-icon.png：光栅图，不由 PHP 生成 ——
|      容器里的 GD 不能栅格化 SVG。这两份由同一几何经 headless Chrome 渲染、
|      再按 ICO 规范封装，属于「改了 Logo.php 才需要重跑」的一次性产物，
|      步骤记在 docs/LOGO.md §6。
|
| 改几何后跑一次本命令，就能保证 favicon 与页面里的标记不会长得不一样。
*/

Artisan::command('logo:export', function () {
    $svg = \App\Support\Logo::faviconSvg();
    $path = public_path('favicon.svg');
    file_put_contents($path, $svg . "\n");

    $this->info('已导出 public/favicon.svg（' . strlen($svg) . ' 字节）。');
    $this->line('  光栅版本（favicon.ico / apple-touch-icon.png）需按 docs/LOGO.md §6 重新渲染。');

    return self::SUCCESS;
})->purpose('从 App\Support\Logo 导出浏览器图标文件');

/*
|--------------------------------------------------------------------------
| 终末地风格等高线背景
|--------------------------------------------------------------------------
|
| 全站背景的地貌层（body::before 第三层）由 App\Support\ContourField
| 程序化生成：值噪声高度场 + marching squares 抽等值线。
| 资产是固定 SEED 的确定性输出 —— 改参数重跑本命令即可再生成，
| 图像可以 diff，不存在「一次性死资产」。
*/

Artisan::command('bg:contours', function () {
    $svg = \App\Support\ContourField::svg();
    $path = public_path('assets/bg-contours.svg');
    file_put_contents($path, $svg . "\n");

    $this->info('已生成 public/assets/bg-contours.svg（' . strlen($svg) . ' 字节）。');

    return self::SUCCESS;
})->purpose('生成终末地风格等高线全站背景（App\Support\ContourField）');

/*
|--------------------------------------------------------------------------
| 干员立绘：导出「库里存在」的泰拉人物名，供采集脚本清理孤儿图
|--------------------------------------------------------------------------
|
| 立绘清单由 bin/fetch-splashes.py 从 PRTS 枚举全部 `立绘_*` 图片生成，
| 而 PRTS 的名单比本仓库大 —— 未实装的干员（F91、郁金香…）、卫戍协议形态、
| 建制的无名单位（预备干员-XX）、制作组彩蛋（海猫、小色）…… 这些「库里没有的人」
| 会以孤儿文件的形式混进仓库。采集脚本用本命令的输出把它们挡在门外。
|
| 判定口径与 App\Support\CharacterSplashes::associate() **完全一致**：
| 按 world()->value + 名字精确匹配，不做模糊推断。这里导出的就是「会被关联上」
| 的那批名字 —— 多一个少一个，两边的名单都会对不上。
*/
Artisan::command('splashes:names', function () {
    $names = \App\Models\Character::query()->get()
        ->filter(fn (\App\Models\Character $c) => $c->world()->value === \App\Enums\World::Terra->value)
        ->pluck('name')
        ->unique()
        ->sort()
        ->values();

    $this->line($names->join("\n"));

    return self::SUCCESS;
})->purpose('输出泰拉侧全部人物名，供立绘采集脚本过滤「库里不存在」的人物');

/*
| 巡检频率的选择依据：时间线内容的写入是低频的（日均几十次），
| 而全量巡检随条目数线性增长，因此按小时而非按分钟执行；
| 写入时的即时体检已经覆盖了「新问题立刻可见」的需求。
*/
Schedule::command('timeline:scan')->hourly()->withoutOverlapping();
Schedule::command('timeline:purge-locks')->everyFifteenMinutes()->withoutOverlapping();
