<?php

namespace App\Enums;

/**
 * 编辑权限分级（RBAC）。
 *
 * viewer   < editor < reviewer < admin
 *
 * - viewer   只读；可提交 annotation（评论 / 纠错建议）
 * - editor   可增删改事件；可对 AI 提案发起审核请求；受 source 归属与条目锁约束
 * - reviewer 可审核 AI 提案、处理冲突与时间线异常、回滚版本、锁定 / 解锁条目
 * - admin    管理用户、来源、阵营、纪元等字典，拥有全部权限
 */
enum UserRole: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Reviewer = 'reviewer';
    case Admin = 'admin';

    public function level(): int
    {
        return match ($this) {
            self::Viewer => 1,
            self::Editor => 2,
            self::Reviewer => 3,
            self::Admin => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Viewer => '访客',
            self::Editor => '编辑者',
            self::Reviewer => '审核员',
            self::Admin => '管理员',
        };
    }

    /** 是否达到某个角色等级。 */
    public function atLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    public function canEditEvents(): bool
    {
        return $this->atLeast(self::Editor);
    }

    public function canReview(): bool
    {
        return $this->atLeast(self::Reviewer);
    }

    public function canAdminister(): bool
    {
        return $this === self::Admin;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $r) => [$r->value => $r->label()])
            ->all();
    }
}
