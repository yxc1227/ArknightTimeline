<?php

namespace App\Enums;

/**
 * 用户操作日志的动作类型。
 *
 * 用枚举而不是裸字符串的原因：详情页需要按动作着色与筛选，
 * 若散落在控制器里靠字符串匹配，改一处文案就会漏掉另一处。
 */
enum UserAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case PasswordReset = 'password_reset';
    case Activated = 'activated';
    case Deactivated = 'deactivated';
    case LoggedIn = 'logged_in';

    public function label(): string
    {
        return match ($this) {
            self::Created => '创建账号',
            self::Updated => '修改资料',
            self::Deleted => '删除账号',
            self::Restored => '恢复账号',
            self::PasswordReset => '重置密码',
            self::Activated => '启用账号',
            self::Deactivated => '禁用账号',
            self::LoggedIn => '登录',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Created => 'badge--ok',
            self::Restored => 'badge--ok',
            self::Activated => 'badge--ok',
            self::Updated => 'badge--info',
            self::LoggedIn => 'badge--muted',
            self::PasswordReset => 'badge--warn',
            self::Deactivated => 'badge--warn',
            self::Deleted => 'badge--danger',
        };
    }

    /** 是否属于「可能影响账号安全」的动作，详情页会重点提示。 */
    public function isSensitive(): bool
    {
        return in_array($this, [self::PasswordReset, self::Deleted, self::Deactivated], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $a) => [$a->value => $a->label()])
            ->all();
    }
}
