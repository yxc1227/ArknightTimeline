<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 已登录会话的账号状态再校验。
 *
 * 为什么需要它：Laravel 的 session guard 只在登录那一刻验证凭据，
 * 之后直到会话过期都不会再看账号一眼。于是「管理员刚禁用了某人，
 * 但那个人因为已登录而照样能继续写入」这件事会真实发生 ——
 * 禁用必须立刻生效，而不是等会话自然过期。
 *
 * 已软删除的账号不需要在这里处理：全局作用域会让 guard 解析不到用户，
 * 请求会被当成访客，Auth 中间件随后就会重定向到登录页。
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isActive()) {
            return $next($request);
        }

        // 顺着「登出」的语义完整清理，避免留下半死的会话
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = '账号已被禁用，请联系管理员。';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'error' => 'account_disabled'], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
