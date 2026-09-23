<?php

namespace App\Enums;

/**
 * 编辑权限分级（RBAC）。
 *
 * viewer < editor < reviewer < admin
 *
 * - viewer   只读；可提交 annotation（评论 / 纠错建议）
 * - editor   可增删改事件；可对 AI 提案发起审核请求；受 source 归属与条目锁约束
 * - reviewer 可审核 AI 提案、处理冲突与时间线异常、回滚版本、锁定 / 解锁条目
 * - admin    管理账号、来源、阵营、纪元等字典，拥有全部权限
 *
 * 关于命名：**枚举值是与数据库、权限判断绑定的标识，一旦上线就不再改动**；
 * 对外展示的名称则采用《明日方舟》世界观里的职级（罗德岛的编制体系）。
 * 两者刻意分离 —— 否则某天想把「精英干员」改叫别的，就得改数据库里的每一行。
 *
 * 命名依据（罗德岛的在编序列，由高到低）：
 *   博士 → 精英干员 → 干员 → 预备干员
 * 这套序列恰好是四档，「权限越大、在编层级越高」的直觉与世界观一致，
 * 因此不需要为某个角色硬造一个不存在的头衔。
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

    /**
     * 展示名。
     *
     * 「博士」在原文里是一个人的头衔而非职位，用它承担最高权限是刻意的：
     * 在这套世界观里，只有博士能对全局做最终决定，正好对应本系统的管理员。
     */
    public function label(): string
    {
        return match ($this) {
            self::Viewer => '预备干员',
            self::Editor => '干员',
            self::Reviewer => '精英干员',
            self::Admin => '博士',
        };
    }

    /** 一句权限说明。用在角色选择器与账号详情里，避免用户凭头衔猜权限。 */
    public function description(): string
    {
        return match ($this) {
            self::Viewer => '已登记但未获外勤权限。仅可浏览时间线、提交标注建议。',
            self::Editor => '在编干员。可编写与修订条目、发起 AI 梳理；受出处归属与条目锁约束。',
            self::Reviewer => '已获完全信任的干员。可审核 AI 提案、裁定争议、回滚版本、锁定条目。',
            self::Admin => '罗德岛的最高指挥。可管理账号与全部字典，拥有所有权限。',
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

    /**
     * 带说明的选项，供角色选择器渲染。
     *
     * @return list<array{value: string, label: string, description: string, level: int}>
     */
    public static function describedOptions(): array
    {
        return collect(self::cases())
            ->map(fn (self $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'description' => $r->description(),
                'level' => $r->level(),
            ])
            ->all();
    }
}
