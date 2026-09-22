<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\Era;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Source;
use App\Models\User;
use App\Support\TerraDate;

trait BuildsTimeline
{
    protected function user(UserRole $role, ?string $email = null): User
    {
        // 显式可空（?string）：PHP 8.4 起隐式可空参数已弃用
        $email ??= $role->value.'@example.test';

        return User::create([
            'name' => $role->label(),
            'display_name' => $role->label(),
            'email' => $email,
            'password' => 'secret-password',
            'role' => $role->value,
            'email_verified_at' => now(),
        ]);
    }

    protected function era(string $name = '测试纪元', int $fromYear = 1090, int $toYear = 1110): Era
    {
        return Era::create([
            'name' => $name,
            'slug' => 'era-'.$name,
            'date_label' => "泰拉历 {$fromYear} — {$toYear} 年",
            'start_index' => TerraDate::toIndex($fromYear),
            'end_index' => TerraDate::toIndex($toYear, 12, 31),
            'color' => '#38bdf8',
        ]);
    }

    protected function source(string $name = '测试出处', string $slug = 'test-source', ?string $rawText = null): Source
    {
        return Source::create([
            'name' => $name,
            'slug' => $slug,
            'type' => 'event',
            'raw_text' => $rawText,
        ]);
    }

    protected function faction(string $name = '测试阵营'): Faction
    {
        return Faction::create(['name' => $name, 'slug' => 'fac-'.$name]);
    }

    /** 绕过 EventWriter 直接落库，用于构造测试前置状态。 */
    protected function rawEvent(array $attributes = []): Event
    {
        $dateDisplay = $attributes['date_display'] ?? '泰拉历1097年';

        return Event::create([
            'title' => $attributes['title'] ?? '测试事件',
            'summary' => $attributes['summary'] ?? '用于测试的事件描述。',
            'date_display' => $dateDisplay,
            'start_index' => $attributes['start_index'] ?? TerraDate::toIndex(1097, 6, 1),
            'end_index' => $attributes['end_index'] ?? TerraDate::toIndex(1097, 6, 1),
            'date_precision' => $attributes['date_precision'] ?? 'day',
            'date_confidence' => $attributes['date_confidence'] ?? 'confirmed',
            'status' => $attributes['status'] ?? 'verified',
            'version' => 1,
            ...$attributes,
        ]);
    }
}
