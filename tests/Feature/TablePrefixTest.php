<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Event;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 表前缀（DB_PREFIX）。
 *
 * 单开一个类是因为这里需要 RefreshDatabase —— 而 MigrationCommentTest 里的
 * information_schema 检查必须保持只读（否则有人在 .env 指向真实 MySQL 时跑测试会清库）。
 *
 * 前缀最容易出的问题不是「没生效」，而是**半生效**：
 * 加了前缀之后，凡是硬编码物理表名的地方都会失效，而
 * `wrapTable()` 只覆盖 from / join / Schema 操作，以及点号引用 `sources.id` 的首段。
 * 因此这里既要确认前缀生效，也要确认依赖点号引用的关联查询仍然可用。
 */
class TablePrefixTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    /** 逻辑表名 → 物理表名必须带上前缀。 */
    private const LOGICAL_TABLES = ['events', 'eras', 'factions', 'sources', 'event_source', 'ai_proposals'];

    public function test_connection_has_a_table_prefix_configured(): void
    {
        // 默认值来自 config/database.php；.env 里 DB_PREFIX= 留空即关闭
        $this->assertSame('arknight_', DB::connection()->getTablePrefix());
    }

    public function test_logical_table_names_are_physically_prefixed(): void
    {
        $prefix = DB::connection()->getTablePrefix();
        $tables = collect(Schema::getTables())->pluck('name')->all();

        foreach (self::LOGICAL_TABLES as $logical) {
            $this->assertContains(
                $prefix.$logical,
                $tables,
                "物理表 {$prefix}{$logical} 不存在（前缀可能未生效）",
            );

            // 物理层面不应残留无前缀的同名表。
            // 这条会真的报出来：Schema::getTables() 不做前缀过滤，而 migrate:fresh
            // 只按逻辑表名加前缀去删 —— 加了前缀之前建的表不会被自动清理，
            // 需要手工 DROP，否则库里会躺着两份同名 schema。
            $this->assertNotContains(
                $logical,
                $tables,
                "存在无前缀的残留表 {$logical}（改为带前缀前建的表需要手工删除）",
            );
        }
    }

    /**
     * 关联子查询里的点号引用（sources.id / tags.id / factions.id / characters.id）
     * 依赖 Grammar 对「点号引用首段」自动加前缀。这条一旦失效，
     * 多维筛选会整体报 Unknown column，因此必须锁住。
     */
    public function test_qualified_column_references_survive_the_prefix(): void
    {
        $source = $this->source('主线 · 第七章', 'ms-7');
        $faction = $this->faction('罗德岛');
        $tag = Tag::create(['name' => '战役', 'slug' => 'battle']);
        $character = Character::create(['name' => '阿米娅', 'slug' => 'amiya']);

        $event = $this->rawEvent(['title' => '带前缀的关联条目']);
        $event->sources()->attach($source->id);
        $event->factions()->attach($faction->id);
        $event->tags()->attach($tag->id);
        $event->characters()->attach($character->id);

        $this->assertSame(['带前缀的关联条目'], $this->filtered(['source_id' => $source->id]));
        $this->assertSame(['带前缀的关联条目'], $this->filtered(['source_type' => 'event']));
        $this->assertSame(['带前缀的关联条目'], $this->filtered(['faction_id' => $faction->id]));
        $this->assertSame(['带前缀的关联条目'], $this->filtered(['tag_ids' => [$tag->id]]));
        $this->assertSame(['带前缀的关联条目'], $this->filtered(['character_id' => $character->id]));
    }

    public function test_relation_pluck_of_qualified_key_works_with_prefix(): void
    {
        $source = $this->source('出处', 'src');
        $event = $this->rawEvent();
        $event->sources()->attach($source->id);

        // EventWriter::assertCanWrite 与 User::ownedSourceIds 都依赖这类 pluck
        $this->assertSame([$source->id], $event->sources()->pluck('sources.id')->all());
    }

    public function test_aggregate_and_ordering_scopes_work_with_prefix(): void
    {
        $this->rawEvent([
            'title' => '未定位条目',
            'date_display' => '泰拉历未载具体年份',
            'start_index' => 0,
            'end_index' => 0,
            'date_precision' => 'unknown',
        ]);
        $this->rawEvent(['title' => '可定位条目']);

        // 时间线排序使用 orderByRaw，聚合使用 selectRaw + groupBy 别名
        $this->assertSame(['可定位条目', '未定位条目'], Event::query()->timelineOrder()->pluck('title')->all());
        $this->assertSame(1, Event::query()->filter(['only_unanchored' => true])->count());
    }

    public function test_feed_endpoint_returns_scale_buckets_with_prefix(): void
    {
        // scaleBuckets 走 selectRaw + groupBy('year_bucket') 别名，最容易被前缀影响
        $this->rawEvent(['title' => '1097 年条目', 'start_index' => 1097 * 372, 'end_index' => 1097 * 372 + 371]);

        $this->getJson(route('timeline.feed'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['scale' => ['buckets', 'days_per_year']]);

        $this->assertArrayHasKey('1097', $this->getJson(route('timeline.feed'))->json('scale.buckets'));
    }

    private function filtered(array $filters): array
    {
        return Event::query()->filter($filters)->pluck('title')->all();
    }
}
