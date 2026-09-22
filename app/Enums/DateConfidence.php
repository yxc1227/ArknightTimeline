<?php

namespace App\Enums;

/**
 * 时间结论的可信度。用于把「官方明写」和「由上下文推断」区分开，
 * 也是多人编辑时判断某条时间是否可以被覆盖的依据。
 */
enum DateConfidence: string
{
    case Confirmed = 'confirmed'; // 原文/关卡内明写
    case Inferred = 'inferred';   // 由上下文物证推断
    case Disputed = 'disputed';   // 存在互相矛盾的出处
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => '已确证',
            self::Inferred => '推断',
            self::Disputed => '存疑',
            self::Unknown => '未知',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Confirmed => 'badge badge--ok',
            self::Inferred => 'badge badge--warn',
            self::Disputed => 'badge badge--danger',
            self::Unknown => 'badge badge--muted',
        };
    }

    /** 低可信度的时间被覆盖时不需要走「冲突确认」流程。 */
    public function requiresConflictReview(): bool
    {
        return $this === self::Confirmed;
    }
}
