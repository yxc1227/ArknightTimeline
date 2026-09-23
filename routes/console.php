<?php

use App\Services\EventLockService;
use App\Services\TimelineConsistencyChecker;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
| 巡检频率的选择依据：时间线内容的写入是低频的（日均几十次），
| 而全量巡检随条目数线性增长，因此按小时而非按分钟执行；
| 写入时的即时体检已经覆盖了「新问题立刻可见」的需求。
*/
Schedule::command('timeline:scan')->hourly()->withoutOverlapping();
Schedule::command('timeline:purge-locks')->everyFifteenMinutes()->withoutOverlapping();
