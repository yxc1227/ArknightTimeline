<?php

namespace App\Enums;

enum AnomalySeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Info => '提示',
            self::Warning => '警告',
            self::Error => '阻断',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Info => 'badge badge--muted',
            self::Warning => 'badge badge--warn',
            self::Error => 'badge badge--danger',
        };
    }

    /** 一致性告警徽章用的状态图标（见 docs/ICONS.md「状态」段，经 <x-icon> 渲染）。 */
    public function icon(): string
    {
        return match ($this) {
            self::Info => 'status-info',
            self::Warning => 'status-warn',
            self::Error => 'status-danger',
        };
    }
}
