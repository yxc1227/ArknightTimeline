<?php

namespace App\Http\Controllers\Auth;

use App\Enums\IdentityProvider;
use App\Exceptions\IdentityException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteExternalRegistrationRequest;
use App\Models\User;
use App\Services\Identity\ExternalProfile;
use App\Services\Identity\IdentityManager;
use App\Services\UserManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 外部渠道的登录、注册与绑定。
 *
 * 控制器只做三件事：验参数、调 IdentityManager、决定跳到哪。
 * 唯一性、解绑保护、state 时序这些判断都在服务层，因此这里没有任何
 * 一条 if 在重新推导业务规则。
 *
 * 失败一律走 `fail()`：身份流程是浏览器跳转链路，出错时必须把人送回
 * 一个能继续操作的页面并说明原因，而不是丢一个 4xx 页面。
 */
class IdentityController extends Controller
{
    /** 补全资料的有效期：超时后外部资料作废，必须重新授权。 */
    private const PENDING_TTL = 1800;

    public function __construct(
        private readonly IdentityManager $identities,
        private readonly UserManager $users,
    ) {}

    /* ------------------------------------------------------------------ 发起授权 */

    /** 从登录页发起：目的是登录（已绑定就直接进站，未绑定则注册）。 */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $target = $this->requireProvider($provider);

        try {
            return redirect()->away(
                $this->identities->authorizationUrl($target, IdentityManager::INTENT_LOGIN)
            );
        } catch (IdentityException $e) {
            return $this->fail('login', $e->getMessage());
        }
    }

    /** 从账号设置发起：目的是把该渠道绑到当前账号上。 */
    public function bind(Request $request, string $provider): RedirectResponse
    {
        $target = $this->requireProvider($provider);

        try {
            return redirect()->away(
                $this->identities->authorizationUrl($target, IdentityManager::INTENT_BIND, $request->user())
            );
        } catch (IdentityException $e) {
            return $this->fail('settings.profile', $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ 回调 */

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $target = $this->requireProvider($provider);

        /*
         * 先验 state，再用 code。
         *
         * 顺序不能反：code 是可被重放的凭据，必须在确认「这次回调确实是我们
         * 发起的那一次授权」之后才能拿去交换资料。
         * state 校验失败时还不知道 intent，因此只能回到登录页。
         */
        try {
            $state = $this->identities->consumeState($target, $request->string('state')->value());
        } catch (IdentityException $e) {
            return $this->fail('login', $e->getMessage());
        }

        $intent = $state['intent'];

        try {
            $profile = $this->identities->fetchProfile($target, $request->string('code')->value());
        } catch (IdentityException $e) {
            return $this->fail($this->homeFor($intent), $e->getMessage());
        }

        return $intent === IdentityManager::INTENT_BIND
            ? $this->completeBind($request, $target, $state, $profile)
            : $this->completeLogin($request, $target, $profile);
    }

    /**
     * 登录 / 注册分支。
     */
    private function completeLogin(Request $request, IdentityProvider $provider, ExternalProfile $profile): RedirectResponse
    {
        $identity = $this->identities->identityOf($profile);

        if ($identity !== null) {
            $user = $identity->user;

            // 绑定还在、账号已被删除：不能直接注册（会撞唯一索引），要给出可执行的下一步
            if ($user === null) {
                return $this->fail('login', '该外部账号关联的本地账号已被删除，请联系管理员恢复后再试。');
            }

            if (! $user->isActive()) {
                return $this->fail('login', '该账号已被禁用，请联系管理员。');
            }

            $identity->forceFill(['last_used_at' => now()])->save();

            Auth::login($user);
            $request->session()->regenerate();
            $this->users->logLogin($user);

            return redirect()->intended(route('timeline.index'))
                ->with('status', sprintf('已通过%s登录。', $provider->label()));
        }

        /*
         * 邮箱命中已有账号时**拒绝静默合并**。
         *
         * 外部渠道只断言「他是某个外部账号的持有者」，我们并没有验证
         * 对方返回的邮箱归属（本项目也没有邮件验证流程）。若按邮箱自动并号，
         * 任何人只要在某个渠道上把邮箱填成受害者的，就能直接接管本地账号。
         * 正确路径是：先用邮箱登录，再到设置里主动绑定。
         */
        if (filled($profile->email) && User::withTrashed()->whereEmailIs($profile->email)->exists()) {
            return $this->fail(
                'login',
                '该邮箱在本站已注册。请先用邮箱登录，然后在「账号设置」中绑定这个外部渠道。',
            );
        }

        $request->session()->put('identity.pending', [
            'provider' => $provider->value,
            'provider_user_id' => $profile->providerUserId,
            'nickname' => $profile->nickname,
            'avatar_url' => $profile->avatarUrl,
            'email' => $profile->email,
            'at' => now()->timestamp,
        ]);

        return redirect()->route('identity.register.form');
    }

    /**
     * 绑定分支。
     */
    private function completeBind(
        Request $request,
        IdentityProvider $provider,
        array $state,
        ExternalProfile $profile,
    ): RedirectResponse {
        $user = $request->user();

        /*
         * state 里记着发起绑定时的那个人，必须与当前会话是同一个人。
         *
         * 否则会出现这种错位：A 在浏览器里发起绑定 → 同一浏览器上 B 登录了 →
         * 回调把外部身份绑到 B 名下。用户完全看不出发生了什么，
         * 而 A 的外部账号从此归 B 所有。
         */
        if ($user === null || $state['user_id'] !== $user->getKey()) {
            return $this->fail('settings.profile', '绑定会话已失效（可能是登录状态发生了变化），请重新发起绑定。');
        }

        try {
            $identity = $this->identities->link($user, $profile, verified: true, actor: $user);
        } catch (IdentityException $e) {
            return $this->fail('settings.profile', $e->getMessage());
        }

        return redirect()->route('settings.profile')
            ->with('status', sprintf('%s「%s」已绑定。', $provider->label(), $identity->displayAccount()));
    }

    /* ------------------------------------------------------------------ 外部注册：补全资料 */

    public function registerForm(Request $request): View|RedirectResponse
    {
        $profile = $this->pendingProfile($request);

        if ($profile === null) {
            return $this->fail('login', '注册会话已过期，请重新选择外部渠道登录。');
        }

        // 预填一个没被占用的登录名与昵称：用户直接确认即可，
        // 而不是面对一个空表单自己猜什么才合法
        $seed = $profile->nickname ?? $profile->displayAccount();

        return view('auth.register-external', [
            'profile' => $profile,
            'suggestedHandle' => $this->users->suggestHandle($seed),
            'suggestedNickname' => $this->users->suggestNickname($seed),
        ]);
    }

    public function registerStore(
        CompleteExternalRegistrationRequest $request,
        UserManager $users,
    ): RedirectResponse {
        $profile = $this->pendingProfile($request);

        if ($profile === null) {
            return $this->fail('login', '注册会话已过期，请重新选择外部渠道登录。');
        }

        // 并发撞上唯一索引时 UserManager 会抛 ValidationException，
        // Laravel 自带的处理器会回到表单并给出字段级提示，因此这里不需要额外的 catch
        try {
            $user = $this->identities->register($profile, $request->validated());
        } catch (IdentityException $e) {
            return back()->withInput()->withErrors(['identity' => $e->getMessage()]);
        }

        // 一次性：注册完成即作废待补全资料，否则刷新会再建一个账号
        $request->session()->forget('identity.pending');

        Auth::login($user);
        $request->session()->regenerate();
        $users->logLogin($user);

        return redirect()->route('timeline.index')
            ->with('status', '账号已创建。可随时在「账号设置」里补充头像或绑定其他渠道。');
    }

    /* ------------------------------------------------------------------ 手工登记与解绑 */

    /** 手工登记外部账号（OAuth 凭据未到位时的可用路径），落库为待核验。 */
    public function claim(Request $request, string $provider): RedirectResponse
    {
        $target = $this->requireProvider($provider);

        $validated = $request->validate([
            'provider_user_id' => ['required', 'string', 'min:4', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ], [
            'provider_user_id.required' => '请填写'.$target->manualLabel().'。',
            'provider_user_id.regex' => $target->manualLabel().'只能是 4-64 位的字母、数字、下划线或连字符。',
        ]);

        try {
            $identity = $this->identities->claimManually($request->user(), $target, $validated['provider_user_id']);
        } catch (IdentityException $e) {
            return $this->fail('settings.profile', $e->getMessage());
        }

        return redirect()->route('settings.profile')
            ->with('status', sprintf('%s「%s」已登记，等待管理员核验。', $identity->label(), $identity->displayAccount()));
    }

    public function unlink(Request $request, string $provider): RedirectResponse
    {
        $target = $this->requireProvider($provider);

        try {
            $this->identities->unlink($request->user(), $target, $request->user());
        } catch (IdentityException $e) {
            return $this->fail('settings.profile', $e->getMessage());
        }

        return redirect()->route('settings.profile')
            ->with('status', sprintf('已解绑%s。', $target->label()));
    }

    /* ------------------------------------------------------------------ 内部工具 */

    /** 路由参数 → 枚举。非法值一律 404，不回落到默认渠道。 */
    private function requireProvider(string $provider): IdentityProvider
    {
        return IdentityProvider::fromRoute($provider) ?? abort(404);
    }

    /** 读取会话里的待补全外部资料；超时视为不存在。 */
    private function pendingProfile(Request $request): ?ExternalProfile
    {
        $pending = $request->session()->get('identity.pending');

        if (! is_array($pending)) {
            return null;
        }

        if (now()->timestamp - (int) ($pending['at'] ?? 0) > self::PENDING_TTL) {
            $request->session()->forget('identity.pending');

            return null;
        }

        $provider = IdentityProvider::tryFrom((string) ($pending['provider'] ?? ''));

        if ($provider === null || blank($pending['provider_user_id'] ?? null)) {
            return null;
        }

        return new ExternalProfile(
            provider: $provider,
            providerUserId: (string) $pending['provider_user_id'],
            nickname: $pending['nickname'] ?? null,
            avatarUrl: $pending['avatar_url'] ?? null,
            email: $pending['email'] ?? null,
        );
    }

    private function homeFor(string $intent): string
    {
        return $intent === IdentityManager::INTENT_BIND ? 'settings.profile' : 'login';
    }

    private function fail(string $route, string $message): RedirectResponse
    {
        return redirect()->route($route)->withErrors(['identity' => $message]);
    }
}
