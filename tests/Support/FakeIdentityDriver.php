<?php

namespace Tests\Support;

use App\Enums\IdentityProvider;
use App\Services\Identity\ExternalProfile;
use App\Services\Identity\IdentityDriver;

/**
 * 测试用的假驱动。
 *
 * 让「授权 → 回调 → 注册 / 登录 / 绑定」这条链路可以在不发任何网络请求的
 * 前提下完整走通，也让 state 校验、会话错位这些分支可以被真正覆盖 ——
 * 那些恰恰是最容易写错、又最难手工复现的部分。
 *
 * authorizeUrl 指向我们自己的回调地址（等价于「对方立刻批准了这次授权」），
 * 因此测试可以直接顺着 Location 跳回来，就像真实浏览器那样。
 */
class FakeIdentityDriver implements IdentityDriver
{
    public int $profileCalls = 0;

    public function __construct(
        private readonly IdentityProvider $provider,
        private readonly ExternalProfile $profile,
    ) {}

    public function provider(): IdentityProvider
    {
        return $this->provider;
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $redirectUri.'?'.http_build_query([
            'code' => 'fake-authorization-code',
            'state' => $state,
        ]);
    }

    public function fetchProfile(string $code, string $redirectUri): ExternalProfile
    {
        $this->profileCalls++;

        return $this->profile;
    }
}
