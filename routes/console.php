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
| 巡检频率的选择依据：时间线内容的写入是低频的（日均几十次），
| 而全量巡检随条目数线性增长，因此按小时而非按分钟执行；
| 写入时的即时体检已经覆盖了「新问题立刻可见」的需求。
*/
Schedule::command('timeline:scan')->hourly()->withoutOverlapping();
Schedule::command('timeline:purge-locks')->everyFifteenMinutes()->withoutOverlapping();
