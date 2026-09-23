<?php

use App\Http\Controllers\AiProposalController;
use App\Http\Controllers\AnomalyController;
use App\Http\Controllers\Auth\IdentityController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\UserController;
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
| 自助注册
|--------------------------------------------------------------------------
| 两条并存的注册路径：网页表单（邮箱 + 密码）与外部渠道。
| 它们共用同一份命名校验与同一套不变量（见 UserManager::register*）。
|
| POST 上的 throttle 是公开注册入口的第一道闸门：没有它，一个脚本
| 就能以每秒几十个的速度建号。限流按 IP，10 次 / 分钟足够正常人纠错。
| 「是否开放注册」由 config/identity.php 的开关决定，控制器里判断。
*/

Route::get('/register', [RegisterController::class, 'show'])->name('register');
Route::post('/register', [RegisterController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('register.store');

/*
|--------------------------------------------------------------------------
| 外部身份（鹰角通行证等）
|--------------------------------------------------------------------------
| 授权跳转与回调必须是公开路由：回调发生在用户从对方站点跳回来的那一刻，
| 此时浏览器可能还没有本站的会话（首次登录就是这样）。
| 因此这里的安全性不靠 auth 中间件，而靠 state 的一次性校验
| （见 IdentityManager::consumeState）。
|
| 「补全资料」两步走是刻意的：外部渠道只证明「他是某个外部账号的持有者」，
| 而登录名 / 昵称 / 邮箱是本站的命名空间，必须由本人在第二步当场选定。
*/

Route::get('/auth/{provider}/redirect', [IdentityController::class, 'redirect'])->name('identity.redirect');
Route::get('/auth/{provider}/callback', [IdentityController::class, 'callback'])->name('identity.callback');

Route::get('/register/external', [IdentityController::class, 'registerForm'])->name('identity.register.form');
Route::post('/register/external', [IdentityController::class, 'registerStore'])->name('identity.register.store');

/*
|--------------------------------------------------------------------------
| 头像
|--------------------------------------------------------------------------
| 公开可读：头像会出现在时间线的标注与版本记录旁边，与「用户名可见」是同一层信息。
| 由控制器受控输出而不是交给 web 服务器托管 —— 少一个 storage:link 的部署步骤，
| 同时把 Content-Type 与 nosniff 握在自己手里。
*/

Route::get('/avatars/{user}/{v?}', [AvatarController::class, 'show'])
    ->where('v', '[A-Za-z0-9]+')
    ->name('avatars.show');

/*
|--------------------------------------------------------------------------
| 已登录区域
|--------------------------------------------------------------------------
| `active` 中间件在每个请求上复核账号状态：Laravel 的 session guard 只在
| 登录那一刻验凭据，否则「刚被禁用的人」能靠已登录的会话继续写入。
*/

Route::middleware(['auth', 'active'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | 个人账号设置（本人操作自己）
    |--------------------------------------------------------------------------
    | 唯一不需要管理员的地方，也是头像上传、昵称修改、绑定外部身份的落点。
    */

    Route::get('/settings/profile', [ProfileController::class, 'show'])->name('settings.profile');
    Route::put('/settings/profile', [ProfileController::class, 'updateNickname'])->name('settings.profile.update');
    Route::put('/settings/profile/password', [ProfileController::class, 'updatePassword'])->name('settings.profile.password');
    Route::post('/settings/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('settings.profile.avatar');
    Route::delete('/settings/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('settings.profile.avatar.destroy');

    // 绑定与解绑外部身份。绑定要先跳去对方站点，因此是一个 GET 跳转
    Route::get('/settings/identities/{provider}/bind', [IdentityController::class, 'bind'])->name('identity.bind');
    Route::post('/settings/identities/{provider}/claim', [IdentityController::class, 'claim'])->name('identity.claim');
    Route::delete('/settings/identities/{provider}', [IdentityController::class, 'unlink'])->name('identity.unlink');

    /*
    |--------------------------------------------------------------------------
    | 条目编辑（editor 及以上）
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | 审核（reviewer 及以上）
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | 账号管理（仅 admin）
    |--------------------------------------------------------------------------
    | 组级 `can:viewAny` 统一挡掉非管理员；每个写操作在控制器内还有对象级判定，
    | 而真正的不变量（不能掏空最后一个管理员、一切留痕）在 UserManager 里。
    |
    | 路由顺序注意：`/users/bulk` 必须注册在 `/users/{user}` 之前，
    | 否则「bulk」会被当成 {user} 的取值。
    */

    Route::prefix('admin')->name('admin.')->middleware('can:viewAny,App\Models\User')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/bulk', [UserController::class, 'bulk'])->name('users.bulk');
        Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');

        // withTrashed：管理员需要能翻出已删除的账号并恢复它
        Route::get('/users/{user}', [UserController::class, 'show'])->withTrashed()->name('users.show');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
        Route::post('/users/{user}/toggle', [UserController::class, 'toggleActive'])->name('users.toggle');

        // 外部身份核验：自助登记的绑定必须经管理员确认
        Route::post('/users/{user}/identities/{identity}/verify', [UserController::class, 'verifyIdentity'])
            ->name('identities.verify');
        Route::post('/users/{user}/identities/{identity}/reject', [UserController::class, 'rejectIdentity'])
            ->name('identities.reject');
    });
});
