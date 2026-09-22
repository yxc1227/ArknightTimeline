<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 乐观锁冲突。
 *
 * 携带三方合并所需的全部信息，让客户端能直接渲染字段级 diff，
 * 而不是只抛一句「保存失败，请刷新」——后者在多人协作里等于丢失用户输入。
 */
class EditConflictException extends RuntimeException
{
    /**
     * @param  array<int, array{field:string, label:string, base:mixed, theirs:mixed, mine:mixed}>  $conflicts
     * @param  array<string, mixed>  $autoMerged  仅一方修改、可自动合并的字段
     * @param  array<string, mixed>  $theirsOnly  对方独有改动
     * @param  array<string, mixed>  $mineOnly  己方独有改动
     */
    public function __construct(
        public readonly int $eventId,
        public readonly int $expectedVersion,
        public readonly int $currentVersion,
        public readonly array $conflicts,
        public readonly array $autoMerged = [],
        public readonly array $theirsOnly = [],
        public readonly array $mineOnly = [],
        public readonly ?string $editorName = null,
    ) {
        parent::__construct(sprintf(
            '条目 #%d 已被他人更新（你基于 v%d，当前 v%d），存在 %d 个字段冲突。',
            $eventId,
            $expectedVersion,
            $currentVersion,
            count($conflicts),
        ));
    }

    public function hasFieldConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    public function toApiPayload(): array
    {
        return [
            'error' => 'edit_conflict',
            'message' => $this->getMessage(),
            'event_id' => $this->eventId,
            'expected_version' => $this->expectedVersion,
            'current_version' => $this->currentVersion,
            'editor' => $this->editorName,
            'conflicts' => $this->conflicts,
            'auto_merged' => $this->autoMerged,
            'theirs_only' => $this->theirsOnly,
            'mine_only' => $this->mineOnly,
            'resolution_hint' => '请逐字段选择保留版本，然后带上 current_version 与 resolve_conflicts 重试。',
        ];
    }
}
