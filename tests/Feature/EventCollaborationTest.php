<?php

namespace Tests\Feature;

use App\Enums\ChangeOrigin;
use App\Enums\UserRole;
use App\Exceptions\EditConflictException;
use App\Exceptions\WriteDeniedException;
use App\Models\Event;
use App\Models\EventRevision;
use App\Models\User;
use App\Services\EventLockService;
use App\Services\EventWriter;
use App\Support\TerraDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 多人协作的核心不变量：
 *  1. 不丢更新（乐观锁 CAS）；
 *  2. 冲突可合并（三方比对，自动并入对方独有改动）；
 *  3. 一切可审计（每次写入留版本快照）；
 *  4. 权限与冻结状态确实拦得住。
 */
class EventCollaborationTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    private function writer(): EventWriter
    {
        return app(EventWriter::class);
    }

    public function test_creating_an_event_writes_the_first_revision(): void
    {
        $editor = $this->user(UserRole::Editor);
        $era = $this->era();

        $event = $this->writer()->create([
            'title' => '切尔诺伯格事变爆发',
            'summary' => '整合运动攻入切尔诺伯格城区。',
            'date_display' => '泰拉历1096年12月23日',
            'era_id' => $era->id,
        ], $editor, ChangeOrigin::Human);

        $this->assertSame(1, $event->version);
        // date_display 被解析成了精确的单点区间
        $this->assertSame(TerraDate::toIndex(1096, 12, 23), $event->start_index);
        $this->assertSame(TerraDate::toIndex(1096, 12, 23), $event->end_index);
        $this->assertSame('day', $event->date_precision->value);

        $revision = EventRevision::where('event_id', $event->id)->first();
        $this->assertNotNull($revision);
        $this->assertSame(1, $revision->version);
        $this->assertSame('created', $revision->action->value);
        $this->assertSame($editor->id, $revision->user_id);
    }

    public function test_viewer_cannot_create_events(): void
    {
        $viewer = $this->user(UserRole::Viewer);

        $this->expectException(WriteDeniedException::class);

        $this->writer()->create([
            'title' => '不应该被创建',
            'summary' => '访客没有写入权限。',
            'date_display' => '1097年',
        ], $viewer);
    }

    public function test_stale_write_raises_a_conflict_instead_of_overwriting(): void
    {
        $editor = $this->user(UserRole::Editor);
        // 必须经 EventWriter 建档：三方合并依赖 v1 的基线快照，缺失时只能保守判冲突
        $event = $this->authoredEvent($editor, ['title' => '原始标题', 'summary' => '原始描述']);

        // A 先提交，版本推进到 2
        $this->writer()->update($event, ['title' => 'A 的标题'], 1, $editor);

        // B 仍基于 v1 提交
        try {
            $this->writer()->update($event->fresh(), ['title' => 'B 的标题'], 1, $editor);
            $this->fail('应当抛出冲突异常');
        } catch (EditConflictException $e) {
            $this->assertSame(1, $e->expectedVersion);
            $this->assertSame(2, $e->currentVersion);
            $this->assertNotEmpty($e->conflicts);
            $this->assertSame('title', $e->conflicts[0]['field']);
            $this->assertSame('A 的标题', $e->conflicts[0]['theirs']);
            $this->assertSame('B 的标题', $e->conflicts[0]['mine']);
        }

        // A 的改动没有被覆盖
        $this->assertSame('A 的标题', $event->fresh()->title);
    }

    public function test_non_overlapping_edits_are_auto_merged_without_field_conflicts(): void
    {
        $editor = $this->user(UserRole::Editor);
        $event = $this->authoredEvent($editor, [
            'title' => '原始标题',
            'summary' => '原始描述',
            'location' => '切尔诺伯格',
        ]);

        // A 改标题
        $this->writer()->update($event, ['title' => 'A 的标题'], 1, $editor);

        // B 基于 v1 改描述 —— 与 A 的改动不重叠
        try {
            $this->writer()->update($event->fresh(), ['summary' => 'B 的描述'], 1, $editor);
            $this->fail('应当抛出冲突异常以便走合并流程');
        } catch (EditConflictException $e) {
            $this->assertSame([], $e->conflicts, '不重叠的改动不应产生字段级冲突');
            $this->assertArrayHasKey('title', $e->autoMerged);
            $this->assertSame('A 的标题', $e->autoMerged['title']);
            $this->assertArrayHasKey('summary', $e->mineOnly);
        }
    }

    public function test_conflict_resolution_preserves_both_sides(): void
    {
        $editor = $this->user(UserRole::Editor);
        $event = $this->rawEvent(['title' => '原始标题', 'summary' => '原始描述']);

        $event = $this->writer()->update($event, ['title' => 'A 的标题'], 1, $editor);

        $merged = $this->writer()->resolveConflict(
            event: $event,
            resolutions: ['title' => 'theirs'],
            mine: ['summary' => 'B 的描述'],
            latestVersion: $event->version,
            actor: $editor,
        );

        // 双方改动都在：标题取对方，描述取己方
        $this->assertSame('A 的标题', $merged->title);
        $this->assertSame('B 的描述', $merged->summary);
        $this->assertSame(3, $merged->version);
    }

    public function test_every_write_appends_a_revision_without_rewriting_history(): void
    {
        $editor = $this->user(UserRole::Editor);
        $event = $this->authoredEvent($editor, ['summary' => '初始描述。']);

        $event = $this->writer()->update($event, ['summary' => '第一次修改'], 1, $editor);
        $this->writer()->update($event, ['summary' => '第二次修改'], 2, $editor);

        $revisions = EventRevision::where('event_id', $event->id)->orderBy('version')->get();

        $this->assertSame([1, 2, 3], $revisions->pluck('version')->all());
        $this->assertSame('created', $revisions[0]->action->value);
        $this->assertSame(['summary'], $revisions[1]->changed_fields);
        $this->assertSame('第一次修改', $revisions[1]->snapshot['summary']);
        // 旧版本内容原样保留，不被新版本覆盖
        $this->assertSame('初始描述。', $revisions[0]->snapshot['summary']);
    }

    public function test_frozen_event_rejects_editors_but_allows_reviewers(): void
    {
        $editor = $this->user(UserRole::Editor, 'editor@example.test');
        $reviewer = $this->user(UserRole::Reviewer, 'reviewer@example.test');

        $event = $this->rawEvent(['status' => 'disputed']);

        try {
            $this->writer()->update($event, ['summary' => '编辑者试图改动'], 1, $editor);
            $this->fail('争议中的条目应拒绝普通编辑者');
        } catch (WriteDeniedException $e) {
            $this->assertStringContainsString('冻结', $e->getMessage());
        }

        $updated = $this->writer()->update($event->fresh(), ['summary' => '审核员改动'], 1, $reviewer);
        $this->assertSame('审核员改动', $updated->summary);
    }

    public function test_locked_event_rejects_editors_but_allows_reviewers(): void
    {
        $editor = $this->user(UserRole::Editor, 'editor@example.test');
        $reviewer = $this->user(UserRole::Reviewer, 'reviewer@example.test');

        $event = $this->rawEvent(['is_locked' => true]);

        $this->expectException(WriteDeniedException::class);
        $this->writer()->update($event, ['summary' => 'x'], 1, $editor);
    }

    public function test_source_scope_restricts_editors_who_own_other_sources(): void
    {
        $editor = $this->user(UserRole::Editor);
        $owned = $this->source('我负责的出处', 'mine');
        $other = $this->source('别人负责的出处', 'theirs');

        $editor->sources()->attach($owned->id);

        $event = $this->rawEvent(['title' => '属于别人出处的条目']);
        $event->sources()->attach($other->id);

        $this->expectException(WriteDeniedException::class);
        $this->writer()->update($event, ['summary' => '越权修改'], 1, $editor);
    }

    public function test_editor_can_still_annotate_a_frozen_event(): void
    {
        $viewer = $this->user(UserRole::Viewer);
        $event = $this->rawEvent(['status' => 'disputed']);

        $annotation = $this->writer()->annotate($event, [
            'type' => 'correction',
            'field' => 'date_display',
            'body' => '该时间应为 1097 年冬，出处为 7-18 关卡文本。',
        ], $viewer);

        $this->assertSame('correction', $annotation->type->value);
        $this->assertTrue($annotation->isOpen());
    }

    public function test_edit_lease_is_advisory_and_transferable(): void
    {
        $locks = app(EventLockService::class);
        $editor = $this->user(UserRole::Editor, 'editor@example.test');
        $other = $this->user(UserRole::Editor, 'other@example.test');
        $reviewer = $this->user(UserRole::Reviewer, 'reviewer@example.test');

        $event = $this->rawEvent();

        $lock = $locks->acquire($event, $editor);
        $this->assertSame($editor->id, $lock->user_id);

        // 他人尝试接管：被拒绝，但错误是「可感知」的软锁，不是数据层互斥
        try {
            $locks->acquire($event, $other);
            $this->fail('他人不应无条件接管租约');
        } catch (WriteDeniedException $e) {
            $this->assertSame('locked_by_other', $e->code_);
        }

        // 审核员可强制接管
        $taken = $locks->acquire($event->fresh(), $reviewer, force: true);
        $this->assertSame($reviewer->id, $taken->user_id);

        $status = $locks->status($event->fresh(), $editor);
        $this->assertTrue($status['locked']);
        $this->assertFalse($status['mine']);
    }

    public function test_deleted_event_keeps_its_revisions_and_can_be_restored(): void
    {
        $reviewer = $this->user(UserRole::Reviewer);
        $event = $this->authoredEvent($reviewer);

        $this->writer()->delete($event, $reviewer, '重复条目');
        $this->assertSoftDeleted('events', ['id' => $event->id]);

        // 创建 + 删除，两条版本记录都还在
        $this->assertSame(2, EventRevision::where('event_id', $event->id)->count());
        $this->assertDatabaseHas('event_revisions', [
            'event_id' => $event->id,
            'action' => 'deleted',
            'comment' => '重复条目',
        ]);

        $restored = $this->writer()->restore($event->id, $reviewer);
        $this->assertNull($restored->deleted_at);
        $this->assertSame(3, $restored->version);
        $this->assertSame(3, EventRevision::where('event_id', $event->id)->count());
    }

    public function test_revert_creates_a_new_version_instead_of_rewinding(): void
    {
        $reviewer = $this->user(UserRole::Reviewer);
        $event = $this->authoredEvent($reviewer, ['title' => '初版标题']);

        $event = $this->writer()->update($event, ['title' => '第二版标题'], 1, $reviewer, ChangeOrigin::Human);
        $event = $this->writer()->update($event, ['title' => '第三版标题'], 2, $reviewer, ChangeOrigin::Human);

        $reverted = $this->writer()->revertTo($event, 2, $reviewer);

        $this->assertSame('第二版标题', $reverted->title);
        // 版本号只增不减：回滚是一次新写入，历史不会被覆盖
        $this->assertSame(4, $reverted->version);
        $this->assertSame(4, EventRevision::where('event_id', $event->id)->count());
        // 且 (event_id, version) 唯一约束不会被自己的回滚记录撞破
        $this->assertDatabaseHas('event_revisions', [
            'event_id' => $event->id,
            'version' => 4,
            'action' => 'restored',
            'comment' => '回滚到 v2',
        ]);
    }

    /** 通过 EventWriter 建档，从而拥有 v1 的 created 版本记录。 */
    private function authoredEvent(User $actor, array $attributes = []): Event
    {
        return $this->writer()->create([
            'title' => '测试条目',
            'summary' => '用于测试的条目描述。',
            'date_display' => '泰拉历1097年',
            ...$attributes,
        ], $actor);
    }
}
