<?php

namespace App\Services\Identity;

use App\Enums\IdentityProvider;
use App\Exceptions\IdentityException;

/**
 * 本地演示渠道：不发起任何网络请求，直接返回一个固定的外部身份。
 *
 * 存在的意义是让「外部注册 → 完善资料 → 绑定 / 解绑 / 解绑保护」
 * 这条完整链路在没有真实凭据的环境里也能走通，并让测试可以覆盖 state 校验等分支。
 *
 * 两点刻意的限制：
 *  1. 它**只在非生产环境**被 config/identity.php 启用（enabled 与环境绑定）；
 *  2. 身份固定（同一个外部账号），因此重复登录会命中已有账号，
 *     既能看到「首次注册」也能看到「再次直接登录」，行为可复现。
 */
class StubDriver implements IdentityDriver
{
    public const ACCOUNT_ID = 'demo-operator';

    public function __construct(
        private readonly IdentityProvider $provider,
    ) {}

    public function provider(): IdentityProvider
    {
        return $this->provider;
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        // 不走真实跳转：直接把自己的回调地址当作「授权页」，
        // 并附上固定的授权码，等价于对方立刻批准了这次授权。
        $query = http_build_query([
            'code' => self::ACCOUNT_ID,
            'state' => $state,
        ]);

        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query;
    }

    public function fetchProfile(string $code, string $redirectUri): ExternalProfile
    {
        if ($code !== self::ACCOUNT_ID) {
            throw new IdentityException('本地演示渠道只接受它自己签发的授权码。', 'stub_code_invalid');
        }

        return new ExternalProfile(
            provider: $this->provider,
            providerUserId: self::ACCOUNT_ID,
            nickname: '演示干员',
        );
    }
}
