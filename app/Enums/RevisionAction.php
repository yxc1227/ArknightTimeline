<?php

namespace App\Enums;

/** 版本动作。 */
enum RevisionAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';   // 回滚到某个历史版本
    case Merged = 'merged';       // 冲突合并后写入
    case Annotated = 'annotated'; // 仅标注变更

    public function label(): string
    {
        return match ($this) {
            self::Created => '创建',
            self::Updated => '编辑',
            self::Deleted => '删除',
            self::Restored => '回滚',
            self::Merged => '合并',
            self::Annotated => '标注',
        };
    }
}
