<?php

namespace App\Services;

use App\Enums\ChangeOrigin;
use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Enums\EventStatus;
use App\Enums\RevisionAction;
use App\Exceptions\EditConflictException;
use App\Exceptions\WriteDeniedException;
use App\Models\AiProposal;
use App\Models\Annotation;
use App\Models\Character;
use App\Models\Event;
use App\Models\EventRevision;
use App\Models\Faction;
use App\Models\Source;
use App\Models\Tag;
use App\Models\User;
use App\Support\TerraDateParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 事件写入的唯一入口。所有 CRUD 都必须经过这里，以保证三件事同时成立：
 *
 *  1. **不丢更新** —— 用 `version` 做 compare-and-swap（单条 SQL 的 WHERE version=?），
 *     而不是「先读后写」。这是数据库层面原子的，不依赖应用层锁。
 *  2. **可审计** —— 每次写入都留一份完整快照，并记录 changed_fields 与 origin（human / ai）。
 *  3. **可合并** —— 冲突时不粗暴报错，而是用 base / theirs / mine 三方比对，
 *     把「对方独有改动」自动并入，只把真正同字段打架的部分交还给用户裁决。
 *
 * 另外两条业务铁律：
 *  - 争议 / 废弃状态的条目正文冻结，只能走 annotation 建议通道；
 *  - AI 不能调用本类的 create/update —— 它只能写 ai_proposals，
 *    由 ProposalApplier 以「AI 起草 + 人工放行」的组合身份调用。
 */
final class EventWriter
{
    /** 允许通过 API 直接写入的字段白名单。 */
    public const EDITABLE_FIELDS = [
        'title', 'summary', 'details', 'location',
        'date_display', 'start_index', 'end_index', 'date_precision', 'date_confidence',
        'era_id', 'sort_seq', 'parent_event_id', 'caused_by_event_id', 'status',
    ];

    /** 关系型字段。 */
    public const RELATION_FIELDS = ['sources', 'characters', 'factions', 'tags'];

    /** 用于三方比对与 revision diff 的全量字段。 */
    public const TRACKED_FIELDS = [...self::EDITABLE_FIELDS, ...self::RELATION_FIELDS];

    private const FIELD_LABELS = [
        'title' => '标题',
        'summary' => '简要描述',
        'details' => '详述',
        'location' => '发生地',
        'date_display' => '时间（原文）',
        'start_index' => '时间起点',
        'end_index' => '时间终点',
        'date_precision' => '时间精度',
        'date_confidence' => '时间可信度',
        'era_id' => '所属纪元',
        'sort_seq' => '同日排序',
        'parent_event_id' => '上级事件',
        'caused_by_event_id' => '直接起因',
        'status' => '状态',
        'sources' => '出处',
        'characters' => '相关人物',
        'factions' => '相关阵营',
        'tags' => '标签',
    ];

    public function __construct(
        private readonly TimelineConsistencyChecker $checker,
        private readonly TerraDateParser $parser = new TerraDateParser,
    ) {}

    // ------------------------------------------------------------------ 创建

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        array $data,
        User $actor,
        ChangeOrigin $origin = ChangeOrigin::Human,
        ?AiProposal $proposal = null,
        ?string $ip = null,
    ): Event {
        if (! $actor->canEditEvents()) {
            throw new WriteDeniedException('当前账号没有编辑时间线的权限。');
        }

        $data = $this->normalizeDate($data);

        $event = DB::transaction(function () use ($data, $actor, $origin, $proposal, $ip) {
            /** @var Event $event */
            $event = Event::create([
                ...Arr::only($data, self::EDITABLE_FIELDS),
                'slug' => $this->uniqueSlug($data['title']),
                'version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'status' => $data['status'] ?? EventStatus::NeedsReview->value,
            ]);

            $this->syncRelations($event, $data, $actor);

            $this->recordRevision(
                event: $event->fresh(),
                action: RevisionAction::Created,
                origin: $origin,
                changedFields: self::EDITABLE_FIELDS,
                base: null,
                actor: $actor,
                proposal: $proposal,
                ip: $ip,
                comment: $proposal ? '由 AI 提案 #'.$proposal->id.' 经人工放行创建' : null,
            );

            return $event->fresh();
        });

        $this->checker->checkEvent($event);

        return $event->fresh(self::RELATION_FIELDS);
    }

    // ------------------------------------------------------------------ 更新

    /**
     * 乐观锁更新。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws EditConflictException 当 expectedVersion 落后于当前版本
     * @throws WriteDeniedException 当权限 / 条目状态不允许写入
     */
    public function update(
        Event $event,
        array $data,
        int $expectedVersion,
        User $actor,
        ChangeOrigin $origin = ChangeOrigin::Human,
        ?string $ip = null,
        RevisionAction $action = RevisionAction::Updated,
        ?string $comment = null,
    ): Event {
        $this->assertCanWrite($event, $actor);

        $data = $this->normalizeDate($data, $event->fresh());

        $base = $this->snapshotAtVersion($event->id, $expectedVersion);

        // 先做一次「读时校验」给用户友好的报错；真正的保证是下面 SQL 的 CAS。
        if ($event->fresh()->version !== $expectedVersion) {
            throw $this->buildConflict($event->fresh(self::RELATION_FIELDS), $expectedVersion, $data, $base);
        }

        $attributes = Arr::only($data, self::EDITABLE_FIELDS);

        if (($attributes['status'] ?? null) === EventStatus::Verified->value) {
            $attributes['verified_at'] = now();
            $attributes['verified_by'] = $actor->id;
        }

        $current = $event->fresh(self::RELATION_FIELDS);

        $event = DB::transaction(function () use ($event, $attributes, $expectedVersion, $data, $actor, $origin, $ip, $current, $base, $action, $comment) {
            // ★ compare-and-swap：条件更新，命中 0 行即说明有人抢先写入。
            $affected = Event::whereKey($event->id)
                ->where('version', $expectedVersion)
                ->update([
                    ...$attributes,
                    'version' => $expectedVersion + 1,
                    'updated_by' => $actor->id,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw $this->buildConflict(
                    Event::with(self::RELATION_FIELDS)->findOrFail($event->id),
                    $expectedVersion,
                    $data,
                    $base,
                );
            }

            $fresh = Event::findOrFail($event->id);
            $this->syncRelations($fresh, $data, $actor);

            $fresh = $fresh->fresh(self::RELATION_FIELDS);

            $changed = $this->diffFields($current, $fresh);

            $this->recordRevision(
                event: $fresh,
                action: $action,
                origin: $origin,
                changedFields: $changed,
                base: $base ?? $this->attributesOf($current),
                actor: $actor,
                proposal: null,
                ip: $ip,
                comment: $comment,
            );

            return $fresh;
        });

        $this->checker->checkEvent($event);

        return $event->fresh(self::RELATION_FIELDS);
    }

    /**
     * 冲突解决后写回。
     *
     * 调用方（前端合并面板）已经拿到字段级裁决结果，这里只负责把它拼成一次
     * 针对**最新版本**的 CAS 写入 —— 因此不会再次冲突，除非在这几秒内又有人写入。
     *
     * @param  array<string, 'mine'|'theirs'|mixed>  $resolutions  字段 => 裁决
     * @param  array<string, mixed>  $mine  己方原始提交
     */
    public function resolveConflict(
        Event $event,
        array $resolutions,
        array $mine,
        int $latestVersion,
        User $actor,
        ?string $ip = null,
    ): Event {
        $this->assertCanWrite($event, $actor);

        $theirs = $event->fresh(self::RELATION_FIELDS);
        $merged = [];

        foreach ($resolutions as $field => $choice) {
            if (! in_array($field, self::TRACKED_FIELDS, true)) {
                continue;
            }

            $merged[$field] = match (true) {
                $choice === 'mine' => $mine[$field] ?? null,
                $choice === 'theirs' => $this->fieldValue($theirs, $field),
                default => $choice, // 允许直接传自定义值
            };
        }

        // 未列入 resolutions 的字段：取己方提交（用户已确认过的整份表单）。
        foreach (self::TRACKED_FIELDS as $field) {
            if (! array_key_exists($field, $merged) && array_key_exists($field, $mine)) {
                $merged[$field] = $mine[$field];
            }
        }

        return $this->update($theirs, $merged, $latestVersion, $actor, ChangeOrigin::Human, $ip);
    }

    // ------------------------------------------------------------------ 删除 / 恢复 / 回滚

    public function delete(Event $event, User $actor, ?string $comment = null): void
    {
        if (! $actor->canEditEvents()) {
            throw new WriteDeniedException('当前账号没有删除条目的权限。');
        }

        if ($event->is_locked && ! $actor->canReview()) {
            throw new WriteDeniedException('条目已被审核员锁定，无法删除。');
        }

        DB::transaction(function () use ($event, $actor, $comment) {
            Event::whereKey($event->id)->update(['version' => $event->version + 1, 'updated_by' => $actor->id]);

            $this->recordRevision(
                event: $event->fresh(),
                action: RevisionAction::Deleted,
                origin: ChangeOrigin::Human,
                changedFields: ['deleted_at'],
                base: $this->attributesOf($event),
                actor: $actor,
                proposal: null,
                ip: request()?->ip(),
                comment: $comment,
            );

            $event->delete(); // 软删除：保留可追溯性
        });
    }

    public function restore(int $eventId, User $actor): Event
    {
        if (! $actor->canReview()) {
            throw new WriteDeniedException('恢复已删除条目需要审核员权限。');
        }

        $event = Event::withTrashed()->findOrFail($eventId);

        DB::transaction(function () use ($event, $actor) {
            $event->restore();
            $event->forceFill(['version' => $event->version + 1, 'updated_by' => $actor->id])->save();

            $this->recordRevision(
                event: $event->fresh(),
                action: RevisionAction::Restored,
                origin: ChangeOrigin::Human,
                changedFields: ['deleted_at'],
                base: null,
                actor: $actor,
                proposal: null,
                ip: request()?->ip(),
                comment: '恢复条目',
            );
        });

        return $event->fresh();
    }

    /** 回滚到指定历史版本（以一次新写入的形式，不破坏版本链）。 */
    public function revertTo(Event $event, int $version, User $actor): Event
    {
        if (! $actor->canReview()) {
            throw new WriteDeniedException('回滚版本需要审核员权限。');
        }

        $revision = EventRevision::where('event_id', $event->id)->where('version', $version)->firstOrFail();
        $snapshot = $revision->snapshot ?? [];

        $payload = Arr::only($snapshot, self::EDITABLE_FIELDS);

        foreach (self::RELATION_FIELDS as $relation) {
            if (array_key_exists($relation, $snapshot)) {
                $payload[$relation] = $snapshot[$relation];
            }
        }

        // 回滚本身就是一次普通写入，只是把 revision 动作标成 restored。
        // 刻意不额外再记一条 revision —— (event_id, version) 有唯一约束，
        // 同一版本写两次会直接撞唯一键。
        return $this->update(
            event: $event,
            data: $payload,
            expectedVersion: $event->fresh()->version,
            actor: $actor,
            origin: ChangeOrigin::Human,
            ip: request()?->ip(),
            action: RevisionAction::Restored,
            comment: '回滚到 v'.$version,
        );
    }

    // ------------------------------------------------------------------ 标注

    /**
     * 提交标注。刻意不要求编辑权限 —— 门槛低才能让纠错回路转起来。
     * actor 允许为 null（匿名访客纠错），此时 user_id 留空以示区别。
     *
     * @param  array<string, mixed>  $payload
     */
    public function annotate(Event $event, array $payload, ?User $actor): Annotation
    {
        return Annotation::create([
            'event_id' => $event->id,
            'user_id' => $actor?->id,
            'type' => $payload['type'] ?? 'comment',
            'field' => $payload['field'] ?? null,
            'body' => $payload['body'],
            'suggested_patch' => $payload['suggested_patch'] ?? null,
            'status' => 'open',
        ]);
    }

    // ------------------------------------------------------------------ 内部

    /**
     * 时间归一化：优先采用调用方显式给定的索引，否则由 date_display 解析。
     *
     * 关键点：**永远保留原文 date_display**。泰拉历的粒度千奇百怪，
     * 把「1097年冬」强行显示成「1097-12-01」会制造假精度，
     * 而这正是时间线类产品最容易失信的地方。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeDate(array $data, ?Event $current = null): array
    {
        $display = $data['date_display'] ?? $current?->date_display;

        if (blank($display) && $current === null) {
            $data['date_display'] = $data['date_display'] ?? '时间未定';
            $display = $data['date_display'];
        }

        $confidence = isset($data['date_confidence'])
            ? DateConfidence::tryFrom((string) $data['date_confidence']) ?? DateConfidence::Inferred
            : ($current?->date_confidence ?? DateConfidence::Inferred);

        $parsed = $this->parser->parse($display, $confidence);

        $explicitStart = $data['start_index'] ?? null;
        $hasExplicitStart = $explicitStart !== null && $explicitStart !== '';

        $data['date_display'] = $display;
        $data['date_confidence'] = $confidence->value;

        if ($hasExplicitStart) {
            // 人工手工指定时间：精度默认给 day，除非调用方另有说明。
            $data['start_index'] = (int) $explicitStart;
            $data['end_index'] = (int) ($data['end_index'] ?? $data['start_index']);
            $data['date_precision'] = $data['date_precision'] ?? DatePrecision::Day->value;
        } else {
            $data['start_index'] = $parsed->startIndex;
            $data['end_index'] = $data['end_index'] ?? $parsed->endIndex;
            $data['date_precision'] = $data['date_precision'] ?? $parsed->precision->value;
        }

        // 区间方向归一，避免 end < start 造成筛选漏命中。
        if ($data['end_index'] < $data['start_index']) {
            [$data['start_index'], $data['end_index']] = [$data['end_index'], $data['start_index']];
        }

        return $data;
    }

    private function assertCanWrite(Event $event, User $actor): void
    {
        if (! $actor->canEditEvents()) {
            throw new WriteDeniedException('当前账号没有编辑时间线的权限。');
        }

        if ($event->is_locked && ! $actor->canReview()) {
            throw new WriteDeniedException('条目已被审核员锁定，如需修改请联系审核员解锁。');
        }

        if ($event->isFrozen() && ! $actor->canReview()) {
            throw new WriteDeniedException('条目处于「'.$event->status->label().'」状态，正文已冻结，请改用标注提交修改建议。');
        }

        if ($actor->enforcesSourceScope() && ! $actor->canReview()) {
            $owned = $actor->ownedSourceIds();
            $attached = $event->sources()->pluck('sources.id')->all();

            // 只有「已归属出处」的条目才受范围限制；未归属的条目对所有编辑者开放。
            if ($attached !== [] && $owned !== [] && array_intersect($attached, $owned) === []) {
                throw new WriteDeniedException('该条目属于你负责范围之外的出处，如需修改请联系对应出处的负责人。');
            }
        }
    }

    /**
     * 三方比对，产出可直接渲染的字段级冲突报告。
     *
     * @param  array<string, mixed>  $mine
     * @param  array<string, mixed>|null  $base
     */
    private function buildConflict(Event $theirsEvent, int $expectedVersion, array $mine, ?array $base): EditConflictException
    {
        // 用 snapshotOf 而不是 attributesOf：关系字段（出处 / 人物 / 阵营 / 标签）也参与冲突比对，
        // 只比标量会出现「两个人都改了出处却没有任何提示」。
        $theirs = $this->snapshotOf($theirsEvent);
        $hasBase = $base !== null;
        $base ??= $theirs;

        $conflicts = [];
        $theirsOnly = [];
        $mineOnly = [];
        $autoMerged = [];

        foreach (self::TRACKED_FIELDS as $field) {
            $baseValue = $base[$field] ?? null;
            $theirsValue = $theirs[$field] ?? null;

            if (! array_key_exists($field, $mine)) {
                // 本次提交没碰这个字段：对方改了就用对方的，天然无冲突。
                if ($this->differs($baseValue, $theirsValue)) {
                    $theirsOnly[$field] = $theirsValue;
                    $autoMerged[$field] = $theirsValue;
                }

                continue;
            }

            $mineValue = $mine[$field];

            // 基线与目标版本完全一致 → 无法证明「只有我方改了」，
            // 此时把不一致一律当作冲突交给人裁决，绝不静默覆盖：
            // 宁可多问一次，也不能悄悄丢掉别人已经确认过的内容。
            if (! $hasBase) {
                if ($this->differs($theirsValue, $mineValue)) {
                    $conflicts[] = [
                        'field' => $field,
                        'label' => self::FIELD_LABELS[$field] ?? $field,
                        'base' => null,
                        'theirs' => $theirsValue,
                        'mine' => $mineValue,
                        'note' => '基线版本不可用（可能已被版本裁剪），需逐字段确认',
                    ];
                }

                continue;
            }

            $theirsChanged = $this->differs($baseValue, $theirsValue);
            $mineChanged = $this->differs($baseValue, $mineValue);

            if (! $mineChanged) {
                if ($theirsChanged) {
                    $theirsOnly[$field] = $theirsValue;
                    $autoMerged[$field] = $theirsValue;
                }

                continue;
            }

            if (! $theirsChanged) {
                $mineOnly[$field] = $mineValue;
                $autoMerged[$field] = $mineValue;

                continue;
            }

            // 双方都改了：值相同则不算冲突（殊途同归）
            if (! $this->differs($theirsValue, $mineValue)) {
                $autoMerged[$field] = $mineValue;

                continue;
            }

            $conflicts[] = [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field] ?? $field,
                'base' => $baseValue,
                'theirs' => $theirsValue,
                'mine' => $mineValue,
            ];
        }

        return new EditConflictException(
            eventId: $theirsEvent->id,
            expectedVersion: $expectedVersion,
            currentVersion: $theirsEvent->version,
            conflicts: $conflicts,
            autoMerged: $autoMerged,
            theirsOnly: $theirsOnly,
            mineOnly: $mineOnly,
            editorName: $theirsEvent->updater?->displayLabel() ?? $theirsEvent->updater?->name,
        );
    }

    /** 关系字段同步。 */
    private function syncRelations(Event $event, array $data, User $actor): void
    {
        if (array_key_exists('sources', $data)) {
            $sync = [];

            foreach ($data['sources'] ?? [] as $index => $row) {
                $sourceId = $this->resolveId($row, Source::class, null);
                if (! $sourceId) {
                    continue;
                }
                $sync[$sourceId] = [
                    'chapter' => $row['chapter'] ?? null,
                    'stage_code' => $row['stage_code'] ?? null,
                    'quote' => $row['quote'] ?? null,
                    'quote_offset' => $row['quote_offset'] ?? null,
                    'is_primary' => (bool) ($row['is_primary'] ?? $index === 0),
                    'sort_order' => $index,
                ];
            }

            $event->sources()->sync($sync);
        }

        if (array_key_exists('characters', $data)) {
            $sync = [];

            foreach ($data['characters'] ?? [] as $row) {
                $id = $this->resolveId($row, Character::class);
                if (! $id) {
                    continue;
                }
                $sync[$id] = ['role' => $row['role'] ?? 'support', 'note' => $row['note'] ?? null];
            }

            $event->characters()->sync($sync);
        }

        if (array_key_exists('factions', $data)) {
            $sync = [];

            foreach ($data['factions'] ?? [] as $row) {
                $id = $this->resolveId($row, Faction::class);
                if (! $id) {
                    continue;
                }
                $sync[$id] = ['role' => $row['role'] ?? 'involved', 'note' => $row['note'] ?? null];
            }

            $event->factions()->sync($sync);
        }

        if (array_key_exists('tags', $data)) {
            $ids = [];

            foreach ($data['tags'] ?? [] as $row) {
                if (is_array($row) && filled($row['name'] ?? null)) {
                    $tag = $this->firstOrCreateByName(Tag::class, (string) $row['name'], [
                        'color' => $row['color'] ?? '#8b5cf6',
                    ]);
                    $ids[] = $tag->id;

                    continue;
                }

                $id = $this->resolveId($row, Tag::class);
                if ($id) {
                    $ids[] = $id;
                }
            }

            $event->tags()->sync($ids);
        }
    }

    /** 关系行既可能给 id，也可能只给名称；名称不存在时按需创建。 */
    private function resolveId(mixed $row, string $modelClass, ?string $nameField = 'name'): ?int
    {
        if (is_numeric($row)) {
            return (int) $row;
        }

        if (! is_array($row)) {
            return null;
        }

        if (filled($row['id'] ?? null)) {
            return (int) $row['id'];
        }

        $name = $row['name'] ?? null;

        if (blank($name) || $nameField === null) {
            return null;
        }

        return $this->firstOrCreateByName($modelClass, (string) $name)->id;
    }

    /**
     * 按名称取字典项，不存在则建档。
     *
     * 必须先按 name 查询再创建：中文名称经 Str::slug() 会变成空串，
     * 直接拿 slug 做唯一键会为同名实体反复建档，产生大量重复阵营 / 人物。
     */
    private function firstOrCreateByName(string $modelClass, string $name, array $extra = []): Model
    {
        /** @var Model|null $existing */
        $existing = $modelClass::where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        /** @var Model $created */
        $created = $modelClass::create([
            'name' => $name,
            'slug' => Str::slug($name) ?: 'item-'.Str::lower(Str::random(8)),
            ...$extra,
        ]);

        return $created;
    }

    /** 写入版本快照。 */
    private function recordRevision(
        Event $event,
        RevisionAction $action,
        ChangeOrigin $origin,
        array $changedFields,
        ?array $base,
        User $actor,
        ?AiProposal $proposal,
        ?string $ip,
        ?string $comment = null,
    ): void {
        EventRevision::create([
            'event_id' => $event->id,
            'version' => $event->version,
            'action' => $action->value,
            'origin' => $origin->value,
            'changed_fields' => array_values($changedFields),
            'snapshot' => $this->snapshotOf($event),
            'base_snapshot' => $base,
            'comment' => $comment,
            'user_id' => $actor->id,
            'ai_proposal_id' => $proposal?->id,
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }

    /**
     * 获取指定版本对应的 base 快照（三方合并的共同祖先）。
     *
     * @return array<string, mixed>|null
     */
    private function snapshotAtVersion(int $eventId, int $version): ?array
    {
        $revision = EventRevision::where('event_id', $eventId)
            ->where('version', $version)
            ->first();

        return $revision?->snapshot;
    }

    /** 完整快照：属性 + 关系，便于回滚与冲突比对。 */
    public function snapshotOf(Event $event): array
    {
        return [
            ...$this->attributesOf($event),
            'sources' => $event->sources->map(fn (Source $s) => [
                'id' => $s->id,
                'chapter' => $s->pivot->chapter,
                'stage_code' => $s->pivot->stage_code,
                'quote' => $s->pivot->quote,
                'quote_offset' => $s->pivot->quote_offset,
                'is_primary' => (bool) $s->pivot->is_primary,
            ])->values()->all(),
            'characters' => $event->characters->map(fn (Character $c) => [
                'id' => $c->id,
                'role' => $c->pivot->role,
                'note' => $c->pivot->note,
            ])->values()->all(),
            'factions' => $event->factions->map(fn (Faction $f) => [
                'id' => $f->id,
                'role' => $f->pivot->role,
                'note' => $f->pivot->note,
            ])->values()->all(),
            'tags' => $event->tags->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
        ];
    }

    /** 只取可比较的标量属性（关系单独处理）。 */
    private function attributesOf(Event $event): array
    {
        $attributes = Arr::only($event->getAttributes(), self::EDITABLE_FIELDS);

        foreach (['date_precision' => $event->date_precision, 'date_confidence' => $event->date_confidence, 'status' => $event->status] as $field => $enum) {
            $attributes[$field] = $enum instanceof \BackedEnum ? $enum->value : $attributes[$field];
        }

        return $attributes;
    }

    /** 取任一字段的值（标量取属性，关系取快照数组）。 */
    private function fieldValue(Event $event, string $field): mixed
    {
        if (! in_array($field, self::RELATION_FIELDS, true)) {
            return $this->attributesOf($event)[$field] ?? null;
        }

        return $this->snapshotOf($event)[$field] ?? [];
    }

    /** @return array<int, string> */
    private function diffFields(Event $before, Event $after): array
    {
        $changed = [];

        foreach (self::TRACKED_FIELDS as $field) {
            if ($this->differs($this->fieldValue($before, $field), $this->fieldValue($after, $field))) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function differs(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return json_encode($this->canonical($a ?? [])) !== json_encode($this->canonical($b ?? []));
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (string) $a !== (string) $b;
        }

        return (string) ($a ?? '') !== (string) ($b ?? '');
    }

    /** 数组规范化，避免键序差异造成伪冲突。 */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->canonical($v), $value);
        }

        ksort($value);

        return array_map(fn ($v) => $this->canonical($v), $value);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $base = $base !== '' ? $base : 'event-'.Str::lower(Str::random(6));

        $slug = $base;
        $i = 2;

        while (Event::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
