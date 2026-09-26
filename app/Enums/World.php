<?php

namespace App\Enums;

/**
 * 时间线所属的世界（星球）。
 *
 * ## 为什么必须有这个维度
 *
 * 本系统的排序键是一个**没有量纲的整数网格**：`index = year * 372 + (month-1) * 31 + (day-1)`。
 * 它只在「同一个纪年体系内部」才有意义 —— 泰拉历 1097 年与塔罗斯历 5 年之间
 * 不存在任何可比关系，把两者的数值放进同一条 ORDER BY 里排序，
 * 得到的是一个**看起来正常、实际毫无意义**的顺序，而且不会报任何错。
 *
 * 更具体的事故：泰拉的第一个纪元「远古 · 前纪元」区间是 `toIndex(-500) ~ toIndex(999)`，
 * 也就是 -186000 ~ 371627。塔罗斯历 5 年的索引只有 1860，**恰好落在里面** ——
 * 于是 `reindexEraAssignments()` 会把塔卫二的事件归进泰拉纪元，且无声无息。
 *
 * 因此世界不是「多一个筛选项」，而是所有区间比较的前提：
 * 纪元区间不重叠、事件归属纪元、时间轴刻度、年代分布统计，全部必须先按世界分组。
 *
 * ## 关于塔罗斯历
 *
 * 「塔罗斯历」这个纪年来自社区考据整理（游戏内文本尚未逐条录入本仓库），
 * 因此塔卫二的条目一律以 `date_confidence = inferred` 入库并标记 needs_review ——
 * 可信度字段存在的意义就是让这种情况能如实表达，而不是假装确证。
 */
enum World: string
{
    /** 泰拉：《明日方舟》主线所在的星球。 */
    case Terra = 'terra';

    /** 塔卫二：《明日方舟：终末地》的主世界，气态行星塔罗斯的二号卫星。 */
    case Talos = 'talos';

    /** 缺省世界。历史数据与未指定世界的写入一律落在泰拉。 */
    public static function default(): self
    {
        return self::Terra;
    }

    /** 容错解析：非法值回落到缺省世界而不是抛异常（筛选参数不该让页面 500）。 */
    public static function fromRequest(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::default()) : self::default();
    }

    public function label(): string
    {
        return match ($this) {
            self::Terra => '泰拉',
            self::Talos => '塔卫二',
        };
    }

    /** 界面上的英文微标签，与全站「大写等宽」的语言一致。 */
    public function englishLabel(): string
    {
        return match ($this) {
            self::Terra => 'Terra',
            self::Talos => 'Talos-II',
        };
    }

    /**
     * 该世界使用的纪年名称。
     *
     * 用于把「约 泰拉历 1097 年 3 月」这类回溯文案里的历法名换对 ——
     * 在塔卫二的条目上写「泰拉历」是彻底的错误信息。
     */
    public function calendarLabel(): string
    {
        return match ($this) {
            self::Terra => '泰拉历',
            self::Talos => '塔罗斯历',
        };
    }

    /** 一句话特征，用于世界切换器。 */
    public function tagline(): string
    {
        return match ($this) {
            self::Terra => '源石与天灾之下的诸国',
            self::Talos => '穿过星门之后的第二家园',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Terra => '《明日方舟》主线所在的星球。纪元以泰拉历计，官方文本粒度不一，推断与矛盾并存。',
            self::Talos => '《明日方舟：终末地》的主世界，气态行星塔罗斯的二号卫星。'
                .'泰拉文明经星门重返此地「开拓」，当前约在塔罗斯历 152 年。'
                .'纪年与年份均来自社区考据，尚无官方原文逐条核对。',
        };
    }

    /** 世界标识色。取自站点既有调色板：泰拉用标志黄，塔卫二用冷青。 */
    public function accent(): string
    {
        return match ($this) {
            self::Terra => '#ffd400',
            self::Talos => '#57c7d4',
        };
    }

    /** 该世界的纪元配色族。泰拉用暖色递进，塔卫二用冷色递进，两者一眼可辨。 */
    public function eraPalette(): array
    {
        return match ($this) {
            self::Terra => ['#808080', '#9a7d18', '#c9a227', '#ffd400', '#ffa32e', '#ff7038', '#ff4242'],
            self::Talos => ['#3f6b7a', '#5596a6', '#6fc3d4'],
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $w) => [$w->value => $w->label()])
            ->all();
    }

    /**
     * 切换器需要的完整信息。
     *
     * @return list<array{value: string, label: string, english: string, calendar: string, tagline: string, description: string, accent: string}>
     */
    public static function switcherOptions(): array
    {
        return collect(self::cases())
            ->map(fn (self $w) => [
                'value' => $w->value,
                'label' => $w->label(),
                'english' => $w->englishLabel(),
                'calendar' => $w->calendarLabel(),
                'tagline' => $w->tagline(),
                'description' => $w->description(),
                'accent' => $w->accent(),
            ])
            ->all();
    }
}
