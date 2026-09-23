<?php

namespace Tests\Feature;

use App\Enums\AnomalyType;
use App\Enums\ChangeOrigin;
use App\Enums\DatePrecision;
use App\Enums\UserRole;
use App\Enums\World;
use App\Exceptions\WriteDeniedException;
use App\Models\Era;
use App\Services\EventWriter;
use App\Services\TimelineConsistencyChecker;
use App\Support\TerraDate;
use App\Support\TerraDateParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * 世界（泰拉 / 塔卫二）之间的隔离。
 *
 * 为什么这块必须单独测：排序键是一个**没有量纲的整数网格**
 * （`index = year * 372 + (month-1) * 31 + (day-1)`），它只在同一纪年体系内可比。
 * 跨世界比较不会抛异常、不会报错，只会安静地给出一个错误的顺序或归属 ——
 * 这种失败模式只能靠断言挡住。
 *
 * 最典型的一条：泰拉第一个纪元「远古 · 前纪元」覆盖 -186000 ~ 371627，
 * 而塔罗斯历 5 年的索引只有 1860，**落在里面**。
 */
class WorldIsolationTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    /** 一个区间大到能吞下塔罗斯历所有年份的泰拉纪元。 */
    private function terraPrehistory(): Era
    {
        return Era::create([
            'name' => '测试用泰拉远古纪元',
            'slug' => 'test-terra-prehistory',
            'world' => World::Terra->value,
            'date_label' => '泰拉历前 500 年 — 999 年',
            'start_index' => TerraDate::toIndex(-500),
            'end_index' => TerraDate::toIndex(999, 12, 31),
            'color' => '#808080',
        ]);
    }

    private function talosEra(): Era
    {
        return Era::create([
            'name' => '测试用塔卫二纪元',
            'slug' => 'test-talos-era',
            'world' => World::Talos->value,
            'date_label' => '塔罗斯历 1 年 — 15 年',
            'start_index' => TerraDate::toIndex(1),
            'end_index' => TerraDate::toIndex(15, 12, 31),
            'color' => '#3f6b7a',
        ]);
    }

    /* ------------------------------------------------------------ 查询隔离 */

    public function test_timeline_feed_never_mixes_worlds(): void
    {
        $this->rawEvent(['title' => '泰拉侧条目']);
        $this->rawEvent([
            'title' => '塔卫二侧条目',
            'world' => World::Talos->value,
            'start_index' => TerraDate::toIndex(5),
            'end_index' => TerraDate::toIndex(5),
        ]);

        // 缺省泰拉：不带 world 的请求绝不能看到另一个世界的条目
        $this->getJson(route('timeline.feed'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', '泰拉侧条目');

        $this->getJson(route('timeline.feed', ['world' => World::Talos->value]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', '塔卫二侧条目');
    }

    public function test_unknown_world_falls_back_to_the_default_instead_of_failing(): void
    {
        $this->rawEvent(['title' => '泰拉侧条目']);

        // 手改 URL 不该把页面打崩，而是回落到缺省世界
        $this->getJson(route('timeline.feed', ['world' => 'not-a-world']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', '泰拉侧条目');
    }

    public function test_world_switch_scopes_era_groups_and_dictionaries(): void
    {
        $terra = $this->terraPrehistory();
        $talos = $this->talosEra();

        $this->get(route('timeline.index'))
            ->assertOk()
            ->assertSee($terra->name)
            ->assertDontSee($talos->name);

        $this->get(route('timeline.index', ['world' => World::Talos->value]))
            ->assertOk()
            ->assertSee($talos->name)
            ->assertDontSee($terra->name);
    }

    public function test_filter_options_are_scoped_to_the_requested_world(): void
    {
        $this->terraPrehistory();
        $talos = $this->talosEra();

        $terraEras = collect($this->getJson(route('timeline.filter-options'))->json('eras'))->pluck('name');
        $talosEras = collect($this->getJson(route('timeline.filter-options', ['world' => World::Talos->value]))->json('eras'))->pluck('name');

        $this->assertNotContains($talos->name, $terraEras);
        $this->assertContains($talos->name, $talosEras);
        $this->assertNotContains('测试用泰拉远古纪元', $talosEras);

        // 时间轴刻度也要带上本世界的历法名，否则塔卫二会显示成「泰拉历」
        $this->assertSame(
            World::Talos->calendarLabel(),
            $this->getJson(route('timeline.filter-options', ['world' => World::Talos->value]))->json('scale.calendar'),
        );
    }

    /* ------------------------------------------------------------ 归属隔离 */

    /**
     * 纪元自动归属不得跨世界。
     *
     * 这是本维度存在的**首要原因**：塔罗斯历 5 年的索引 1860 落在泰拉
     * 「远古 · 前纪元」的区间内，少了世界条件就会被静默归入泰拉纪元。
     */
    public function test_era_auto_assignment_never_crosses_worlds(): void
    {
        $terraEra = $this->terraPrehistory();

        $event = $this->rawEvent([
            'title' => '塔罗斯历五年的条目',
            'world' => World::Talos->value,
            'start_index' => TerraDate::toIndex(5),
            'end_index' => TerraDate::toIndex(5),
            'date_display' => '塔罗斯历 5 年',
            'era_id' => null,
        ]);

        // 前置条件：这个索引确实落在泰拉纪元的区间里 —— 否则这个用例什么也没测到
        $this->assertTrue($terraEra->coversIndex($event->start_index));

        app(TimelineConsistencyChecker::class)->reindexEraAssignments();

        $this->assertNull($event->fresh()->era_id, '塔卫二的条目被归入了泰拉纪元');

        /*
         * 反方向同样成立，而且更能说明问题：
         * 塔罗斯历的纪元与泰拉的纪元**同时覆盖**索引 1860，
         * 泰拉条目必须归到泰拉纪元上，而不是排在前面的那个。
         */
        $talosEra = $this->talosEra();
        $terraEvent = $this->rawEvent([
            'title' => '索引落在两个纪元交叠处的泰拉条目',
            'start_index' => TerraDate::toIndex(5),
            'end_index' => TerraDate::toIndex(5),
            'era_id' => null,
        ]);

        $this->assertTrue($terraEra->coversIndex($terraEvent->start_index));
        $this->assertTrue($talosEra->coversIndex($terraEvent->start_index));

        app(TimelineConsistencyChecker::class)->reindexEraAssignments();

        $this->assertSame(
            $terraEra->id,
            $terraEvent->fresh()->era_id,
            '泰拉条目被归进了塔卫二的纪元',
        );
    }

    public function test_writer_rejects_an_era_belonging_to_another_world(): void
    {
        $editor = $this->user(UserRole::Editor, 'isolated-writer@example.test');
        $terraEra = $this->terraPrehistory();

        $this->expectException(WriteDeniedException::class);
        $this->expectExceptionMessage('两个世界的纪年数值不可比较');

        app(EventWriter::class)->create([
            'world' => World::Talos->value,
            'title' => '把泰拉纪元用在塔卫二条目上',
            'summary' => '这个写入必须被拒绝。',
            'date_display' => '塔罗斯历 5 年',
            'era_id' => $terraEra->id,
        ], $editor, ChangeOrigin::Human);
    }

    public function test_an_events_world_is_immutable(): void
    {
        $editor = $this->user(UserRole::Editor, 'world-swap@example.test');

        $event = app(EventWriter::class)->create([
            'title' => '原本属于泰拉的条目',
            'summary' => '换世界等于换一套纪年。',
            'date_display' => '泰拉历 1097 年',
        ], $editor, ChangeOrigin::Human);

        $this->assertSame(World::Terra, $event->world());

        $this->expectException(WriteDeniedException::class);
        $this->expectExceptionMessage('条目的世界不可更改');

        app(EventWriter::class)->update($event, [
            'world' => World::Talos->value,
            'title' => $event->title,
            'summary' => $event->summary,
            'date_display' => $event->date_display,
        ], $event->version, $editor);
    }

    public function test_entries_default_to_terra_when_the_world_is_unspecified(): void
    {
        $editor = $this->user(UserRole::Editor, 'default-world@example.test');

        $event = app(EventWriter::class)->create([
            'title' => '未指定世界的条目',
            'summary' => '历史数据与既有调用点都走这条路径。',
            'date_display' => '泰拉历 1097 年',
        ], $editor, ChangeOrigin::Human);

        // 属性访问拿到的是 cast 后的枚举（而不是被同名的 world() 方法遮住），
        // 这一点本身也值得钉住：遮住的话这里会拿到一个 World 对象却读不出值
        $this->assertSame(World::Terra, $event->fresh()->world);
        $this->assertSame('terra', $event->fresh()->world()->value);
    }

    /* ------------------------------------------------------------ 展示与解析 */

    public function test_date_hint_uses_the_worlds_own_calendar(): void
    {
        $event = $this->rawEvent([
            'title' => '塔罗斯历 152 年的事',
            'world' => World::Talos->value,
            'date_display' => '塔罗斯历 152 年',
            'start_index' => TerraDate::toIndex(152),
            'end_index' => TerraDate::toIndex(152, 12, 31),
        ]);

        $hint = $event->toApiArray()['date']['hint'];

        $this->assertStringContainsString('塔罗斯历', $hint);
        $this->assertStringNotContainsString('泰拉历', $hint);
    }

    /**
     * 短年份只在带历法名时才解析为绝对年份。
     *
     * 塔罗斯历的年份只有一两位数，`\d{4}` 必须放宽成 `\d{1,4}`；
     * 但那样一来「切尔诺伯格事变前3年」里的 3 也会被当成绝对年份 ——
     * 而 parseYear 恰好排在 parseRelative 之前。这三条断言把这个平衡钉住。
     */
    public function test_short_years_require_an_explicit_calendar_label(): void
    {
        $parser = app(TerraDateParser::class);

        $talos = $parser->parse('塔罗斯历 5 年');
        $this->assertSame(DatePrecision::Year, $talos->precision);
        $this->assertSame(TerraDate::toIndex(5), $talos->startIndex);

        $relative = $parser->parse('切尔诺伯格事变前3年');
        $this->assertSame(DatePrecision::Relative, $relative->precision, '相对时间被误判为绝对年份');
        $this->assertSame(-3, $relative->anchorOffsetYears);

        $beforeEra = $parser->parse('泰拉历前200年');
        $this->assertSame(-200, $beforeEra->year, '纪元前年份被误判为相对时间');
    }

    /**
     * 跨世界的「相似」不是重复。
     *
     * 巡检的重复检测会先按时间区间重叠筛候选，若不加世界条件，
     * 两个世界里索引相近的条目会被拉进同一个候选集 —— 判据本身就不成立。
     */
    public function test_duplicate_detection_does_not_cross_worlds(): void
    {
        $this->rawEvent([
            'title' => '四号谷地遭袭击',
            'start_index' => TerraDate::toIndex(75),
            'end_index' => TerraDate::toIndex(75),
        ]);

        $talos = $this->rawEvent([
            'title' => '四号谷地遭袭击',
            'world' => World::Talos->value,
            'start_index' => TerraDate::toIndex(75),
            'end_index' => TerraDate::toIndex(75),
        ]);

        app(TimelineConsistencyChecker::class)->checkEvent($talos);

        // 只断言「疑似重复」这一项：巡检还会报别的类型（缺出处等），
        // 用总数断言会让这个用例随着巡检规则的增减而误报
        $this->assertSame(
            0,
            $talos->anomalies()->where('type', AnomalyType::DuplicateSuspect->value)->count(),
            '跨世界的同名条目被判成了疑似重复',
        );
    }
}
