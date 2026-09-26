<?php

namespace Tests\Feature;

use App\Enums\AnomalyType;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\TimelineAnomaly;
use App\Services\EventWriter;
use App\Services\TimelineConsistencyChecker;
use App\Support\TerraDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 一致性巡检是「冲突处理」的第二条线：
 * 乐观锁解决的是同一秒的写入竞争，巡检解决的是语义层的不一致。
 * 这里验证规则确实能发现问题，并且能随数据修正自动收敛。
 */
class TimelineConsistencyTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    private function checker(): TimelineConsistencyChecker
    {
        return app(TimelineConsistencyChecker::class);
    }

    public function test_detects_order_inversion_between_effect_and_cause(): void
    {
        $cause = $this->rawEvent([
            'title' => '切尔诺伯格事变爆发',
            'start_index' => TerraDate::toIndex(1099, 1, 1),
            'end_index' => TerraDate::toIndex(1099, 1, 1),
        ]);

        $effect = $this->rawEvent([
            'title' => '罗德岛撤离切尔诺伯格',
            'start_index' => TerraDate::toIndex(1096, 12, 24),
            'end_index' => TerraDate::toIndex(1096, 12, 24),
            'caused_by_event_id' => $cause->id,
        ]);

        $produced = $this->checker()->checkEvent($effect);

        $this->assertContains(AnomalyType::OrderInversion->value, array_column($produced, 'type'));
        $this->assertDatabaseHas('timeline_anomalies', [
            'event_id' => $effect->id,
            'related_event_id' => $cause->id,
            'type' => AnomalyType::OrderInversion->value,
            'severity' => 'error',
            'status' => 'open',
        ]);

        // 阻断级异常会挡住「标记已校验」
        $this->assertTrue($effect->fresh()->hasBlockingAnomalies());
    }

    public function test_order_inversion_is_confirmed_when_dates_are_fixed(): void
    {
        $cause = $this->rawEvent([
            'title' => '起因',
            'start_index' => TerraDate::toIndex(1099, 1, 1),
            'end_index' => TerraDate::toIndex(1099, 1, 1),
        ]);

        $effect = $this->rawEvent([
            'title' => '结果',
            'start_index' => TerraDate::toIndex(1096, 1, 1),
            'end_index' => TerraDate::toIndex(1096, 1, 1),
            'caused_by_event_id' => $cause->id,
        ]);

        $this->checker()->checkEvent($effect);
        $this->assertDatabaseHas('timeline_anomalies', [
            'event_id' => $effect->id,
            'type' => AnomalyType::OrderInversion->value,
            'status' => 'open',
        ]);

        // 把结果挪到起因之后
        Event::whereKey($effect->id)->update([
            'start_index' => TerraDate::toIndex(1100, 1, 1),
            'end_index' => TerraDate::toIndex(1100, 1, 1),
        ]);

        $this->checker()->checkEvent($effect->fresh());

        // 收敛：本轮未复现的异常自动销案，收件箱不会累积噪音
        $this->assertDatabaseHas('timeline_anomalies', [
            'event_id' => $effect->id,
            'type' => AnomalyType::OrderInversion->value,
            'status' => 'resolved',
        ]);
        $this->assertFalse($effect->fresh()->hasBlockingAnomalies());
    }

    public function test_detects_era_mismatch(): void
    {
        $era = $this->era('维多利亚战争', 1100, 1100);

        $event = $this->rawEvent([
            'title' => '被错误归入该纪元的条目',
            'start_index' => TerraDate::toIndex(1050, 1, 1),
            'end_index' => TerraDate::toIndex(1050, 1, 1),
            'era_id' => $era->id,
        ]);

        $produced = $this->checker()->checkEvent($event);

        $this->assertContains(AnomalyType::EraMismatch->value, array_column($produced, 'type'));
        $this->assertDatabaseHas('timeline_anomalies', [
            'event_id' => $event->id,
            'type' => AnomalyType::EraMismatch->value,
            'severity' => 'warning',
        ]);
    }

    public function test_detects_duplicate_entries_with_similar_titles(): void
    {
        $this->rawEvent([
            'title' => '切尔诺伯格事变爆发',
            'start_index' => TerraDate::toIndex(1096, 12, 23),
            'end_index' => TerraDate::toIndex(1096, 12, 23),
        ]);

        $second = $this->rawEvent([
            'title' => '切尔诺伯格事变爆发',
            'start_index' => TerraDate::toIndex(1096, 12, 23),
            'end_index' => TerraDate::toIndex(1096, 12, 23),
        ]);

        $produced = $this->checker()->checkEvent($second);

        $this->assertContains(AnomalyType::DuplicateSuspect->value, array_column($produced, 'type'));
    }

    public function test_different_dates_do_not_trigger_duplicate_warning(): void
    {
        $this->rawEvent([
            'title' => '切尔诺伯格事变爆发',
            'start_index' => TerraDate::toIndex(1050, 1, 1),
            'end_index' => TerraDate::toIndex(1050, 1, 1),
        ]);

        // 标题相同但区间相差数十年 → 不是重复
        $second = $this->rawEvent([
            'title' => '切尔诺伯格事变爆发',
            'start_index' => TerraDate::toIndex(1099, 1, 1),
            'end_index' => TerraDate::toIndex(1099, 1, 1),
        ]);

        $produced = $this->checker()->checkEvent($second);

        $this->assertNotContains(AnomalyType::DuplicateSuspect->value, array_column($produced, 'type'));
    }

    public function test_detects_source_disagreement_from_quote_years(): void
    {
        $event = $this->rawEvent([
            'title' => '时间存疑的事件',
            'start_index' => TerraDate::toIndex(1097, 1, 1),
            'end_index' => TerraDate::toIndex(1097, 1, 1),
        ]);

        $source = $this->source('某设定集', 'disagreeing-source');
        $event->sources()->attach($source->id, [
            'quote' => '泰拉历1040年，该事件首次被记录。',
        ]);

        $produced = $this->checker()->checkEvent($event->fresh());

        $this->assertContains(AnomalyType::SourceDisagreement->value, array_column($produced, 'type'));
    }

    public function test_unanchored_entries_skip_causal_checks(): void
    {
        $cause = $this->rawEvent(['title' => '起因']);

        // 时间未定的条目无法参与因果判定，不应被误报
        $event = $this->rawEvent([
            'title' => '时间未定的条目',
            'date_display' => '泰拉历纪元前（年表未载）',
            'start_index' => TerraDate::UNKNOWN_INDEX,
            'end_index' => TerraDate::UNKNOWN_INDEX,
            'date_precision' => 'unknown',
            'caused_by_event_id' => $cause->id,
        ]);

        $produced = $this->checker()->checkEvent($event);

        $this->assertSame([], $produced);
    }

    public function test_relative_date_without_anchor_is_blocking(): void
    {
        $event = $this->rawEvent([
            'title' => '锚点未解析的条目',
            'date_display' => '切尔诺伯格事变后3年',
            'start_index' => TerraDate::UNKNOWN_INDEX,
            'end_index' => TerraDate::UNKNOWN_INDEX,
            'date_precision' => 'relative',
        ]);

        $produced = $this->checker()->checkEvent($event);

        $this->assertContains(AnomalyType::UnanchoredRelative->value, array_column($produced, 'type'));
    }

    public function test_writer_triggers_a_check_on_every_write(): void
    {
        $editor = $this->user(\App\Enums\UserRole::Editor);
        $era = $this->era('维多利亚战争', 1100, 1100);

        // 通过 EventWriter 写入一条时代错位的条目，巡检应被自动触发
        $event = app(EventWriter::class)->create([
            'title' => '时代错位的条目',
            'summary' => '用于验证写入后即时体检。',
            'date_display' => '泰拉历1050年',
            'era_id' => $era->id,
        ], $editor);

        $this->assertDatabaseHas('timeline_anomalies', [
            'event_id' => $event->id,
            'type' => AnomalyType::EraMismatch->value,
        ]);
    }

    public function test_full_scan_covers_every_event(): void
    {
        $this->rawEvent(['title' => 'A']);
        $this->rawEvent(['title' => 'B']);
        $this->rawEvent(['title' => 'C']);

        $result = $this->checker()->checkAll();

        $this->assertSame(3, $result['checked']);
        $this->assertIsInt($result['anomalies']);
    }

    public function test_anomaly_resolution_is_tracked(): void
    {
        $event = $this->rawEvent([
            'title' => '重复条目',
            'start_index' => TerraDate::toIndex(1096, 12, 23),
            'end_index' => TerraDate::toIndex(1096, 12, 23),
        ]);
        $this->rawEvent([
            'title' => '重复条目',
            'start_index' => TerraDate::toIndex(1096, 12, 23),
            'end_index' => TerraDate::toIndex(1096, 12, 23),
        ]);

        $this->checker()->checkEvent($event);

        $anomaly = TimelineAnomaly::where('event_id', $event->id)->firstOrFail();
        $reviewer = $this->user(\App\Enums\UserRole::Reviewer);

        $this->actingAs($reviewer)
            ->postJson(route('anomalies.resolve', $anomaly), ['status' => 'ignored', 'note' => '两条表述角度不同，保留'])
            ->assertOk();

        $anomaly->refresh();
        $this->assertSame('ignored', $anomaly->status);
        $this->assertSame($reviewer->id, $anomaly->resolved_by);
    }

    public function test_scan_requires_reviewer_role(): void
    {
        $editor = $this->user(UserRole::Editor, 'editor-scan@example.test');
        $viewer = $this->user(UserRole::Viewer, 'viewer-scan@example.test');

        $this->actingAs($editor)->postJson(route('anomalies.scan'))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('anomalies.scan'))->assertForbidden();
    }
}
