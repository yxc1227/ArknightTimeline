<?php

namespace App\Http\Requests\Concerns;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Enums\EventStatus;
use Illuminate\Validation\Rule;

/**
 * 事件条目的字段规则。创建与更新共用，避免两处规则漂移
 * —— 校验规则漂移是协作系统里最隐蔽的 bug 来源之一。
 */
trait HasEventRules
{
    /**
     * 关系数组统一走「id 或 name 二选一」：
     * 允许前端提交尚不存在的人名 / 阵营名，由 EventWriter 按需建档，
     * 这样编辑者不必先去字典页建条目再回来挂载，录入摩擦最小。
     */
    protected function eventRules(bool $creating = true): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:160'],
            'summary' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:2000'],
            'details' => ['nullable', 'string', 'max:20000'],
            'location' => ['nullable', 'string', 'max:120'],

            // 时间：原文必填，索引可选（留空则由 TerraDateParser 从原文推导）
            'date_display' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'start_index' => ['nullable', 'integer', 'between:-2000000,2000000'],
            'end_index' => ['nullable', 'integer', 'between:-2000000,2000000'],
            'date_precision' => ['nullable', Rule::enum(DatePrecision::class)],
            'date_confidence' => ['nullable', Rule::enum(DateConfidence::class)],

            'era_id' => ['nullable', 'integer', 'exists:eras,id'],
            'sort_seq' => ['nullable', 'integer', 'between:-9999,9999'],
            'parent_event_id' => ['nullable', 'integer', 'exists:events,id'],
            'caused_by_event_id' => ['nullable', 'integer', 'exists:events,id'],
            'status' => ['nullable', Rule::enum(EventStatus::class)],

            'sources' => ['nullable', 'array', 'max:20'],
            'sources.*.id' => ['nullable', 'integer', 'exists:sources,id'],
            'sources.*.chapter' => ['nullable', 'string', 'max:120'],
            'sources.*.stage_code' => ['nullable', 'string', 'max:60'],
            'sources.*.quote' => ['nullable', 'string', 'max:4000'],
            'sources.*.quote_offset' => ['nullable', 'integer', 'min:0'],
            'sources.*.is_primary' => ['nullable', 'boolean'],

            'characters' => ['nullable', 'array', 'max:40'],
            'characters.*.id' => ['nullable', 'integer', 'exists:characters,id'],
            'characters.*.name' => ['nullable', 'string', 'max:60'],
            'characters.*.role' => ['nullable', 'in:protagonist,support,mentioned'],
            'characters.*.note' => ['nullable', 'string', 'max:300'],

            'factions' => ['nullable', 'array', 'max:30'],
            'factions.*.id' => ['nullable', 'integer', 'exists:factions,id'],
            'factions.*.name' => ['nullable', 'string', 'max:60'],
            'factions.*.role' => ['nullable', 'in:instigator,involved,victim'],
            'factions.*.note' => ['nullable', 'string', 'max:300'],

            'tags' => ['nullable', 'array', 'max:12'],
            'tags.*.id' => ['nullable', 'integer', 'exists:tags,id'],
            'tags.*.name' => ['nullable', 'string', 'max:40'],
        ];
    }

    /** 编辑备注，会写进 revision.comment，便于事后理解「这次改动意图」。 */
    protected function eventMessages(): array
    {
        return [
            'title.required' => '事件标题必填。',
            'summary.required' => '简要描述必填。',
            'date_display.required' => '游戏内纪元时间必填；确实未知时请填「时间未定」。',
        ];
    }
}
