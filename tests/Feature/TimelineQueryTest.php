<?php

namespace Tests\Feature;

use App\Models\Era;
use App\Models\Tag;
use App\Support\TerraDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 检索与筛选。
 *
 * 最容易被忽略、也最容易出错的是**时间维度**：泰拉历的粒度不统一，
 * 所以查询必须按「区间重叠」而不是「区间包含」来做，
 * 否则「1097 年冬」在查「1098 年 1 月」时会被漏掉。
 */
class TimelineQueryTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    private function search(array $filters = []): array
    {
        return \App\Models\Event::query()->filter($filters)->timelineOrder()->pluck('title')->all();
    }

    public function test_time_range_uses_overlap_not_containment(): void
    {
        // 冬季跨年：1097年12月 → 1098年2月
        [$winterStart, $winterEnd] = TerraDate::seasonBounds(1097, '冬');

        $this->rawEvent([
            'title' => '风雪过境事件',
            'start_index' => $winterStart,
            'end_index' => $winterEnd,
            'date_precision' => 'season',
        ]);

        $january = TerraDate::toIndex(1098, 1, 15);

        // 查询 1098 年 1 月这一天，跨年的冬季条目必须被命中
        $this->assertContains('风雪过境事件', $this->search(['from_index' => $january, 'to_index' => $january]));

        // 但查询 1096 年时不应命中
        [$from, $to] = TerraDate::yearBounds(1096);
        $this->assertNotContains('风雪过境事件', $this->search(['from_index' => $from, 'to_index' => $to]));
    }

    public function test_unanchored_filter_isolates_unlocated_entries(): void
    {
        $this->rawEvent(['title' => '可定位条目', 'start_index' => TerraDate::toIndex(1097, 1, 1), 'end_index' => TerraDate::toIndex(1097, 1, 1)]);
        $this->rawEvent([
            'title' => '时间未定条目',
            'date_display' => '泰拉历纪元前（年表未载）',
            'start_index' => TerraDate::UNKNOWN_INDEX,
            'end_index' => TerraDate::UNKNOWN_INDEX,
            'date_precision' => 'unknown',
        ]);

        $this->assertSame(['时间未定条目'], $this->search(['only_unanchored' => true]));
    }

    public function test_unknown_entries_sort_last_not_as_year_zero(): void
    {
        $this->rawEvent([
            'title' => '时间未定条目',
            'start_index' => TerraDate::UNKNOWN_INDEX,
            'end_index' => TerraDate::UNKNOWN_INDEX,
            'date_precision' => 'unknown',
        ]);
        $this->rawEvent(['title' => '纪元前条目', 'start_index' => TerraDate::toIndex(-200), 'end_index' => TerraDate::toIndex(-200), 'date_precision' => 'year']);
        $this->rawEvent(['title' => '1097年条目', 'start_index' => TerraDate::toIndex(1097, 1, 1), 'end_index' => TerraDate::toIndex(1097, 1, 1)]);

        $titles = $this->search();

        // 未定位条目排在最后，而不是插在「纪元前」与 1097 年之间
        $this->assertSame(['纪元前条目', '1097年条目', '时间未定条目'], $titles);
    }

    public function test_era_filter(): void
    {
        $era = $this->era('龙门危机', 1097, 1097);

        $this->rawEvent(['title' => '龙门危机事件', 'era_id' => $era->id]);
        $this->rawEvent(['title' => '其他事件', 'start_index' => TerraDate::toIndex(1100, 1, 1), 'end_index' => TerraDate::toIndex(1100, 1, 1)]);

        $this->assertSame(['龙门危机事件'], $this->search(['era_id' => $era->id]));
    }

    public function test_source_and_source_type_filters(): void
    {
        $mainStory = \App\Models\Source::create(['name' => '主线 · 第七章', 'slug' => 'ms-7', 'type' => 'main_story']);
        $event = \App\Models\Source::create(['name' => '活动 · 孤星', 'slug' => 'ev-lone', 'type' => 'event']);

        $a = $this->rawEvent(['title' => '主线条目']);
        $a->sources()->attach($mainStory->id);

        $b = $this->rawEvent(['title' => '活动条目', 'start_index' => TerraDate::toIndex(1099, 1, 1), 'end_index' => TerraDate::toIndex(1099, 1, 1)]);
        $b->sources()->attach($event->id);

        $this->assertSame(['主线条目'], $this->search(['source_id' => $mainStory->id]));
        $this->assertSame(['活动条目'], $this->search(['source_type' => 'event']));
        $this->assertCount(2, $this->search());
    }

    public function test_faction_filter_includes_descendant_factions(): void
    {
        $parent = \App\Models\Faction::create(['name' => '罗德岛', 'slug' => 'rhodes']);
        $child = \App\Models\Faction::create(['name' => '精英干员', 'slug' => 'elite', 'parent_id' => $parent->id]);
        $other = \App\Models\Faction::create(['name' => '整合运动', 'slug' => 'reunion']);

        $a = $this->rawEvent(['title' => '精英干员参与的条目']);
        $a->factions()->attach($child->id);

        $b = $this->rawEvent(['title' => '整合运动条目', 'start_index' => TerraDate::toIndex(1096, 1, 1), 'end_index' => TerraDate::toIndex(1096, 1, 1)]);
        $b->factions()->attach($other->id);

        // 按母阵营筛选时，子阵营的条目也要命中
        $this->assertContains('精英干员参与的条目', $this->search(['faction_id' => $parent->id]));
        $this->assertNotContains('整合运动条目', $this->search(['faction_id' => $parent->id]));
    }

    public function test_tag_filter_matches_any_of_the_selected_tags(): void
    {
        $battle = Tag::create(['name' => '战役', 'slug' => 'battle']);
        $disaster = Tag::create(['name' => '灾害', 'slug' => 'disaster']);

        $a = $this->rawEvent(['title' => '战役条目']);
        $a->tags()->attach($battle->id);

        $b = $this->rawEvent(['title' => '灾害条目', 'start_index' => TerraDate::toIndex(1096, 1, 1), 'end_index' => TerraDate::toIndex(1096, 1, 1)]);
        $b->tags()->attach($disaster->id);

        $this->assertSame(['战役条目'], $this->search(['tag_ids' => [$battle->id]]));
        $this->assertCount(2, $this->search(['tag_ids' => [$battle->id, $disaster->id]]));
    }

    public function test_keyword_search_covers_title_summary_and_date_text(): void
    {
        $this->rawEvent(['title' => '切尔诺伯格事变爆发', 'date_display' => '泰拉历1096年12月23日']);
        $this->rawEvent(['title' => '沃伦姆德事件', 'summary' => '感染者与市民的对立失控。', 'start_index' => TerraDate::toIndex(1099, 12, 1), 'end_index' => TerraDate::toIndex(1099, 12, 1)]);

        $this->assertSame(['切尔诺伯格事变爆发'], $this->search(['q' => '切尔诺伯格']));
        $this->assertSame(['沃伦姆德事件'], $this->search(['q' => '感染者与市民']));
        // 纪年原文也参与检索
        $this->assertSame(['切尔诺伯格事变爆发'], $this->search(['q' => '1096年12月23日']));
    }

    public function test_status_and_confidence_filters(): void
    {
        $this->rawEvent(['title' => '已校验条目', 'status' => 'verified', 'date_confidence' => 'confirmed']);
        $this->rawEvent(['title' => '待校验条目', 'status' => 'needs_review', 'date_confidence' => 'inferred', 'start_index' => TerraDate::toIndex(1096, 1, 1), 'end_index' => TerraDate::toIndex(1096, 1, 1)]);

        $this->assertSame(['已校验条目'], $this->search(['status' => 'verified']));
        $this->assertSame(['待校验条目'], $this->search(['confidence' => 'inferred']));
    }

    public function test_only_with_anomalies_filter(): void
    {
        $clean = $this->rawEvent(['title' => '正常条目']);
        $era = $this->era('未来纪', 1200, 1210);
        $broken = $this->rawEvent(['title' => '时代错位条目', 'era_id' => $era->id]);

        app(\App\Services\TimelineConsistencyChecker::class)->checkAll();

        $this->assertSame(['时代错位条目'], $this->search(['only_with_anomalies' => true]));
    }

    public function test_feed_endpoint_returns_paginated_envelope(): void
    {
         for ($i = 0; $i < 5; $i++) {
             $this->rawEvent([
                 'title' => "条目 {$i}",
                 'start_index' => TerraDate::toIndex(1097, 1, $i + 1),
                 'end_index' => TerraDate::toIndex(1097, 1, $i + 1),
             ]);
         }

        $response = $this->getJson(route('timeline.feed', ['per_page' => 2]));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.page', 1);

        // 缩放宽表随首屏一起下发，避免前端再发一次统计请求
        $this->assertArrayHasKey('1097', $response->json('scale.buckets'));
        $this->assertSame(TerraDate::DAYS_PER_YEAR, $response->json('scale.days_per_year'));
    }

    public function test_feed_endpoint_is_publicly_readable(): void
    {
        $this->getJson(route('timeline.feed'))->assertOk();
    }

    public function test_filters_can_be_combined(): void
    {
        $era = $this->era('维多利亚战争', 1100, 1100);
        $source = \App\Models\Source::create(['name' => '主线 · 第十章', 'slug' => 'ms-10', 'type' => 'main_story']);

        $match = $this->rawEvent([
            'title' => '伦蒂尼姆攻防战开始',
            'era_id' => $era->id,
            'start_index' => TerraDate::toIndex(1100, 5, 1),
            'end_index' => TerraDate::toIndex(1100, 5, 1),
        ]);
        $match->sources()->attach($source->id);

        // 落在同一纪元但出处不同 → 不该命中
        $this->rawEvent([
            'title' => '同纪元的其它条目',
            'era_id' => $era->id,
            'start_index' => TerraDate::toIndex(1100, 6, 1),
            'end_index' => TerraDate::toIndex(1100, 6, 1),
        ]);

        $this->assertSame(['伦蒂尼姆攻防战开始'], $this->search([
            'era_id' => $era->id,
            'source_id' => $source->id,
            'q' => '伦蒂尼姆',
        ]));
    }
}
