<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\UserManager;
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
    public function __construct(
        private readonly UserManager $manager,
    ) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $key = 'login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages([
                'identifier' => '尝试过于频繁，请在 '.RateLimiter::availableIn($key).' 秒后重试。',
            ]);
        }

        $identifier = (string) $request->validated('identifier');

        /*
         * 邮箱与登录名都能用来登录。
         *
         * 两者都全服唯一，因此「含 @ 就当邮箱、否则当登录名」不存在歧义 ——
         * 登录名的格式本身就把 @ 排除在外了（见 User::HANDLE_PATTERN）。
         */
        $credentials = [
            str_contains($identifier, '@') ? 'email' : 'name' => mb_strtolower($identifier),
            'password' => (string) $request->validated('password'),
        ];

        /*
         * 用 validate() 而不是 attempt()：
         *  1. attempt() 会先把会话建立起来再让我们检查状态，中间存在一个
         *     「已登录但未授权」的瞬间；validate() 只验凭据，不落会话。
         *  2. 停用提示只在**凭据正确**时才给出，因此不会变成账号枚举的探针 ——
         *     不知道密码的人只会看到「邮箱或密码不正确」。
         */
        if (! Auth::validate($credentials)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['identifier' => '邮箱（或登录名）与密码不正确。']);
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();

        if (! $user->isActive()) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['identifier' => '该账号已被禁用，请联系管理员。']);
        }

        Auth::login($user, $request->boolean('remember'));

        RateLimiter::clear($key);
        $request->session()->regenerate();

        // 登录痕迹（last_login_at / ip）与操作日志一并记录，供账号详情页追溯
        $this->manager->logLogin($user);

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
