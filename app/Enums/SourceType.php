<?php

namespace App\Enums;

/** 事件出处的载体类型。 */
enum SourceType: string
{
    case MainStory = 'main_story';         // 主线剧情
    case SideStory = 'side_story';         // 支线 / 干员密录
    case Event = 'event';                  // 活动剧情
    case OperatorRecord = 'operator_record'; // 干员档案 / 语音
    case Artbook = 'artbook';              // 官方设定集
    case Setting = 'setting';              // 官方世界观设定 / 年表
    case Anime = 'anime';                  // 动画 / 影像
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MainStory => '主线剧情',
            self::SideStory => '支线剧情',
            self::Event => '活动剧情',
            self::OperatorRecord => '干员档案',
            self::Artbook => '官方设定集',
            self::Setting => '世界观设定',
            self::Anime => '动画影像',
            self::Other => '其他',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $t) => [$t->value => $t->label()])
            ->all();
    }
}
