<?php

use App\Exceptions\EditConflictException;
use App\Exceptions\WriteDeniedException;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // `active`：每个已登录请求都复核账号状态。Laravel 的 session guard 只在登录
        // 那一刻验凭据，没有这道中间件的话，「刚被禁用的人」能靠既有会话继续写入。
        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 冲突与拒绝写入即使在控制器之外抛出（队列、命令、后续新增的入口），
        // 也必须以结构化 JSON 返回，否则前端拿不到字段级 diff 就只能干刷新。
        $exceptions->render(function (EditConflictException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json($e->toApiPayload(), 409);
            }

            return back()->withErrors(['conflict' => $e->getMessage()]);
        });

        $exceptions->render(function (WriteDeniedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json($e->toApiPayload(), 403);
            }

            return back()->withErrors(['denied' => $e->getMessage()]);
        });
    })->create();
