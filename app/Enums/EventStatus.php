<?php

namespace App\Enums;

/** 条目生命周期状态。AI 产出的条目默认落在 NeedsReview，不直接对外可见为「已确证」。 */
enum EventStatus: string
{
    case Draft = 'draft';               // 草稿，仅作者与审核员可见
    case NeedsReview = 'needs_review';  // 待校验（AI 产出 / 人工存疑提交）
    case Verified = 'verified';         // 已校验
    case Disputed = 'disputed';         // 存在争议，正文冻结，仅允许补充注释
    case Deprecated = 'deprecated';     // 已被取代 / 证伪，保留供追溯

    public function label(): string
    {
        return match ($this) {
            self::Draft => '草稿',
            self::NeedsReview => '待校验',
            self::Verified => '已校验',
            self::Disputed => '争议中',
            self::Deprecated => '已废弃',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'badge badge--muted',
            self::NeedsReview => 'badge badge--warn',
            self::Verified => 'badge badge--ok',
            self::Disputed => 'badge badge--danger',
            self::Deprecated => 'badge badge--muted',
        };
    }

    /** 徽章用的状态图标（见 docs/ICONS.md「状态」段，经 <x-icon> 渲染）。 */
    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'status-info',
            self::NeedsReview => 'status-warn',
            self::Verified => 'status-ok',
            self::Disputed => 'status-danger',
            self::Deprecated => 'status-info',
        };
    }

    /** 争议中与已废弃的条目禁止直接改正文，只能提交建议。 */
    public function isFrozen(): bool
    {
        return in_array($this, [self::Disputed, self::Deprecated], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}
