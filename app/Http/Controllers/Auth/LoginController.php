<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 极简登录。不引入完整脚手架是有意的：
 * 这个站点的核心是时间线本身，认证只需要「区分角色」这一个能力。
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $key = 'login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages([
                'email' => '尝试过于频繁，请在 '.RateLimiter::availableIn($key).' 秒后重试。',
            ]);
        }

        $credentials = $request->validated();

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['email' => '邮箱或密码不正确。']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $request->user()->forceFill(['last_seen_at' => now()])->save();

        return redirect()->intended(route('timeline.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('timeline.index');
    }
}
