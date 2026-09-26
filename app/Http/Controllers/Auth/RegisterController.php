<?php

namespace App\Http\Controllers\Auth;

use App\Enums\IdentityProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Services\UserManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 网页自助注册。
 *
 * 这是全站唯一一条**匿名用户能直接触发账号表写入**的路径，因此三道闸门都在这里对齐：
 *   · 路由上的 throttle（按 IP 限流，挡住批量刷号）；
 *   · RegisterRequest（命名与唯一性，与数据库索引严格对齐）；
 *   · UserManager::register（角色固定最低档、出处范围限制固定开启）。
 *
 * 账号创建后立即可用（`is_active = true`）：注册出来的角色是预备干员（只读 +
 * 可提交标注），越权风险为零，因此不引入「等管理员审批」这一步 ——
 * 那会让自助注册变得名不副实。
 */
class RegisterController extends Controller
{
    public function __construct(
        private readonly UserManager $users,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (($redirect = $this->guard($request)) !== null) {
            return $redirect;
        }

        return view('auth.register', [
            // 不想填表的人可以直接走外部渠道，两条路并存而不是互相替代
            'providers' => IdentityProvider::enabled(),
        ]);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        if (($redirect = $this->guard($request)) !== null) {
            return $redirect;
        }

        // 并发撞上唯一索引时 UserManager 会抛 ValidationException，
        // Laravel 会自动回到表单并带上字段级提示，这里不需要 try/catch
        $user = $this->users->register($request->validated());

        Auth::login($user);
        $request->session()->regenerate();
        $this->users->logLogin($user);

        return redirect()->route('timeline.index')
            ->with('status', '账号已创建。可随时在「账号设置」里补充头像或绑定外部渠道。');
    }

    /**
     * 注册页的两道前置检查。
     *
     * 用显式判断而不是 `guest` 中间件：后者会把已登录的人送到 `route('home')`，
     * 而本项目并没有名为 home 的路由 —— 那会从「访问 /register」变成 500。
     */
    private function guard(Request $request): ?RedirectResponse
    {
        if (! config('identity.registration.enabled', true)) {
            return redirect()->route('login')->withErrors([
                'register' => '本站当前未开放自助注册，请联系管理员开通账号。',
            ]);
        }

        if ($request->user() !== null) {
            return redirect()->route('timeline.index')->with('status', '你已经登录了。');
        }

        return null;
    }
}
