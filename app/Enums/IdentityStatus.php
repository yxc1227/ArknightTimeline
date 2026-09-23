<?php

namespace App\Enums;

/**
 * 外部身份的核验状态。
 *
 * 必须区分「已登记」与「已核验」，因为两者的可信度完全不同：
 *
 *  - Verified：由外部渠道的授权流程确认（用户当场完成授权，身份由对方断言），
 *              或由管理员人工核验后确认。
 *  - Pending ：用户自助声明。任何人都能填任意 UID，
 *              因此**不得**在界面上表现为「已认证」，只能显示为待核验。
 *
 * 这条区分是隐私与信任设计的一部分：把自助声明渲染成认证，等于系统在替用户背书。
 */
enum IdentityStatus: string
{
    case Verified = 'verified';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Verified => '已核验',
            self::Pending => '待核验',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Verified => 'badge--ok',
            self::Pending => 'badge--warn',
        };
    }

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}
