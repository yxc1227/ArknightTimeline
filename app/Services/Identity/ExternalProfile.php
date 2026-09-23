<?php

namespace App\Services\Identity;

use App\Enums\IdentityProvider;

/**
 * 从外部渠道拿回来的用户资料，已归一化为本系统关心的四个字段。
 *
 * 用只读对象而不是数组：数组在跨层传递时没人拦得住「多个字段少一个」，
 * 而字段是否可为空在这里一次讲清楚。
 */
final class ExternalProfile
{
    public function __construct(
        public readonly IdentityProvider $provider,
        /** 对方系统中的唯一 ID：OAuth 的 sub，或用户手工登记的通行证 UID */
        public readonly string $providerUserId,
        public readonly ?string $nickname = null,
        public readonly ?string $avatarUrl = null,
        public readonly ?string $email = null,
    ) {}

    /** 用于界面展示的外部账号标识。 */
    public function displayAccount(): string
    {
        return $this->nickname ?? $this->providerUserId;
    }
}
