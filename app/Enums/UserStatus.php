<?php

namespace App\Enums;

/**
 * 账号状态。
 *
 * 与 UserRole 正交：角色决定「能做什么」，状态决定「还能不能进来」。
 * 禁用是删除的轻量替代 —— 考据项目里账号往往关联着大量历史归属，
 * 直接删掉会让「谁改的」失去答案，因此优先停用。
 */
enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => '启用',
            self::Disabled => '已禁用',
        };
    }

    /** 与 EventStatus / ProposalStatus 的徽章配色保持同一套语义。 */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'badge--ok',
            self::Disabled => 'badge--muted',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}
