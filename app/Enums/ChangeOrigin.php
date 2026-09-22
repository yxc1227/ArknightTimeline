<?php

namespace App\Enums;

/** 一次写入的来源。用于审计，也用于「AI 内容必须经人工确认」这条规则的落地校验。 */
enum ChangeOrigin: string
{
    case Human = 'human';
    case Ai = 'ai';
    case Import = 'import';
    case Seed = 'seed';

    public function label(): string
    {
        return match ($this) {
            self::Human => '人工编辑',
            self::Ai => 'AI 生成',
            self::Import => '批量导入',
            self::Seed => '初始数据',
        };
    }
}
