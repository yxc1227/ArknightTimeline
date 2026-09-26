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
    /**
     * 造一个账号。
     *
     * 登录名与昵称都从邮箱派生，而不是用角色标签：
     * 这两列现在都有唯一索引，若沿用「角色名」当值，同一个测试里造两个同角色账号
     * 就会在唯一索引上直接炸掉 —— 而错误现场离原因很远。
     *
     * 登录名还必须是 ASCII 且以字母开头（见 User::HANDLE_PATTERN），
     * 因此这里顺手做了归一化。
     */
    protected function user(UserRole $role, ?string $email = null, ?string $nickname = null): User
    {
        // 显式可空（?string）：PHP 8.4 起隐式可空参数已弃用
        $email ??= $role->value.'@example.test';
        $handle = $this->handleFromEmail($email);

        return User::create([
            'name' => $handle,
            'nickname' => $nickname ?? $handle,
            'email' => $email,
            'password' => 'secret-password',
            'password_set_at' => now(),
            'role' => $role->value,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * 造一个管理员账号。
     *
     * 昵称与登录名一样由邮箱派生，**不能写死成某个常量**：
     * 昵称上有唯一索引，而「造两个管理员」是最常见的测试前置
     * （最后一个管理员的保护逻辑必须有两个管理员才测得了），
     * 写死常量会让那些用例直接撞唯一索引。
     */
    protected function admin(string $email = 'admin@example.test', ?string $nickname = null): User
    {
        return $this->user(UserRole::Admin, $email, $nickname);
    }

    /** 由邮箱局部名派生一个合法的登录名（全小写、字母开头、仅 ASCII）。 */
    protected function handleFromEmail(string $email): string
    {
        $local = strstr($email, '@', true) ?: $email;
        $handle = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($local)), '-');

        if ($handle === '' || preg_match('/^[a-z]/', $handle) !== 1) {
            $handle = 'u'.$handle;
        }

        return $handle;
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
