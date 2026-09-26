<?php

namespace App\Services\Identity;

use App\Enums\IdentityProvider;
use App\Enums\IdentityStatus;
use App\Enums\UserAction;
use App\Exceptions\IdentityException;
use App\Exceptions\WriteDeniedException;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\UserManager;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 外部身份的注册、登录、绑定与解绑。
 *
 * 不变量全部集中在这里，控制器只负责取参数与跳转：
 *
 *  1. **一个外部账号只能属于一个本地账号**，反之每个渠道也只能绑一个。
 *     两条都由数据库唯一索引兜底，这里做的是把冲突翻译成人话，
 *     并在写入前先给出更具体的提示（哪个渠道、被谁绑走了）。
 *  2. **解绑不能让用户失去唯一的登录方式**。外部渠道注册的账号本人不知道密码
 *     （库里是随机占位值），如果放任解绑，用户会被永久锁在门外。
 *  3. **自助声明不等于认证**。手工登记的绑定落为 pending，只有管理员核验才转 verified，
 *     界面上两者必须能区分开。
 *  4. **绝不代收外部账号密码**。绑定只能由用户在自己的浏览器里完成授权；
 *     本系统不做「输入对方密码帮我绑定」这种模式，也因此在库里不存任何外部令牌。
 *
 * state 用「一次性消费 + 上限条数」处理：一次性是为了防重放，
 * 上限是为了让反复点击登录按钮不会把会话撑大。
 */
class IdentityManager
{
    /** 登录意图：只建立会话。 */
    public const INTENT_LOGIN = 'login';

    /** 绑定意图：把外部身份挂到当前登录用户身上。 */
    public const INTENT_BIND = 'bind';

    private const STATE_TTL = 600;

    private const STATE_LIMIT = 5;

    /** @var array<string, IdentityDriver> */
    private array $drivers = [];

    public function __construct(
        private readonly UserManager $users,
        private readonly Session $session,
    ) {}

    /* ------------------------------------------------------------------ 驱动 */

    /** 覆盖某个渠道的驱动实现；测试用它注入假驱动，避免真的发请求。 */
    public function extend(IdentityProvider $provider, IdentityDriver $driver): void
    {
        $this->drivers[$provider->value] = $driver;
    }

    public function driver(IdentityProvider $provider): IdentityDriver
    {
        return $this->drivers[$provider->value] ??= $this->makeDriver($provider);
    }

    private function makeDriver(IdentityProvider $provider): IdentityDriver
    {
        return match ($provider->driver()) {
            'stub' => new StubDriver($provider),
            default => new OAuthDriver($provider),
        };
    }

    /** 回调地址必须与授权时完全一致，因此只在这里生成一次。 */
    public function redirectUri(IdentityProvider $provider): string
    {
        return route('identity.callback', ['provider' => $provider->value]);
    }

    /* ------------------------------------------------------------------ state */

    /**
     * 记录一次待完成的授权，返回用户浏览器应当跳转到的授权页地址。
     *
     * 返回值是完整 URL 而不是 state：调用方（控制器）只需要跳转，
     * 把 state 交出去只会诱使控制器自己去拼授权地址 —— 那样 redirect_uri
     * 就会在两处各拼一次，而它必须与换取令牌时逐字一致。
     */
    public function authorizationUrl(IdentityProvider $provider, string $intent, ?User $user = null): string
    {
        if (! $provider->isEnabled()) {
            throw new IdentityException(
                sprintf('%s渠道尚未启用，请联系管理员。', $provider->label()),
                'provider_disabled',
            );
        }

        if ($intent === self::INTENT_BIND && $user === null) {
            throw new IdentityException('绑定外部账号需要先登录。', 'bind_requires_login');
        }

        $state = Str::random(40);

        $states = (array) $this->session->get('identity.states', []);
        $states[$state] = [
            'provider' => $provider->value,
            'intent' => $intent,
            'user_id' => $user?->getKey(),
            'at' => now()->timestamp,
        ];

        // 只留最近几条：授权地址是公开可反复访问的，不设上限就是一个会话膨胀的入口
        $this->session->put('identity.states', array_slice($states, -self::STATE_LIMIT, null, true));

        return $this->driver($provider)->authorizeUrl($state, $this->redirectUri($provider));
    }

    /**
     * 校验并**立即作废** state。
     *
     * 无论后续成功与否都先删掉：state 是一次性凭据，重放同一次授权
     * （比如用户刷新回调页）不应该被接受第二次。
     *
     * @return array{provider: string, intent: string, user_id: int|null}
     */
    public function consumeState(IdentityProvider $provider, ?string $state): array
    {
        if (blank($state)) {
            throw new IdentityException('授权请求缺少 state 参数，请重新发起。', 'state_missing');
        }

        $states = (array) $this->session->get('identity.states', []);
        $payload = $states[$state] ?? null;
        unset($states[$state]);
        $this->session->put('identity.states', $states);

        if (! is_array($payload) || ($payload['provider'] ?? null) !== $provider->value) {
            throw new IdentityException('授权请求已失效，请重新发起。', 'state_invalid');
        }

        if (now()->timestamp - (int) ($payload['at'] ?? 0) > self::STATE_TTL) {
            throw new IdentityException('授权请求已超时，请重新发起。', 'state_expired');
        }

        return [
            'provider' => (string) $payload['provider'],
            'intent' => (string) ($payload['intent'] ?? self::INTENT_LOGIN),
            'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
        ];
    }

    public function fetchProfile(IdentityProvider $provider, ?string $code): ExternalProfile
    {
        if (blank($code)) {
            throw new IdentityException('授权未完成：没有收到授权码。', 'code_missing');
        }

        return $this->driver($provider)->fetchProfile($code, $this->redirectUri($provider));
    }

    /* ------------------------------------------------------------------ 查询 */

    public function identityOf(ExternalProfile $profile): ?UserIdentity
    {
        return UserIdentity::query()
            ->ofProvider($profile->provider)
            ->where('provider_user_id', $profile->providerUserId)
            ->first();
    }

    /**
     * 该外部身份已绑定、但所属账号已被软删除。
     *
     * 必须单独识别这种情况：否则会掉进「注册新账号」的分支，
     * 最后在唯一索引上炸出一个让人看不懂的错误。
     */
    public function orphanedIdentity(ExternalProfile $profile): bool
    {
        $identity = $this->identityOf($profile);

        return $identity !== null && $identity->user === null;
    }

    /* ------------------------------------------------------------------ 绑定 */

    /**
     * 把外部身份绑定到本地账号。
     *
     * @param  bool  $verified  授权流程（true）或用户自助声明（false）
     */
    public function link(User $user, ExternalProfile $profile, bool $verified = true, ?User $actor = null): UserIdentity
    {
        $existing = $this->identityOf($profile);

        if ($existing !== null && $existing->user_id !== $user->getKey()) {
            throw new IdentityException(
                sprintf('该%s账号已绑定到其他账号。若确属本人，请先在对方账号中解绑。', $profile->provider->label()),
                'identity_taken',
            );
        }

        if ($existing !== null) {
            // 幂等：重复绑定同一份身份不写日志，否则审计表会被无意义的记录塞满
            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $profile, $verified, $actor) {
                $identity = $user->identities()->create([
                    'provider' => $profile->provider->value,
                    'provider_user_id' => $profile->providerUserId,
                    'nickname' => $profile->nickname,
                    'avatar_url' => $profile->avatarUrl,
                    'email' => $profile->email,
                    'status' => $verified ? IdentityStatus::Verified : IdentityStatus::Pending,
                    'verified_at' => $verified ? now() : null,
                    'verified_by' => $verified ? $actor?->getKey() : null,
                    'linked_at' => now(),
                ]);

                $this->users->logIdentityChange(
                    $user,
                    $actor ?? $user,
                    UserAction::IdentityLinked,
                    sprintf(
                        '绑定%s「%s」%s。',
                        $profile->provider->label(),
                        $profile->displayAccount(),
                        $verified ? '' : '（自助登记，等待核验）',
                    ),
                );

                return $identity;
            });
        } catch (UniqueConstraintViolationException $e) {
            // 并发下的兜底：唯一索引是最终裁决者，这里只把它翻译成人话
            throw new IdentityException('该外部账号刚刚已被绑定，请刷新后重试。', 'identity_taken');
        }
    }

    /**
     * 解除绑定。
     *
     * 关键不变量：解绑后必须还剩至少一种登录方式，
     * 否则用户会被永久锁在门外（外部注册的账号本人并不知道密码）。
     */
    public function unlink(User $user, IdentityProvider $provider, ?User $actor = null): void
    {
        $identity = $user->identityFor($provider);

        if ($identity === null) {
            throw new IdentityException(sprintf('尚未绑定%s。', $provider->label()), 'identity_absent');
        }

        if (! $user->hasAlternativeLoginMethod($provider)) {
            throw new IdentityException(
                '这是你唯一的登录方式，解绑后将无法再登录。请先在下方设置登录密码，然后再解绑。',
                'last_login_method',
            );
        }

        $label = $identity->displayAccount();

        DB::transaction(function () use ($user, $identity, $provider, $actor, $label) {
            $identity->delete();

            $this->users->logIdentityChange(
                $user,
                $actor ?? $user,
                UserAction::IdentityUnlinked,
                sprintf('解绑%s「%s」。', $provider->label(), $label),
            );
        });
    }

    /* ------------------------------------------------------------------ 自助登记与核验 */

    /**
     * 手工登记一个外部账号（在正式 OAuth 凭据到位之前，这是唯一能走通的绑定方式）。
     *
     * 落库即 pending：用户说的任何话在核验之前都只是声明。
     */
    public function claimManually(User $user, IdentityProvider $provider, string $providerUserId): UserIdentity
    {
        if (! $provider->allowsManual()) {
            throw new IdentityException(sprintf('%s不支持手工登记。', $provider->label()), 'manual_not_allowed');
        }

        $providerUserId = trim($providerUserId);

        if (preg_match('/^[A-Za-z0-9_-]{4,64}$/', $providerUserId) !== 1) {
            throw new IdentityException(
                sprintf('%s格式不正确，应为 4-64 位的字母、数字、下划线或连字符。', $provider->manualLabel()),
                'manual_format_invalid',
            );
        }

        return $this->link(
            $user,
            new ExternalProfile(
                provider: $provider,
                providerUserId: $providerUserId,
                nickname: null,
            ),
            verified: false,
        );
    }

    /** 管理员核验一条自助登记，把它转为已核验。 */
    public function verify(UserIdentity $identity, User $actor): UserIdentity
    {
        $this->assertAdmin($actor, '核验外部身份');

        if ($identity->isVerified()) {
            return $identity;
        }

        DB::transaction(function () use ($identity, $actor) {
            $identity->forceFill([
                'status' => IdentityStatus::Verified,
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
            ])->save();

            $this->users->logIdentityChange(
                $identity->user,
                $actor,
                UserAction::IdentityVerified,
                sprintf('核验通过%s「%s」。', $identity->label(), $identity->displayAccount()),
            );
        });

        return $identity->refresh();
    }

    /**
     * 驳回一条自助登记。
     *
     * 直接删除而不是标记为「已驳回」：留着它只会继续占住那条唯一索引，
     * 让真正的号主无法登记同一个 UID；驳回这件事本身已经进了操作日志。
     */
    public function reject(UserIdentity $identity, User $actor, ?string $reason = null): void
    {
        $this->assertAdmin($actor, '驳回外部身份');

        $label = $identity->displayAccount();
        $providerLabel = $identity->label();

        DB::transaction(function () use ($identity, $actor, $reason, $label, $providerLabel) {
            $this->users->logIdentityChange(
                $identity->user,
                $actor,
                UserAction::IdentityRejected,
                sprintf('驳回%s「%s」的自助登记%s。', $providerLabel, $label, filled($reason) ? '：'.$reason : '。'),
            );

            $identity->delete();
        });
    }

    /* ------------------------------------------------------------------ 注册 */

    /**
     * 用外部资料注册一个新的本地账号，并绑定该身份。
     *
     * 角色固定为最低档、出处范围限制固定开启：自助注册的账号不能自己决定权限。
     *
     * @param  array{name: string, nickname: string, email: string}  $data
     */
    public function register(ExternalProfile $profile, array $data): User
    {
        if ($this->orphanedIdentity($profile)) {
            throw new IdentityException(
                '该外部账号关联的本地账号已被删除，请联系管理员恢复后再试。',
                'identity_orphaned',
            );
        }

        if ($this->identityOf($profile) !== null) {
            throw new IdentityException('该外部账号已经注册过了，请直接登录。', 'identity_exists');
        }

        return DB::transaction(function () use ($profile, $data) {
            $user = $this->users->registerExternally($data, $profile);

            $this->link($user, $profile, verified: true);

            return $user;
        });
    }

    private function assertAdmin(User $actor, string $intent): void
    {
        if (! $actor->isAdmin()) {
            throw new WriteDeniedException("只有管理员可以{$intent}。", 'user_manage_denied');
        }
    }
}
