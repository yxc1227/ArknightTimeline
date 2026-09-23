<?php

namespace Tests\Feature;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Enums\EventStatus;
use App\Enums\World;
use App\Models\Character;
use App\Models\Era;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Source;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\TerraDateParser;
use Database\Seeders\TimelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 种子数据自身的完整性。
 *
 * 起始语料是这个项目最容易「悄悄烂掉」的部分：手写的数据里一个错字就会
 * 让条目排到错误的位置，而页面不会报错、只会安静地显示错的东西。
 * 因此这里把数据当成代码来测。
 */
class SeederIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TimelineSeeder::class);
    }

    /**
     * 最重要的一条不变量：每条的 date_display 重新解析后必须与落库的索引一致。
     *
     * 这能抓住「改了原文但忘了改索引」「复制粘贴时年份写错」这类静默错误。
     */
    public function test_every_event_date_display_agrees_with_its_stored_index(): void
    {
        $parser = new TerraDateParser;

        foreach (Event::all() as $event) {
            $parsed = $parser->parse($event->date_display, $event->date_confidence);

            $this->assertSame(
                $parsed->startIndex,
                $event->start_index,
                "条目「{$event->title}」的时间原文与 start_index 不一致（原文：{$event->date_display}）",
            );

            $this->assertSame(
                $parsed->precision,
                $event->date_precision,
                "条目「{$event->title}」的时间精度与原文不一致",
            );

            $this->assertLessThanOrEqual(
                $event->end_index,
                $event->start_index,
                "条目「{$event->title}」的区间方向反了",
            );
        }
    }

    public function test_every_event_has_a_summary_and_a_title(): void
    {
        foreach (Event::all() as $event) {
            $this->assertNotSame('', trim($event->title), '标题不能为空');
            $this->assertNotSame('', trim($event->summary), "条目「{$event->title}」缺少简要描述");
        }
    }

    public function test_undated_events_are_confined_to_the_unanchored_lane(): void
    {
        $undated = Event::where('date_precision', DatePrecision::Unknown->value)->get();

        $this->assertGreaterThan(0, $undated->count());

        foreach ($undated as $event) {
            // 索引必须是哨兵值，否则它会以「0 年」的身份混进有序时间序列
            $this->assertSame(0, $event->start_index, "条目「{$event->title}」精度为 unknown 却有非零索引");
            $this->assertSame(0, $event->end_index);

            // 真正要守住的不变量是「定位不到时间就不可能被标为已确证」。
            // 可信度本身可以是 inferred（推断）甚至 disputed（存疑）——
            // 「时间未知」与「对这条时间有争议」是两件事，后者同样以未定位的形式存在。
            $this->assertNotSame(
                'confirmed',
                $event->date_confidence->value,
                "条目「{$event->title}」定位不到时间却被标为已确证",
            );
        }
    }

    // ---------------------------------------------------------------- 《大地巡礼》

    public function test_terra_tour_source_is_registered(): void
    {
        $source = Source::where('slug', 'terra-tour')->firstOrFail();

        $this->assertSame('《大地巡礼》', $source->name);
        $this->assertSame('artbook', $source->type->value);
        $this->assertSame('官方世界观设定集', $source->code);

        // 这本书的原文尚未录入，因此 raw_text 应留空 —— 而不是塞入编造的「原文」
        $this->assertTrue(blank($source->raw_text), '《大地巡礼》不应预置 raw_text');
    }

    public function test_terra_tour_events_are_all_marked_undated_and_unreviewed(): void
    {
        $events = Event::whereHas('sources', fn ($q) => $q->where('sources.slug', 'terra-tour'))->get();

        $this->assertGreaterThanOrEqual(17, $events->count());

        foreach ($events as $event) {
            $this->assertSame(
                DatePrecision::Unknown,
                $event->date_precision,
                "《大地巡礼》条目「{$event->title}」不应声明具体时间",
            );
            $this->assertSame('unknown', $event->date_confidence->value);
            $this->assertSame('needs_review', $event->status->value, '未定位年份的条目必须留在待校验状态');
        }
    }

    /**
     * 这条是刻意的「反向」断言：出处没有引文是**可接受的待办状态**，
     * 但绝不允许出现凭印象写下的引文。若将来补录了原文与引文，
     * 应改为断言引文能在 source.raw_text 中定位 —— 而不是删掉这个测试。
     */
    public function test_terra_tour_citations_are_left_empty_rather_than_invented(): void
    {
        $rows = DB::table('event_source')
            ->join('sources', 'sources.id', '=', 'event_source.source_id')
            ->where('sources.slug', 'terra-tour')
            ->get(['event_source.quote', 'event_source.chapter', 'event_source.quote_offset']);

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertNull($row->quote, '不得为尚未录入原文的出处编造引文');
            $this->assertNull($row->quote_offset);
            // 章节仍应标注（粗粒度），让审核人知道该去哪一卷核对
            $this->assertContains($row->chapter, ['世界卷', '国家与地区卷']);
        }
    }

    /**
     * 引文缺失必须在界面上显式呈现，不能留白 ——
     * 留白会让人误读为「已经核对过、确实没有可引的原文」。
     */
    public function test_source_page_surfaces_the_missing_citation_state(): void
    {
        $source = Source::where('slug', 'terra-tour')->firstOrFail();

        $this->get(route('sources.show', $source))
            ->assertOk()
            ->assertSee('国家与地区卷')
            ->assertSee('尚未附引文')
            ->assertSee('莱塔尼亚的双王共治')
            ->assertSee('雷姆必拓的矿业体系');
    }

    public function test_terra_tour_covers_the_world_and_nation_sections(): void
    {
        $sections = DB::table('event_source')
            ->join('sources', 'sources.id', '=', 'event_source.source_id')
            ->where('sources.slug', 'terra-tour')
            ->distinct()
            ->pluck('event_source.chapter')
            ->all();

        $this->assertContains('世界卷', $sections);
        $this->assertContains('国家与地区卷', $sections);
    }

    public function test_nation_factions_are_available_as_a_filter_dimension(): void
    {
        foreach (['莱塔尼亚', '哥伦比亚', '玻利瓦尔', '雷姆必拓', '米诺斯', '萨米', '阿戈尔'] as $name) {
            $this->assertDatabaseHas('factions', ['name' => $name]);
        }
    }

    public function test_rhine_lab_is_nested_under_columbia(): void
    {
        $columbia = Faction::where('name', '哥伦比亚')->firstOrFail();
        $rhine = Faction::where('name', '莱茵生命')->firstOrFail();

        $this->assertSame($columbia->id, $rhine->parent_id);
        // 层级筛选会向下包含：按哥伦比亚检索应能带出莱茵生命相关的条目
        $this->assertContains($rhine->id, $columbia->selfAndDescendantIds());
    }

    public function test_filtering_by_columbia_includes_its_descendant_faction_events(): void
    {
        $columbia = Faction::where('name', '哥伦比亚')->firstOrFail();

        $titles = Event::query()->filter(['faction_id' => $columbia->id])->pluck('title');

        // 「哥伦比亚脱离维多利亚」同时挂了母政体与莱茵生命
        $this->assertContains('哥伦比亚脱离维多利亚', $titles);
    }

    public function test_terra_tour_entries_expose_nation_and_mechanism_topics(): void
    {
        $titles = Event::whereHas('sources', fn ($q) => $q->where('sources.slug', 'terra-tour'))
            ->pluck('title')
            ->all();

        // 世界卷机制
        $this->assertContains('移动城市技术的形成', $titles);
        $this->assertContains('源石与天灾的共生关系', $titles);
        // 国家与地区卷政体
        $this->assertContains('莱塔尼亚的双王共治', $titles);
        $this->assertContains('雷姆必拓的矿业体系', $titles);
    }

    // ---------------------------------------------------------------- 其它数据维度

    /**
     * 纪元区间在**各自的世界内**不重叠。
     *
     * 必须逐世界判定：纪元区间是纪年网格上的数值区间，只在同一纪年体系内可比。
     * 塔罗斯历 1-15 年的索引（372 ~ 5951）就落在泰拉「远古 · 前纪元」
     * （-186000 ~ 371627）的范围内 —— 跨世界比较不仅会误报，而且会把
     * 纪元自动归属引向错误的结论。
     */
    public function test_eras_do_not_overlap_within_each_world(): void
    {
        foreach (World::cases() as $world) {
            $eras = Era::ofWorld($world)->ordered()->get();

            for ($i = 1; $i < $eras->count(); $i++) {
                $this->assertLessThan(
                    $eras[$i]->start_index,
                    $eras[$i - 1]->end_index,
                    "{$world->label()}：纪元「{$eras[$i - 1]->name}」与「{$eras[$i]->name}」的区间重叠",
                );
            }
        }

        // 反空过：两个世界都必须真的有纪元，否则上面那个循环会「什么也没检查」地通过
        $this->assertGreaterThan(0, Era::ofWorld(World::Terra)->count());
        $this->assertGreaterThan(0, Era::ofWorld(World::Talos)->count());
    }

    /**
     * 塔卫二的条目必须落在塔卫二的纪元里。
     *
     * 这是世界维度存在的**主要原因**：泰拉的「远古 · 前纪元」覆盖 -186000 ~ 371627，
     * 而塔罗斯历 5 年的索引只有 1860 —— 少了世界条件，它会被静默归入泰拉纪元。
     */
    public function test_talos_events_are_never_assigned_to_terra_eras(): void
    {
        $misplaced = Event::query()
            ->where('world', World::Talos->value)
            ->whereNotNull('era_id')
            ->whereHas('era', fn ($q) => $q->where('world', '!=', World::Talos->value))
            ->count();

        $this->assertSame(0, $misplaced, '有塔卫二条目被归入了泰拉纪元');

        // 反空过：塔卫二的条目确实存在，且确实拿到了纪元
        $this->assertSame(10, Event::where('world', World::Talos->value)->count());
        $this->assertSame(
            10,
            Event::where('world', World::Talos->value)->whereNotNull('era_id')->count(),
        );
    }

    /**
     * 塔卫二的条目是**社区考据**，不是官方原文。
     *
     * 因此它们必须全部落在「推断 / 存疑」档并且没有引文 ——
     * 一旦有人把社区整理当成官方出处填了引文，「引用可定位」这条校验就失去意义了。
     */
    public function test_talos_entries_are_marked_as_inferred_and_carry_no_quotes(): void
    {
        $events = Event::where('world', World::Talos->value)->with('sources')->get();

        foreach ($events as $event) {
            $this->assertContains(
                $event->date_confidence,
                [DateConfidence::Inferred, DateConfidence::Disputed],
                "塔卫二条目「{$event->title}」的可信度不应高于「推断」",
            );

            $this->assertSame(EventStatus::NeedsReview, $event->status, "塔卫二条目「{$event->title}」应处于待复核");

            foreach ($event->sources as $source) {
                $this->assertNull($source->pivot->quote, "塔卫二条目「{$event->title}」不应附引文（来源是社区整理）");
            }
        }
    }

    public function test_characters_reference_existing_factions(): void
    {
        foreach (Character::with('faction')->get() as $character) {
            if ($character->faction_id === null) {
                continue;
            }

            $this->assertNotNull($character->faction, "人物「{$character->name}」指向了不存在的阵营");
        }
    }

    public function test_source_pivot_rows_reference_existing_events_and_sources(): void
    {
        $orphans = DB::table('event_source')
            ->leftJoin('events', 'events.id', '=', 'event_source.event_id')
            ->leftJoin('sources', 'sources.id', '=', 'event_source.source_id')
            ->whereNull('events.id')
            ->orWhereNull('sources.id')
            ->count();

        $this->assertSame(0, $orphans, 'event_source 存在悬空引用');
    }

    public function test_control_point_events_are_marked_confirmed(): void
    {
        // 切尔诺伯格事变是整个主线的时间原点，是唯一被当作「已确证」的锚点之一
        $event = Event::where('title', '切尔诺伯格事变爆发')->firstOrFail();

        $this->assertSame('confirmed', $event->date_confidence->value);
        $this->assertSame('泰拉历1096年12月23日', $event->date_display);
        $this->assertSame('verified', $event->status->value);
    }

    /**
     * 演示账号必须体现「昵称与登录名分离」这条设计。
     *
     * 种子数据是最容易被当作规范的东西：如果这里用中文当登录名，
     * 后来的人就会以为登录名可以是中文，而那条规则其实写在
     * User::HANDLE_PATTERN 里（登录名是凭据，必须 ASCII）。
     */
    public function test_seeded_accounts_separate_ascii_handle_from_nickname(): void
    {
        $accounts = User::all();

        $this->assertCount(5, $accounts);

        foreach ($accounts as $user) {
            $this->assertTrue(
                User::isValidHandle($user->name),
                "演示账号 {$user->email} 的登录名「{$user->name}」不符合格式要求",
            );
            $this->assertNotSame($user->name, $user->nickname, '演示数据应当让登录名与昵称不同，以体现两者分离');
            $this->assertNotSame('', trim((string) $user->nickname));
        }

        // 登录名与昵称各自全服唯一
        $this->assertSame($accounts->count(), $accounts->pluck('name')->unique()->count());
        $this->assertSame($accounts->count(), $accounts->pluck('nickname')->unique()->count());

        // 停用示例账号确实处于禁用状态（否则状态筛选与徽章没有可演示的数据）
        $disabled = User::where('email', 'disabled@terra.local')->firstOrFail();
        $this->assertFalse($disabled->isActive());
    }

    /**
     * 外部身份绑定也要有真实样本：一条已核验、一条待核验 ——
     * 「待核验」是唯一需要管理员介入的状态，没有样本就等于没有演示。
     */
    public function test_seeded_identities_cover_both_verification_states(): void
    {
        $identities = UserIdentity::all();

        $this->assertCount(2, $identities);
        $this->assertCount(1, $identities->filter(fn (UserIdentity $i) => $i->isVerified()));
        $this->assertCount(1, $identities->filter(fn (UserIdentity $i) => ! $i->isVerified()));

        // 同一渠道不能在同一个人身上绑两条（唯一索引的语义在应用层的体现）
        $this->assertSame(
            $identities->count(),
            $identities->map(fn (UserIdentity $i) => $i->user_id.'|'.$i->provider()->value)->unique()->count(),
        );
    }
}
