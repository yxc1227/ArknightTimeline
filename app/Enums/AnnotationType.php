<?php

namespace App\Enums;

/** 标注（annotation）类型。user 对任意条目可标注，无需编辑权限。 */
enum AnnotationType: string
{
    case Comment = 'comment';           // 普通备注
    case Correction = 'correction';     // 纠错建议（可携带字段级补丁）
    case Question = 'question';         // 存疑提问
    case Verification = 'verification'; // 审核结论 / 二次确认

    public function label(): string
    {
        return match ($this) {
            self::Comment => '备注',
            self::Correction => '纠错',
            self::Question => '存疑',
            self::Verification => '复核',
        };
    }
}
