<?php

namespace App\Enums;

/**
 * 用户操作日志的动作类型。
 *
 * 用枚举而不是裸字符串的原因：详情页需要按动作着色与筛选，
 * 若散落在控制器里靠字符串匹配，改一处文案就会漏掉另一处。
 *
 * 动作粒度刻意分得细（尤其是身份与密码相关）：审计表的价值在于
 * 「事后能回答『他是怎么进来的、什么时候变的』」，
 * 把所有变更糊成一个 updated 就等于没记。
 */
enum UserAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case Activated = 'activated';
    case Deactivated = 'deactivated';
    case LoggedIn = 'logged_in';
    case Registered = 'registered';
    case AvatarUpdated = 'avatar_updated';
    case AvatarRemoved = 'avatar_removed';
    case IdentityLinked = 'identity_linked';
    case IdentityUnlinked = 'identity_unlinked';
    case IdentityVerified = 'identity_verified';
    case IdentityRejected = 'identity_rejected';

    public function label(): string
    {
        return match ($this) {
            self::Created => '创建账号',
            self::Updated => '修改资料',
            self::Deleted => '删除账号',
            self::Restored => '恢复账号',
            self::PasswordReset => '重置密码',
            self::PasswordChanged => '修改密码',
            self::Activated => '启用账号',
            self::Deactivated => '禁用账号',
            self::LoggedIn => '登录',
            self::Registered => '外部注册',
            self::AvatarUpdated => '更新头像',
            self::AvatarRemoved => '移除头像',
            self::IdentityLinked => '绑定外部身份',
            self::IdentityUnlinked => '解绑外部身份',
            self::IdentityVerified => '核验外部身份',
            self::IdentityRejected => '驳回外部身份',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Created, self::Restored, self::Activated, self::Registered,
            self::IdentityVerified => 'badge--ok',
            self::Updated, self::IdentityLinked, self::AvatarUpdated => 'badge--info',
            self::LoggedIn, self::AvatarRemoved => 'badge--muted',
            self::PasswordReset, self::PasswordChanged, self::Deactivated,
            self::IdentityUnlinked => 'badge--warn',
            self::Deleted, self::IdentityRejected => 'badge--danger',
        };
    }

    /**
     * 是否属于「可能影响账号安全」的动作，详情页会重点提示。
     *
     * 解绑外部身份与改密码都在内：它们是「账号还能不能被本人拿回来」的变更，
     * 一旦被他人操作，当事人必须能立刻从日志里看出来。
     */
    public function isSensitive(): bool
    {
        return in_array($this, [
            self::PasswordReset,
            self::PasswordChanged,
            self::Deleted,
            self::Deactivated,
            self::IdentityUnlinked,
            self::IdentityRejected,
        ], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $a) => [$a->value => $a->label()])
            ->all();
    }
}
