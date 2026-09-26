<?php

namespace Tests\Feature;

use App\Enums\DateConfidence;
use App\Enums\DatePrecision;
use App\Enums\CharacterKind;
use App\Enums\EventStatus;
use App\Enums\FactionKind;
use App\Enums\World;
use App\Models\Character;
use App\Models\Era;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Source;
use App\Models\Place;
use App\Models\Race;
use App\Models\Term;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\CharacterAvatars;
use App\Support\CharacterSplashes;
use App\Support\CorpusLocator;
use App\Support\EntityEmblems;
use App\Support\TerraDate;
use App\Support\TerraDateParser;
use App\Support\TerraTourCorpus;
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

    // ---------------------------------------------------------------- 《大地巡旅》

    public function test_terra_tour_source_is_registered(): void
    {
        $source = Source::where('slug', 'terra-tour')->firstOrFail();

        $this->assertSame('《大地巡旅》', $source->name);
        $this->assertSame('artbook', $source->type->value);
        $this->assertSame('官方世界观设定集', $source->code);

        // 原文文件刻意不入库（版权与体积：raw_text 是 MySQL TEXT，整书 1.16 MB 会被静默截断）。
        // 因此语料是不是有值，取决于本地有没有那份文件 —— 两种状态都必须成立，
        // 唯一不能出现的是「文件不在、语料却有内容」：那只能是编造的原文。
        if (! TerraTourCorpus::exists()) {
            $this->assertTrue(blank($source->raw_text), '原文缺失时不应凭空造出语料');

            return;
        }

        // 有文件时必须只收录**被引用的那一卷**（附录「泰拉纪年」），而不是整本书
        $excerpt = TerraTourCorpus::excerpt();
        $this->assertNotNull($excerpt);
        $this->assertSame($excerpt['text'], $source->raw_text);
        $this->assertStringContainsString('泰拉纪年', (string) $source->raw_text);
        $this->assertLessThan(
            65535,
            strlen((string) $source->raw_text),
            'raw_text 必须放得进 MySQL TEXT（64 KB），否则会被静默截断、引文偏移随之全部错位',
        );
    }

    /**
     * 散文卷（世界卷 / 国家与地区卷）的主体是机制与政体描述，年份普遍未载 ——
     * 这批条目必须停留在「时间未定」泳道。
     */
    public function test_terra_tour_prose_entries_are_marked_undated_and_unreviewed(): void
    {
        // chapter 是 event_source 的 pivot 字段，不能走 whereHas —— 直接查中间表取事件 id
        $proseEventIds = DB::table('event_source')
            ->join('sources', 'sources.id', '=', 'event_source.source_id')
            ->where('sources.slug', 'terra-tour')
            ->whereIn('event_source.chapter', ['世界卷', '国家与地区卷', '组织卷'])
            ->pluck('event_source.event_id');

        $events = Event::whereIn('id', $proseEventIds)->get();

        $this->assertGreaterThanOrEqual(25, $events->count());

        foreach ($events as $event) {
            $this->assertSame(
                DatePrecision::Unknown,
                $event->date_precision,
                "《大地巡旅》散文卷条目「{$event->title}」不应声明具体时间",
            );
            $this->assertSame('unknown', $event->date_confidence->value);
            $this->assertSame('needs_review', $event->status->value, '未定位年份的条目必须留在待校验状态');
        }
    }

    /**
     * 例外：书末附录「泰拉纪年」是全书唯一成体系的带年份材料。
     * 这批条目必须**带年份、带逐字引文**，且可信度落在已确证档 ——
     * 反过来讲，谁要是给散文卷条目编了年份或引文，上一条测试会拦下来。
     */
    public function test_terra_tour_chronicle_entries_are_dated_and_quoted(): void
    {
        $rows = DB::table('event_source')
            ->join('sources', 'sources.id', '=', 'event_source.source_id')
            ->join('events', 'events.id', '=', 'event_source.event_id')
            ->where('sources.slug', 'terra-tour')
            ->where('event_source.chapter', '泰拉纪年')
            ->get(['events.title', 'events.date_display', 'events.date_confidence', 'events.date_precision',
                'events.status', 'event_source.quote', 'event_source.quote_offset', 'event_source.source_line']);

        $this->assertGreaterThan(20, $rows->count(), '《大地巡旅》年表条目数量异常');

        foreach ($rows as $row) {
            $this->assertNotSame(DatePrecision::Unknown->value, $row->date_precision, "年表条目「{$row->title}」必须可定位时间");
            $this->assertSame('confirmed', $row->date_confidence, "年表条目「{$row->title}」应标为已确证");
            $this->assertSame('verified', $row->status, "年表条目「{$row->title}」应处于已确证状态");
            $this->assertNotNull($row->quote, "年表条目「{$row->title}」必须附年表原文引文");

            // 引文必须已经落到语料里的具体位置：偏移与行号是「引用可定位」的凭据，
            // 一条没有定位的引文与一段普通文字没有区别。
            $this->assertNotNull($row->quote_offset, "年表条目「{$row->title}」的引文尚未定位到语料");
            $this->assertGreaterThan(0, $row->source_line, "年表条目「{$row->title}」缺少引文所在行号");
        }
    }

    /**
     * L3 硬闸门：每条引文都必须能在**自己的出处语料**中逐字定位。
     *
     * README 一直把「出处可定位」列为硬闸门，但在此之前它只是一句声明：
     * 没有任何测试真的去核对过。这条闸门一上线就抓出 7 条「长得像引文、实际改写过」
     * 的记录 —— 6 条把原文的「，」写成了「。」，1 条撞上示例语料里的城市名错字
     * （伦蒂尼**恩**），另有一处把年表续行补上「969」前缀充作引文。
     * 它们现已逐条按原文修正，这也是「容忍空白标点、不容忍改写」这句口径的由来。
     */
    public function test_every_quote_is_locatable_in_its_source_corpus(): void
    {
        $violations = [];
        $mismatched = [];
        $checked = 0;

        foreach (Source::whereNotNull('raw_text')->with('events')->get() as $source) {
            $locator = CorpusLocator::forText((string) $source->raw_text);

            foreach ($source->events as $event) {
                $quote = $event->pivot->quote;

                if (blank($quote)) {
                    continue;
                }

                $checked++;
                $hit = $locator->locate((string) $quote);

                if ($hit === null) {
                    $violations[] = "{$source->slug} / {$event->title}：{$quote}";

                    continue;
                }

                // 落库的偏移与行号必须与现场计算一致：手抄的数字迟早漂移，
                // 而漂移过的定位比「没有定位」更误导人（它会跳到错误的原文位置）。
                if ($hit->charOffset !== $event->pivot->quote_offset || $hit->line !== $event->pivot->source_line) {
                    $mismatched[] = "{$source->slug} / {$event->title}："
                        ."落库 offset={$event->pivot->quote_offset} line={$event->pivot->source_line}，"
                        ."实际 offset={$hit->charOffset} line={$hit->line}";
                }
            }
        }

        $this->assertSame([], $violations, "以下引文无法在出处语料中定位（不是逐字抄录）：\n".implode("\n", $violations));
        $this->assertSame([], $mismatched, "以下引文的定位与语料不符：\n".implode("\n", $mismatched));

        // 反空过：没有引文被检查时，上面两个断言会「什么也没查」地通过
        $this->assertGreaterThan(30, $checked, '被检查的引文数量异常，闸门可能没真正跑起来');
    }

    /**
     * 年表引文所在的行，要么自己以年份开头，要么是**紧邻上一条年份条目的续行**。
     *
     * 年表用「同一年下分两行」的形态记录彼此关联的两件事（969 年巫王即位与叙拉古独立即如此）。
     * 这层结构以前只能靠人记住，现在它可以从语料本身验证 ——
     * 也就顺带挡住了「给续行补一个年份前缀」这类看起来更整齐、实则改写了原文的做法。
     */
    public function test_chronicle_quotes_sit_on_year_headed_lines_or_continuations(): void
    {
        if (! TerraTourCorpus::exists()) {
            $this->markTestSkipped('本地没有《大地巡旅》原文文件，无法验证引文所在行的形态');
        }

        $locator = TerraTourCorpus::locator();
        $this->assertNotNull($locator);

        $rows = DB::table('event_source')
            ->join('sources', 'sources.id', '=', 'event_source.source_id')
            ->where('sources.slug', 'terra-tour')
            ->where('event_source.chapter', '泰拉纪年')
            ->get(['event_source.quote', 'event_source.source_line']);

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $line = (int) $row->source_line;

            $this->assertTrue(
                $locator->lineStartsWithYear($line) || $locator->isContinuationLine($line),
                "引文「{$row->quote}」落在第 {$line} 行，而该行既不以年份开头、也不是年份条目的续行",
            );
        }
    }

    /**
     * 年表里 `[…]` 标注的条目是凯尔希的补充（原书有注）。
     * 这层语义现在是一列布尔值，可检索、也可在界面上区分 —— 而不是埋在引文文本里。
     */
    public function test_annotation_quotes_are_flagged(): void
    {
        $flagged = DB::table('event_source')
            ->join('sources', 'sources.id', '=', 'event_source.source_id')
            ->where('sources.slug', 'terra-tour')
            ->where('event_source.is_annotation', true)
            ->pluck('event_source.quote');

        $this->assertGreaterThan(0, $flagged->count(), '年表里的凯尔希补充条目应当被标记出来');

        foreach ($flagged as $quote) {
            $this->assertStringStartsWith('[', (string) $quote, '被标为编者按的引文应当以 [ 开头');
        }

        // 反向：不以 [ 开头的引文不应被标成编者按
        $wrong = DB::table('event_source')
            ->join('sources', 'sources.id', '=', 'event_source.source_id')
            ->where('sources.slug', 'terra-tour')
            ->where('event_source.is_annotation', true)
            ->where('event_source.quote', 'not like', '[%')
            ->count();

        $this->assertSame(0, $wrong);
    }

    /**
     * 引文规则按卷拆开：散文卷的出处没有引文是**可接受的待办状态**，
     * 但绝不允许凭印象写引文；年表卷的引文则必须逐字取自原文，
     * 且以年份数字开头 —— 这是「引用可定位」在这本书上的落地形态。
     */
    public function test_terra_tour_citations_follow_the_per_section_rules(): void
    {
        $rows = DB::table('event_source')
            ->join('sources', 'sources.id', '=', 'event_source.source_id')
            ->where('sources.slug', 'terra-tour')
            ->get(['event_source.quote', 'event_source.chapter']);

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            if ($row->chapter === '泰拉纪年') {
                // 年表引文必须存在；它是否**逐字**取自原文，交给
                // test_every_quote_is_locatable_in_its_source_corpus 用语料直接判定 ——
                // 此前这里用「引文以年份开头」当代理指标，但那只是代理：
                // 它挡不住「给续行补一个年份前缀」（969 叙拉古那条正是如此），
                // 反而会把真正的原文形态误判成违规。
                $this->assertNotNull($row->quote, '年表条目必须附年表原文引文');
            } else {
                $this->assertNull($row->quote, '不得为尚未录入原文的散文卷出处编造引文');
                $this->assertContains($row->chapter, ['世界卷', '国家与地区卷', '组织卷']);
            }
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
        // 书里第五章按国家与地区编排，第六章换了维度：组织
        $this->assertContains('组织卷', $sections);
        // 书末附录「泰拉纪年」的条目在 seedEvents() 里，同样挂 terra-tour 出处
        $this->assertContains('泰拉纪年', $sections);
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

            // **同级**判定，而不是全体判定：父级「时代」的区间就是其子纪元的并集，
            // 父子重叠是定义使然。真正必须成立的是「同一父亲下的兄弟不重叠」——
            // 一旦重叠，一个条目就会同时落进两个桶，「所属纪元」随即失去唯一性。
            foreach ($eras->groupBy(fn (Era $era) => $era->parent_id ?? 0) as $siblings) {
                $sorted = $siblings->sortBy('start_index')->values();

                for ($i = 1; $i < $sorted->count(); $i++) {
                    $this->assertLessThan(
                        $sorted[$i]->start_index,
                        $sorted[$i - 1]->end_index,
                        "{$world->label()}：纪元「{$sorted[$i - 1]->name}」与「{$sorted[$i]->name}」的区间重叠",
                    );
                }
            }
        }

        // 反空过：两个世界都必须真的有纪元，否则上面那个循环会「什么也没检查」地通过
        $this->assertGreaterThan(0, Era::ofWorld(World::Terra)->count());
        $this->assertGreaterThan(0, Era::ofWorld(World::Talos)->count());
    }

    /**
     * 子纪元必须落在父「时代」之内 —— 否则「结晶时代涵盖 797 年至今」就成了一句空话。
     */
    public function test_child_eras_stay_inside_their_period(): void
    {
        $children = Era::whereNotNull('parent_id')->with('parent')->get();

        foreach ($children as $child) {
            $this->assertGreaterThanOrEqual(
                $child->parent->start_index,
                $child->start_index,
                "子纪元「{$child->name}」的起点早于父「{$child->parent->name}」",
            );

            $this->assertLessThanOrEqual(
                $child->parent->end_index,
                $child->end_index,
                "子纪元「{$child->name}」的终点晚于父「{$child->parent->name}」",
            );
        }

        // 反空过：没有子纪元时上面的循环等于没跑
        $this->assertGreaterThan(0, $children->count(), '没有任何子纪元，parent_id 等于没被用起来');
    }

    /**
     * 「结晶时代」必须真的建模出来，而且起点就是书里写的 797 年。
     *
     * 这条同时守住 797–999 那段不再混在「远古 · 前纪元」里 ——
     * 此前项目自行把它切进了前纪元，等于丢掉了书里现成的分期。
     */
    public function test_crystalline_era_is_modelled_from_the_book(): void
    {
        $period = Era::ofWorld(World::Terra)->where('slug', 'crystalline-era')->firstOrFail();

        $this->assertSame(TerraDate::toIndex(797), $period->start_index);
        $this->assertGreaterThan(0, $period->children()->count(), '结晶时代应当下辖纪元');
        $this->assertSame(
            TerraDate::toIndex(796, 12, 31),
            Era::where('slug', 'prehistory')->value('end_index'),
            '前纪元应当在 796 年收尾，把 797 年让给结晶时代',
        );

        // 797–999 那批年表条目已经改挂到新的子纪元上（此前混在「远古 · 前纪元」里）
        $event = Event::where('title', '七城联邦建成第一座现代移动城市')->firstOrFail();
        $this->assertSame('era-797-999', $event->era->slug);

        // 父级不参与「挑一个桶」：它不该出现在叶子集合里，
        // 否则条目自动归属、时间轴色带与筛选下拉都会去用它。
        $this->assertNotContains($period->slug, Era::leaves()->pluck('slug')->all());
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

    // ---------------------------------------------------------------- 字典维度

    /**
     * 三处字典：种族（书第四章）、地名（第五章政区）、词条（全书术语）。
     *
     * 重点不是「有没有数据」，而是**关系是否成立** —— 字典最容易烂掉的方式不是为空，
     * 而是指向一个已经不存在的条目，而界面只会安静地少显示一个字段。
     */
    public function test_dictionary_dimensions_are_consistent(): void
    {
        /*
         * 种族：《大地巡旅》第四章立目的那些必须带概要。
         *
         * 第四章立目 32 个（另有温迪戈 / 鬼 / 阿纳萨只在萨卡兹条目里带过一句，也各自写明了）。
         * 门槛卡在 32 而不是「大于 0」：这一栏曾经烂在「16 个有概要、另 20 个留空」上，
         * 理由写的是「书上没立目」——而那是检索失误：后半章的标题丢了 `#` 前缀，
         * 书名号里的目录结构对了，条目却数漏了。
         */
        $this->assertGreaterThanOrEqual(32, Race::whereNotNull('description')->count(), '书里立目的种族应当都有概要');
        $this->assertGreaterThanOrEqual(36, Race::count(), '种族字典未覆盖书里立目的种族');

        /*
         * 核心机制词条要写够：源石与天灾这一层是全书写「泰拉怎么运转」的地方，
         * 2026-09-26 依书把它们从「一句话定义」加厚到 80 字以上 —— 这条防的是退回一句话。
         */
        foreach (['天灾', '源石自然灾害', '源石尘降', '晶体外壳', '源石原矿', '固化源石结晶'] as $core) {
            $term = Term::where('name', $core)->first();

            $this->assertNotNull($term, "核心词条「{$core}」不在字典里");
            $this->assertGreaterThanOrEqual(
                80,
                mb_strlen((string) $term->definition),
                "核心词条「{$core}」的定义又变回一句话了",
            );
        }

        /*
         * 地名里「国家」这一层要写够：《大地巡旅》第五章给每个国家都写了整卷
         * （概览 + 历史 + 地理 + 政治 + 结语）。2026-09-26 依书把这一层加厚过一轮 ——
         * 这里卡的是**平均数**（个别国家本来就短），防的是整体退回一句话。
         */
        $this->assertGreaterThanOrEqual(
            100,
            (int) Place::where('world', 'terra')->where('kind', 'nation')->get()
                ->avg(fn ($place) => mb_strlen((string) $place->description)),
            '国家级的说明又退回一句话了',
        );

        /*
         * 地名整体也要写够：2026-09-26 依书加厚过两轮（国家 42→143、王国 30→153、城市 28→53）。
         * 同样卡平均 —— 省级、村级与莱塔尼亚九大区这类条目本来就只有一行注记（并列清单式），
         * 强求逐条只会让它们掺水。
         */
        $this->assertGreaterThanOrEqual(
            50,
            (int) Place::where('world', 'terra')->get()
                ->avg(fn ($place) => mb_strlen((string) $place->description)),
            '泰拉地名的说明整体又变薄了',
        );

        /*
         * 第六章「组织卷」的九个主打条目（含补篇罗德岛）要写够：它们在书里各自有专节
         * （莱茵生命、黑钢国际、喀兰贸易、锈锤、雷神工业、太阳谷机械工业、企鹅物流、
         * 鲤氏侦探事务所、罗德岛）。2026-09-26 依书把这一卷逐个加厚过一轮。
         * 其余组织不在此列 —— 见下面「政体必须有说明」那条的注脚。
         */
        foreach ([
            '莱茵生命', '黑钢国际', '喀兰贸易', '锈锤', '雷神工业',
            '太阳谷机械工业', '企鹅物流', '鲤氏侦探事务所', '罗德岛',
        ] as $name) {
            $faction = Faction::where('name', $name)->first();

            $this->assertNotNull($faction, "第六章组织卷的「{$name}」不在阵营库里");
            $this->assertGreaterThanOrEqual(
                100,
                mb_strlen((string) $faction?->description),
                "第六章组织卷的「{$name}」说明过薄",
            );
        }

        /*
         * 政体必须有说明：《大地巡旅》第五章给每个国家都写了整卷（概览 + 历史 + 地理 + 政治），
         * 因此「某个国家没有说明」只可能是漏了，不可能是书里没有。
         * 组织则不强求 —— 罗德岛的内部编制、塔卫二的组织与联动单位都不在这本书里，留空是诚实的。
         */
        $this->assertSame(
            0,
            Faction::where('kind', 'polity')
                ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))
                ->count(),
            '有政体没有说明：书里逐卷写过国家，这一栏不该有空',
        );
        $this->assertSame(0, Character::whereNotNull('race_id')->whereDoesntHave('race')->count());
        $this->assertGreaterThan(0, Character::has('race')->count(), '没有任何人物挂上种族字典，链接等于没用');

        // 地名：必须成树、不跨世界，且只能挂到存在的政体上
        $this->assertGreaterThan(0, Place::whereNotNull('parent_id')->count());
        $this->assertSame(0, Place::whereNotNull('parent_id')->whereDoesntHave('parent')->count());
        $this->assertSame(0, Place::whereNotNull('faction_id')->whereDoesntHave('faction')->count());
        $this->assertSame(0, Event::whereNotNull('place_id')->whereDoesntHave('place')->count());
        $this->assertGreaterThan(0, Event::whereNotNull('place_id')->count(), '没有任何条目挂上地名，维度等于没用');

        // 条目的发生地必须与它属于同一个世界
        $crossWorld = Event::with('place')
            ->whereNotNull('place_id')
            ->get()
            ->filter(fn (Event $e) => $e->place->world !== $e->world())
            ->count();
        $this->assertSame(0, $crossWorld, '有条目的发生地挂在另一个世界的地名上');

        // 词条：释义是必填的，空释义的词条没有存在意义
        $this->assertGreaterThan(0, Term::count());
        $this->assertSame(0, Term::whereRaw("coalesce(definition, '') = ''")->count());
        $this->assertSame(0, Term::whereNotIn('category', array_keys(Term::CATEGORIES))->count());
    }

    /**
     * 徽记路径必须指向真的存在的文件，且路径形状统一。
     *
     * 徽记是「锦上添花」的字段：绝大多数实体没有它，页面照常工作 —— 所以它烂掉时
     * 最不容易被发现。列里写了值、文件名写错，页面只会安静地不显示那一枚，
     * 或者更糟：渲染一张碎图。因此这里断言的是**路径与文件的一致性**，
     * 而不是「有多少枚」。
     */
    public function test_emblems_resolve_to_files_that_exist(): void
    {
        $entities = Place::whereNotNull('logo')->get()
            ->merge(Faction::whereNotNull('logo')->get());

        $this->assertGreaterThan(0, $entities->count(), '没有任何实体挂上徽记，这一列等于没用');

        foreach ($entities as $entity) {
            $this->assertFileExists(
                public_path($entity->logo),
                $entity->name.' 的徽记指向了不存在的文件：'.$entity->logo,
            );

            // 落盘一律扁平地放在 assets/emblems/ 下：同一枚徽记常同时属于同名的
            // 一行地名与一行政体，按世界分目录反而要把同一张图放两处。
            $this->assertStringStartsWith('assets/emblems/', $entity->logo);
            $this->assertStringNotContainsString(
                '/',
                substr($entity->logo, strlen('assets/emblems/')),
                $entity->name.' 的徽记不在 assets/emblems/ 的平铺层里：'.$entity->logo,
            );

            // 库里存相对路径、界面上是 URL —— 两者都要能成立
            $this->assertStringStartsWith('http', (string) $entity->logoUrl());
        }
    }

    /**
     * 徽记清单必须能把**每一条**都落到实处。
     *
     * 清单随仓库分发（与头像清单不同，它记的是相对路径），所以本地不该缺它。
     * 三类缺口都要报错而不只是跳过：
     *  - 清单不在 → 关联整段失效，页面上所有徽记一起消失；
     *  - 文件缺失 → 那是一条注定 404 的路径；
     *  - 字典之外的名字 → 清单与字典开始脱节，该重新对一遍。
     */
    public function test_emblem_manifest_links_every_entry(): void
    {
        $manifest = base_path('docs/emblems.json');

        $this->assertFileExists($manifest, 'docs/emblems.json 随仓库分发，不应缺失');

        $stats = EntityEmblems::associate($manifest, public_path());

        $this->assertNotNull($stats);
        $this->assertSame(0, $stats['missing_file'], '清单里的徽记有文件缺失');
        $this->assertSame([], $stats['unknown'], '清单里有字典之外的名字');
        $this->assertGreaterThan(0, $stats['places'], '没有一枚徽记落到地名上');
        $this->assertGreaterThan(0, $stats['factions'], '没有一枚徽记落到阵营上');
    }

    /**
     * 立绘路径必须指向真的存在的文件，变体号要认得出来。
     *
     * 与徽记同一条理由：立绘也是「锦上添花」的字段，烂掉时页面只会安静地不显示那一张，
     * 或者更糟 —— 渲染一张碎图。因此断言的是**路径与文件的一致性**，
     * 而不是「有多少张」；覆盖面的边界另见下一个用例。
     */
    public function test_splashes_resolve_to_files_that_exist(): void
    {
        $characters = Character::whereNotNull('splashes')->get();

        $this->assertGreaterThan(0, $characters->count(), '没有任何人物挂上立绘，这一列等于没用');

        $images = 0;

        foreach ($characters as $character) {
            foreach ($character->splashes as $key => $relative) {
                $images++;

                // 变体号决定展示名，认不出的键会写进库里却没有任何标签可用
                $this->assertMatchesRegularExpression(
                    '/^([12]|skin\d+)$/',
                    (string) $key,
                    $character->name.' 的立绘变体号无法识别：'.$key,
                );

                // 目录按**世界**分（与头像同一规矩）：来源是采集期的事，运行期只按世界落盘
                $this->assertStringStartsWith(
                    'assets/splashes/'.$character->world()->value.'/',
                    $relative,
                    $character->name.' 的立绘不在所属世界的目录里：'.$relative,
                );

                $this->assertFileExists(
                    public_path($relative),
                    $character->name.' 的立绘指向了不存在的文件：'.$relative,
                );

                // 库里存相对路径、界面上是 URL —— 两者都要能成立
                foreach ($character->splashList() as $art) {
                    $this->assertStringStartsWith('http', $art['url']);
                    $this->assertNotSame('', $art['label']);
                }
            }
        }

        // 一个人可以有多张：合并计数应当不少于有立绘的人数
        $this->assertGreaterThanOrEqual($characters->count(), $images);

        // 立绘按**精英一 → 精英二 → 时装**排；顺序错了页面上的标签与图会对不上
        foreach ($characters->take(20) as $character) {
            $keys = array_column($character->splashList(), 'key');
            $sorted = $keys;
            usort($sorted, fn ($a, $b) => Character::splashVariantSortKey($a)
                <=> Character::splashVariantSortKey($b));
            $this->assertSame($sorted, $keys, $character->name.' 的立绘清单没有按变体排序');
        }
    }

    /**
     * 立绘清单要能落到实处，且三类「空」各有各的性质，不能混为一谈。
     *
     *  - 塔卫二整侧为空：fz.wiki 的干员条目只有文字与图标，**来源侧就没有立绘**
     *    （见 bin/fetch-splashes.py 的说明）。写成断言是为了让将来的人一眼看到
     *    「这不是漏抓」—— 少一条断言，下次就会有人去补一个补不出来的东西。
     *  - 历史人物为空：他们根本不在干员名单里，没有立绘是应当的。
     *  - 「清单之外的名字」应为空：清单入库前已经过 `bin/fetch-splashes.py --prune-missing`
     *    清理 —— 来源名单比本仓库大的那一批（未实装的、卫戍协议形态、建制的无名单位）
     *    不会以孤儿文件的形式进仓库，因此这里出现任何「清单之外」都是真的脱节，必须查。
     */
    public function test_splash_manifest_links_every_entry(): void
    {
        $manifest = base_path('docs/splashes.json');

        // 清单随仓库分发（记的是相对 public/ 的路径，与头像那份 ignore 掉的外部清单相反），
        // 所以本地不该缺它
        $this->assertFileExists($manifest, 'docs/splashes.json 随仓库分发，不应缺失');

        $stats = CharacterSplashes::associate($manifest, public_path());

        $this->assertNotNull($stats);
        $this->assertSame(0, $stats['missing_file'], '清单里的立绘有文件缺失');
        $this->assertGreaterThan(0, $stats['characters'], '没有一位人物关联上立绘');

        /*
         * 「清单之外的名字」应当为空 —— 这与徽记那份同一标准，而不是放宽。
         *
         * 立绘清单在入库前已经过 `bin/fetch-splashes.py --prune-missing`：PRTS 的
         * 立绘名单比本仓库大（未实装的 F91、卫戍协议形态、预备干员-XX 建制单位、
         * 制作组彩蛋），这些「库里没有的人」连同孤儿文件一起被清掉，不会进仓库。
         * 因此这里一旦出现任何「清单之外」，都是真的脱节（要么清单忘了清理、
         * 要么字典漏了该收录的人），而不是「来源本来就没有」的正当差异 ——
         * 那种差异已经被采集层的清理吸收掉了。
         *
         * 断言的是「空」而不是「某份名字清单」，因为那份清单已经不存在了；
         * 若要新增人名，正路是改字典（数据库），不是在这里追加例外。
         */
        $this->assertSame(
            [],
            $stats['unknown'],
            '清单里出现了库里不存在的人物 —— 先跑 bin/fetch-splashes.py --prune-missing，'
                .'再核对是否漏收了该人物',
        );

        // 有立绘的人里，绝大多数应当**两档都有**（精英一与精英二）；全库一个双档的
        // 人都没有，说明多变体从清单到页面这一整条链断在某一处，而不是来源本来就没有
        $this->assertGreaterThan(
            0,
            Character::whereNotNull('splashes')->get()
                ->filter(fn (Character $c) => count((array) $c->splashes) > 1)
                ->count(),
            '没有任何人物挂上两档立绘，多变体这条路等于没走通',
        );

        // 覆盖率：泰拉干员里应当有八成以上拿得到立绘。这条比「清单之外有几个」更能
        // 说明问题 —— 清单抓漏、关联退化都会先在这里掉下去。
        $terraOperators = Character::ofWorld(World::Terra)->where('kind', CharacterKind::Operator);
        $total = $terraOperators->count();
        $covered = $terraOperators->whereNotNull('splashes')->count();

        $this->assertGreaterThan(
            (int) ($total * 0.8),
            $covered,
            "泰拉干员的立绘覆盖率低于八成（{$covered}/{$total}）",
        );

        $this->assertSame(
            0,
            Character::ofWorld(World::Talos)->whereNotNull('splashes')->count(),
            '塔卫二在来源侧没有立绘，这一列应当整侧为空',
        );

        $this->assertSame(
            0,
            Character::where('kind', CharacterKind::Historical)->whereNotNull('splashes')->count(),
            '历史人物不在干员名单里，不该有立绘',
        );
    }

    /**
     * 条目挂地名取**最长匹配**：location 原文里同时出现「维多利亚」与「伦蒂尼姆」时，
     * 必须落到更具体的那个 —— 否则「按地区聚合」会把首都的条目挂到整片疆域上。
     */
    /**
     * 种子必须对条目**幂等**。
     *
     * `EventWriter::create()` 每次都会新建一条，因此一个没有守卫的种子跑两遍就会
     * 让时间线整体翻倍 —— 而对本项目来说，一份翻倍的年表比没有年表更糟：
     * 它恰好是「可被信赖」这件事的反面，而且页面不会报错，只会安静地显示错的东西。
     */
    public function test_seeder_does_not_duplicate_events_on_a_second_run(): void
    {
        $before = Event::count();
        $this->assertGreaterThan(0, $before);

        // 再灌一次：应当整批跳过条目，而不是再建一份
        $this->seed(TimelineSeeder::class);

        $this->assertSame($before, Event::count(), '重复执行种子把条目翻倍了');
    }

    /**
     * 地名树要覆盖第 5 章全部十九卷的政区，且**层级必须真的成立**。
     *
     * 子级的 parent_id 由「上级是否已插入」决定，而上级不存在时是**静默**落成 null ——
     * 「龙门」此前就是这样丢掉了它的上级。因此这里逐个点名，而不是只数个数。
     */
    public function test_place_tree_covers_every_country_volume(): void
    {
        $volumes = [
            '维多利亚', '莱塔尼亚', '乌萨斯帝国', '高卢', '拉特兰', '伊比利亚', '阿戈尔', '谢拉格',
            '卡西米尔', '哥伦比亚', '玻利瓦尔', '叙拉古', '萨尔贡', '米诺斯', '萨米', '雷姆必拓',
            '炎国', '东国', '卡兹戴尔',
        ];

        $names = Place::ofWorld(World::Terra)->pluck('name')->all();

        foreach ($volumes as $name) {
            $this->assertContains($name, $names, "第 5 章的政区「{$name}」没有进地名树");
        }

        // 子级不得挂空
        $children = [
            '维多利亚王国', '塔拉王国', '下高卢王国', '伦蒂尼姆', '海登施威尔大区',
            '圣骏堡', '格里高利省', '切尔诺伯格', '汐斯塔', '尚蜀', '龙门',
            '赫库兰尼姆', '西西里', '察帕特', '塔尔干主矿脉', '尤立卡自治州',
        ];

        $orphans = Place::ofWorld(World::Terra)
            ->whereIn('name', $children)
            ->whereNull('parent_id')
            ->pluck('name');

        $this->assertSame([], $orphans->all(), '这些地名的上级没有挂上（上级必须先于子级插入）');

        // 别名是数据：书里用过的简称要记在字典里，否则 location 里的简称永远挂不上链接
        $this->assertContains('乌萨斯', Place::where('name', '乌萨斯帝国')->firstOrFail()->aliases);

        // 塔卫二同样要分层：供能高地是四号谷地**境内**的高地，不是与它并列的一级地名
        $this->assertSame(
            '四号谷地',
            Place::ofWorld(World::Talos)->where('name', '供能高地')->firstOrFail()->parent?->name,
        );

        // 链子要一路连到顶：文明环带 → 四号谷地 → 供能高地
        $this->assertSame(
            '文明环带',
            Place::ofWorld(World::Talos)->where('name', '四号谷地')->firstOrFail()->parent?->name,
        );

        // 文明环带是**地域**：它被排除出组织页，因此地名树里的这个节点是它唯一的落点。
        // 少了它，这东西会在两个页面之间消失 —— 既不算组织，又没地方可查。
        $this->assertSame(
            'territory',
            \App\Models\Faction::where('name', '文明环带')->firstOrFail()->kind->value,
        );
    }

    /**
     * 干员名单（PRTS 导出）的导入纪律。
     *
     * 这份名单是批量导入的，最容易出的事故不是「少了几个人」，而是**把人工维护的东西冲掉**、
     * 或者把「未公开」当成一个种族写进去。三条一起守。
     */
    public function test_operator_roster_is_imported_without_overwriting_curated_people(): void
    {
        $roster = json_decode((string) file_get_contents(base_path('docs/prts-干员一览.json')), true);

        $this->assertIsArray($roster);
        $this->assertGreaterThan(300, count($roster), 'PRTS 导出看起来不完整');

        $operators = Character::ofWorld(World::Terra)->where('kind', 'operator')->pluck('name')->all();

        // 名单里的人都在库里 —— 这是导入存在的意义
        $missing = collect($roster)
            ->map(fn (array $row) => trim((string) ($row['zh'] ?? '')))
            ->filter(fn (string $name) => $name !== '' && ! in_array($name, $operators, true));

        $this->assertSame([], $missing->take(10)->values()->all(), '名单里有干员没有入库');

        // 人工核过的人不被覆盖：斯卡蒂的简介是本仓库写的
        $this->assertNotNull(Character::where('name', '斯卡蒂')->firstOrFail()->description);

        // 「未公开」这类写法一律留空，且不得作为种族入字典
        foreach (['斯卡蒂', '夕', '年', '结城理'] as $name) {
            $this->assertNull(
                Character::where('name', $name)->firstOrFail()->race_id,
                $name.' 的种族在来源里不是规范种族名，应当留空',
            );
        }

        $this->assertFalse(
            Race::whereIn('name', ['未公开', '未知', '不明', '未录入', '因经纪公司要求不公开'])->exists(),
            '非种族值被写进了种族字典',
        );

        // 势力取值要能落到阵营上（含别名归并），否则这些人会没有归属
        $this->assertSame(
            0,
            Character::ofWorld(World::Terra)->where('kind', 'operator')->doesntHave('factions')->count(),
            '有干员的势力没有落到任何阵营上',
        );
    }

    /**
     * 多归属：一个人可以**同时**属于「深海猎人」与「阿戈尔」，两条都要在。
     *
     * 改造前只有一列 `faction_id`，装不下第二条 —— 于是导入名单时只能取最具体的那个，
     * 另一半被静默丢掉。丢掉的那半个事实在页面上看不出缺了什么，这正是它值得一条测试的原因。
     */
    public function test_characters_can_belong_to_several_factions(): void
    {
        $shark = Character::where('name', '幽灵鲨')->firstOrFail();
        $names = $shark->factions->pluck('name')->all();

        $this->assertContains('深海猎人', $names);
        $this->assertContains('阿戈尔', $names);

        // 顺序由来源的层序决定（小队 → 团体 → 国别）：越具体越靠前
        $this->assertSame('深海猎人', $names[0], '更具体的归属应当排在前面');

        // 归并来的那一条要真的能被筛到，否则等于没记
        $this->get(route('operators.index', ['faction' => Faction::where('name', '阿戈尔')->value('id')]))
            ->assertOk()
            ->assertSee('幽灵鲨');
    }

    /**
     * 出身地：**能对上的必须对上**。
     *
     * 「对不上」本身是允许的 —— 来源写的是「未公开」「瓦伊凡」这类非地名值。
     * 但能对上却空着就是静默缺失：页面上只会少一个链接，不会有任何报错。
     */
    public function test_birth_places_are_resolved_into_place_nodes(): void
    {
        $placeNames = Place::pluck('name')->all();
        $withBirthPlace = Character::with('birthPlace')->whereNotNull('birth_place')->get();

        $this->assertGreaterThan(0, $withBirthPlace->count());
        // 大多数应当能对上：对不上的只是少数非地名写法
        $this->assertGreaterThan(
            $withBirthPlace->count() / 2,
            $withBirthPlace->whereNotNull('birth_place_id')->count(),
        );

        foreach ($withBirthPlace as $character) {
            if ($character->birth_place_id !== null) {
                continue;
            }

            $this->assertNotContains(
                $character->birth_place,
                $placeNames,
                "「{$character->name}」的出身地「{$character->birth_place}」对得上地名树却空着",
            );
        }

        // 锚点：写法对照（来源写「炎」，本仓库叫「炎国」）与限定语剥离（「汐斯塔（独立城邦）」）
        $this->assertSame('炎国', Character::where('name', '令')->firstOrFail()->birthPlace->name);
        $this->assertSame('汐斯塔', Character::where('name', '锡兰')->firstOrFail()->birthPlace->name);
    }

    /**
     * 每个阵营都必须有**明确**的类型。
     *
     * 「未归类」是留给新出现阵营的显眼位置，不是一个可以长期停放的常量：
     * 它一旦非空，就说明有人往阵营树里加了东西却没分类 ——
     * 而那时资料集的「组织」页会悄悄多出一个不该在那儿的东西。
     */
    public function test_every_faction_has_a_declared_kind(): void
    {
        $unclassified = Faction::where('kind', FactionKind::Other->value)->pluck('name');

        $this->assertSame([], $unclassified->all(), '这些阵营还没有归类');
    }

    /**
     * 政体与地域**不得**出现在组织里。
     *
     * 「哪些算组织」只有一处判据（`FactionKind::isOrganization()`），
     * 但那条判据很容易在某个查询里被悄悄绕过 —— 这里从数据侧把结果钉住：
     * 维多利亚（政体）与文明环带（地域）都不该是组织。
     */
    public function test_polities_and_territories_are_not_organizations(): void
    {
        $this->assertSame('polity', Faction::where('name', '维多利亚')->firstOrFail()->kind->value);
        $this->assertSame('territory', Faction::where('name', '文明环带')->firstOrFail()->kind->value);

        $organizationNames = Faction::organizations()->pluck('name');

        $this->assertNotContains('维多利亚', $organizationNames->all());
        $this->assertNotContains('文明环带', $organizationNames->all());

        // 而组织确实在里面 —— 否则上两条会因为「组织页是空的」而假通过
        $this->assertContains('莱茵生命', $organizationNames->all());
    }

    public function test_event_places_prefer_the_most_specific_match(): void
    {
        $places = Place::ofWorld(World::Terra)->get();
        $linked = Event::with('place')->whereNotNull('place_id')->get();

        $this->assertGreaterThan(0, $linked->count());

        foreach ($linked as $event) {
            $place = $event->place;
            $location = (string) $event->location;

            // 命中的必须是这个地名自己的某个写法。**别名同样算数**：
            // location 里写「乌萨斯」而挂到「乌萨斯帝国」是对的，因为简称记在该地名的别名里。
            $tokens = collect($place->matchTokens())
                ->filter(fn (string $token) => str_contains($location, $token));

            $this->assertNotEmpty(
                $tokens->values()->all(),
                "条目「{$event->title}」的发生地「{$place->name}」没有任何写法出现在 location 原文里",
            );

            $best = $tokens->map(fn (string $token) => mb_strlen($token))->max();

            // 不得存在写法更长的候选。等长的情况交给深度平局规则（见 seedPlaces 的 matchPlace），
            // 这里只守「没有更长的」——那是最容易出错的半边。
            $longer = $places->filter(fn (Place $other) => collect($other->matchTokens())
                ->contains(fn (string $token) => str_contains($location, $token) && mb_strlen($token) > $best));

            $this->assertSame(
                [],
                $longer->pluck('name')->values()->all(),
                "条目「{$event->title}」的 location 里还有写法更长的地名，应当挂到那个上",
            );
        }

        // 锚点 1：切尔诺伯格事变发生在切尔诺伯格
        $this->assertSame(
            '切尔诺伯格',
            Event::where('title', '切尔诺伯格事变爆发')->firstOrFail()->place->name,
        );

        // 锚点 2：别名 —— location 写的是简称「乌萨斯」，应落到「乌萨斯帝国」
        $this->assertSame(
            '乌萨斯帝国',
            Event::where('location', '乌萨斯')->firstOrFail()->place->name,
        );

        // 锚点 3：等长时取更具体的 ——「炎国 · 尚蜀」里两个写法都是两字，应落到层级更深的尚蜀
        $this->assertSame(
            '尚蜀',
            Event::where('location', '炎国 · 尚蜀')->firstOrFail()->place->name,
        );
    }

    /**
     * 非干员的两档（历史人物、剧情人物）共用同一套不变量：
     * **有头衔、有简介、没有干员代号**。
     *
     * 干员与他们是两类实体 —— 前者有代号与干员页，后者有头衔与身份说明。
     * 混在一起的话，干员名单里会混进一堆几百年前的皇帝，以及当代的组织创办者。
     */
    public function test_non_operator_figures_are_typed_and_carry_titles(): void
    {
        $figures = Character::whereIn('kind', [
            CharacterKind::Historical->value,
            CharacterKind::Npc->value,
        ])->get();

        $this->assertGreaterThan(0, $figures->count());

        foreach ($figures as $figure) {
            $this->assertNotNull($figure->title, "非干员「{$figure->name}」应当有头衔或身份");
            $this->assertNotNull($figure->description, "非干员「{$figure->name}」应当有简介");
            $this->assertNull($figure->codename, "非干员「{$figure->name}」不应有干员代号");

            if ($figure->reign_start_index !== null && $figure->reign_end_index !== null) {
                $this->assertLessThan(
                    $figure->reign_end_index,
                    $figure->reign_start_index,
                    "非干员「{$figure->name}」的在位区间方向反了",
                );
            }
        }

        // 锚点 1：巫王的在位区间是年表明写的 969–1077
        $hel = Character::where('name', '赫尔昏佐伦')->firstOrFail();
        $this->assertSame(CharacterKind::Historical, $hel->kind);
        $this->assertSame(TerraDate::toIndex(969), $hel->reign_start_index);
        $this->assertSame(TerraDate::toIndex(1077, 12, 31), $hel->reign_end_index);
        $this->assertStringContainsString('巫王', (string) $hel->title);

        // 锚点 2：当代的组织创办者归「剧情人物」—— 他们不是干员，也不是几百年前的人
        $cliff = Character::where('name', '克里夫')->firstOrFail();
        $this->assertSame(CharacterKind::Npc, $cliff->kind);
        $this->assertNull($cliff->reign_start_index, '剧情人物不该有在位区间');

        // 锚点 3：「岁」是炎国的远古实体，不是干员 —— 它此前被记在干员名单里
        $this->assertSame(CharacterKind::Historical, Character::where('name', '岁')->firstOrFail()->kind);
    }

    /**
     * 干员名单（`/operators`）只列干员：历史人物不进去。
     * 这条守住的是「分类一旦落地就必须真的生效」，否则 kind 只是一个没人读的字段。
     */
    public function test_operator_index_excludes_historical_figures(): void
    {
        $historical = Character::where('kind', 'historical')->firstOrFail();

        $this->get(route('operators.index', ['world' => World::Terra->value]))
            ->assertOk()
            ->assertDontSee($historical->name);
    }

    /**
     * 塔卫二人员来自 `docs/fz-干员一览.json`（终末地 Wiki 的干员名单）。
     *
     * 名单把管理员按性别拆成两行（共用英文代号 Endministrator），而本仓库的
     * 「管理员」是同一个人 —— 三个条目并排出现，读者只会困惑。
     */
    public function test_talos_roster_is_imported_from_the_endfield_wiki(): void
    {
        // 已有条目不被覆盖：管理员的人工简介与归属都还在；
        // 代号是**空字段** —— 由名单的 nameEn 照来源写法补上（只补空，不覆盖既有值）
        $endministrator = Character::where('name', '管理员')->firstOrFail();
        $this->assertStringContainsString('失去记忆', (string) $endministrator->description);
        $this->assertSame('终末地工业', $endministrator->factions->first()->name);
        $this->assertSame('Endministrator', $endministrator->codename);

        // 性别变体没有变成独立的三行
        $this->assertSame(1, Character::where('name', 'like', '管理员%')->count());

        // 名单里的人带代号、种族与势力进来
        $wolf = Character::where('name', '狼卫')->firstOrFail();
        $this->assertSame('Wulfgard', $wolf->codename);
        $this->assertSame('鲁珀', $wolf->race->name);
        $this->assertSame('狼群氏族', $wolf->factions->first()->name);
        $this->assertSame(World::Talos, $wolf->world());

        // 清波寨既是地名树里的聚落、也是名单里的势力 —— 两个维度各自回答不同的问题
        $this->assertSame('清波寨', Character::where('name', '汤汤')->firstOrFail()->factions->first()->name);

        // 名单给出的种族全部对得上字典（「■■」这类遮盖写法不算数，但管理员那两条已归并）。
        // 例外集必须**恰好**是「管理员」：Wiki 对它的种族写着遮盖符「■■」——
        // 来源自己说「不公开」，留空才是正确行为，不是导入把数据丢了
        $this->assertSame(
            ['管理员'],
            Character::ofWorld(World::Talos)->where('kind', 'operator')
                ->whereNotNull('codename')->whereNull('race_id')
                ->orderBy('name')->pluck('name')->all(),
            '除来源标注「不公开」的人之外，带代号的人员都必须对上种族字典',
        );

        // 名单里的势力要在组织页上看得见 —— 只进库不上页，等于没完善
        $this->get(route('organizations.index', ['world' => World::Talos->value]))
            ->assertOk()
            ->assertSee('宏山科学院')
            ->assertSee('应龙特勤队');
    }

    /**
     * 归属改走枢轴之后（一人可属多个阵营），这里查的是另一半：
     * **没有人一个归属都没有**。「指向不存在的阵营」由外键拦住，
     * 而「导入漏了归属」数据库不会报错，只会在页面上少一行。
     */
    public function test_every_character_has_at_least_one_faction(): void
    {
        $orphans = Character::doesntHave('factions')->pluck('name');

        $this->assertSame([], $orphans->all(), '这些人员没有任何阵营归属');
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
