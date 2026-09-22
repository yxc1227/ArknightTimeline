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
}
