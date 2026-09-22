<?php

use App\Http\Controllers\AiProposalController;
use App\Http\Controllers\AnomalyController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\TimelineController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 时间线（公开可读）
|--------------------------------------------------------------------------
| 考据项目的价值来自可被引用，因此读接口全部开放；
| 写接口按角色分级，且 AI 相关写操作额外要求审核员身份。
*/

Route::get('/', [TimelineController::class, 'index'])->name('timeline.index');
Route::get('/api/timeline', [TimelineController::class, 'feed'])->name('timeline.feed');
Route::get('/api/filter-options', [TimelineController::class, 'filterOptions'])->name('timeline.filter-options');

Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
Route::get('/events/{event}/revisions', [EventController::class, 'revisions'])->name('events.revisions');

// 标注（纠错建议）对访客开放：门槛低，纠错回路才转得起来
Route::post('/events/{event}/annotations', [EventController::class, 'annotate'])->name('events.annotations.store');

// 出处列表与详情同样公开：时间线的可信度取决于出处，读者必须能顺着引文点到原文。
// 页面内的编辑表单与「开始梳理」入口各自另有权限判断。
Route::get('/sources', [SourceController::class, 'index'])->name('sources.index');
Route::get('/sources/{source}', [SourceController::class, 'show'])->name('sources.show');

/*
|--------------------------------------------------------------------------
| 认证
|--------------------------------------------------------------------------
*/

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

/*
|--------------------------------------------------------------------------
| 条目编辑（editor 及以上）
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::put('/events/{event}', [EventController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');

    // 冲突合并后的写回（携带字段级裁决）
    Route::post('/events/{event}/resolve-conflict', [EventController::class, 'update'])->name('events.resolve-conflict');

    Route::post('/events/{eventId}/restore', [EventController::class, 'restore'])->name('events.restore');
    Route::post('/events/{event}/revert/{version}', [EventController::class, 'revert'])->name('events.revert');

    // 编辑租约（软锁）
    Route::post('/events/{event}/lock', [EventController::class, 'acquireLock'])->name('events.lock.acquire');
    Route::patch('/events/{event}/lock', [EventController::class, 'renewLock'])->name('events.lock.renew');
    Route::delete('/events/{event}/lock', [EventController::class, 'releaseLock'])->name('events.lock.release');
});

/*
|--------------------------------------------------------------------------
| 审核（reviewer 及以上）
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/events/{event}/status', [EventController::class, 'updateStatus'])->name('events.status');
    Route::post('/events/{event}/lock-toggle', [EventController::class, 'toggleLock'])->name('events.lock-toggle');
    Route::post('/events/{event}/annotations/{annotation}/resolve', [EventController::class, 'resolveAnnotation'])
        ->name('events.annotations.resolve');

    // ---- AI 梳理与审核 ----
    Route::get('/proposals', [AiProposalController::class, 'index'])->name('proposals.index');
    Route::get('/proposals/{proposal}', [AiProposalController::class, 'show'])->name('proposals.show');
    Route::post('/ai/synthesize', [AiProposalController::class, 'synthesize'])->name('ai.synthesize');
    Route::post('/proposals/bulk-approve', [AiProposalController::class, 'bulkApprove'])->name('proposals.bulk-approve');
    Route::post('/proposals/{proposal}/approve', [AiProposalController::class, 'approve'])->name('proposals.approve');
    Route::post('/proposals/{proposal}/reject', [AiProposalController::class, 'reject'])->name('proposals.reject');
    Route::post('/proposals/{proposal}/merge', [AiProposalController::class, 'merge'])->name('proposals.merge');

    // ---- 一致性收件箱 ----
    Route::get('/anomalies', [AnomalyController::class, 'index'])->name('anomalies.index');
    Route::post('/anomalies/scan', [AnomalyController::class, 'scan'])->name('anomalies.scan');
    Route::post('/anomalies/{anomaly}/resolve', [AnomalyController::class, 'resolve'])->name('anomalies.resolve');

    // ---- 出处与语料 ----
    Route::put('/sources/{source}', [SourceController::class, 'update'])->name('sources.update');
});
