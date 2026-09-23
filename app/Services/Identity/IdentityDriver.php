<?php

namespace App\Services\Identity;

use App\Enums\IdentityProvider;

/**
 * 一个外部渠道的最小契约：给一个授权地址，用一个授权码换回一份资料。
 *
 * 只有这两个动作 —— 注册、绑定、登录的差异全部在 IdentityManager 里，
 * 因此新增渠道不需要重新实现任何权限或唯一性判断，那是唯一性出错的根源。
 */
interface IdentityDriver
{
    public function provider(): IdentityProvider;

    /** 用户浏览器应当跳转到的授权页地址（内含 state）。 */
    public function authorizeUrl(string $state, string $redirectUri): string;

    /**
     * 用授权码换取用户资料。
     *
     * 失败时抛 IdentityException，错误码用于区分「对方不可达」与「我们配置错了」。
     */
    public function fetchProfile(string $code, string $redirectUri): ExternalProfile;
}
