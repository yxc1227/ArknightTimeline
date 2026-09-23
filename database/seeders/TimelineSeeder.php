<?php

namespace Database\Seeders;

use App\Enums\ChangeOrigin;
use App\Enums\EventStatus;
use App\Enums\SourceType;
use App\Models\Character;
use App\Models\Era;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Source;
use App\Models\Tag;
use App\Models\User;
use App\Services\Ai\AiEventSynthesizer;
use App\Services\EventWriter;
use App\Services\UserManager;
use App\Support\TerraDate;
use Illuminate\Database\Seeder;

/**
 * 起始语料。
 *
 * ⚠️ 关于数据性质，必须说清楚：
 * 这是让站点「开箱可用」的**起始语料**，不是权威年表。其中大量条目在社区考据中
 * 属于「由上下文推断」而非官方明写，因此：
 *
 *   - 每个条目都带 date_confidence，官方文本明写的记 confirmed，推断的记 inferred；
 *   - 推断条目的 status 为 needs_review，会在审核流程里被持续核对；
 *   - 出处字段尽量精确到关卡号 / 章节，方便人工逐一溯源；
 *   - 剧情原文（sources.raw_text）只在少数出处里预置了示例段落，
 *     用于演示「AI 梳理 → 提案 → 人工放行」的完整链路。
 *
 * 数据分三层来源：
 *   1. 剧情条目（主线 / 活动）——  有较明确的游戏内时间，多为 inferred；
 *   2. 《大地巡礼》条目        ——  世界观机制与国家背景，**年份普遍未载**，
 *                                  因此统一落在「时间未定」泳道，见 seedTerraTourEvents()；
 *   3. 示例语料与 AI 提案      ——  演示完整的梳理链路。
 *
 * 换句话说：这份数据本身就是产品要解决的问题的样本 —— 它需要被考据者继续纠错。
 */
class TimelineSeeder extends Seeder
{
    /** 《大地巡礼》条目的统一时间占位：明确「年份未载」，而不是伪造一个年份。 */
    private const UNDATED_DATE = '泰拉历未载具体年份';

    public function run(): void
    {
        $admin = $this->seedUsers();
        $this->seedFactions();
        $this->seedEras();
        $this->seedCharacters();
        $this->seedTags();
        $this->seedSources();
        $this->seedEvents($admin);
        $this->seedTerraTourEvents($admin);
        $this->seedProposalsFromCorpus();
    }

    // ------------------------------------------------------------------ 账号

    private function seedUsers(): User
    {
        $accounts = [
            ['name' => '档案管理员', 'email' => 'admin@terra.local', 'role' => 'admin', 'password' => 'terra-admin'],
            ['name' => '考据审核员', 'email' => 'reviewer@terra.local', 'role' => 'reviewer', 'password' => 'terra-reviewer'],
            ['name' => '条目编辑者', 'email' => 'editor@terra.local', 'role' => 'editor', 'password' => 'terra-editor'],
            ['name' => '访客读者', 'email' => 'viewer@terra.local', 'role' => 'viewer', 'password' => 'terra-viewer'],
            // 停用示例：账号列表、状态筛选与「已禁用」徽章都需要至少一条真实数据才有意义
            ['name' => '停用示例账号', 'email' => 'disabled@terra.local', 'role' => 'editor', 'password' => 'terra-disabled'],
        ];

        $admin = null;
        $created = [];

        foreach ($accounts as $account) {
            // withTrashed + 清空 deleted_at：让 seed 可重复执行
            // （软删除的账号仍占用 email 唯一索引，否则二次 seed 会撞唯一键）
            $user = User::withTrashed()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'display_name' => $account['name'],
                    'password' => $account['password'],
                    'role' => $account['role'],
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'deleted_at' => null,
                ],
            );

            $created[$account['email']] = $user;
            $admin ??= $user;
        }

        $this->seedUserActivity($admin, $created);

        return $admin;
    }

    /**
     * 生成真实的操作日志与账号状态。
     *
     * 刻意走 UserManager 而不是直接 `UserActivityLog::create([...])`：
     * 这样种子数据里的日志措辞、字段级前后值与生产完全一致 ——
     * 手写字符串的话，改了服务层文案就会让演示数据变成过时的假象。
     *
     * @param  array<string, User>  $users
     */
    private function seedUserActivity(User $admin, array $users): void
    {
        $manager = app(UserManager::class);

        // 登录痕迹（同时写入 logged_in 日志）
        foreach (['reviewer@terra.local', 'editor@terra.local'] as $email) {
            $manager->logLogin($users[$email]);
        }

        // 把示例账号真的停用一次，从而得到一条 deactivated 日志与对应的字段变化
        $manager->setActive($users['disabled@terra.local'], false, $admin);
    }

    // ------------------------------------------------------------------ 阵营

    private function seedFactions(): void
    {
        $tree = [
            ['name' => '罗德岛', 'full_name' => '罗德岛制药公司', 'color' => '#38bdf8', 'children' => [
                ['name' => '精英干员', 'color' => '#0ea5e9'],
                ['name' => '医疗部', 'color' => '#22d3ee'],
            ]],
            ['name' => '巴别塔', 'full_name' => '巴别塔（罗德岛前身）', 'color' => '#6366f1', 'description' => '罗德岛的前身组织，卡兹戴尔内战期间活跃，后改组为罗德岛。'],
            ['name' => '整合运动', 'full_name' => '感染者反抗组织 · 整合运动', 'color' => '#f87171'],
            ['name' => '乌萨斯帝国', 'color' => '#94a3b8', 'children' => [
                ['name' => '乌萨斯军事委员会', 'color' => '#64748b'],
            ]],
            ['name' => '龙门', 'full_name' => '龙门独立市', 'color' => '#fbbf24'],
            ['name' => '维多利亚', 'full_name' => '维多利亚王国', 'color' => '#a78bfa'],
            ['name' => '卡西米尔', 'full_name' => '卡西米尔骑士之国', 'color' => '#f472b6'],
            ['name' => '哥伦比亚', 'color' => '#10b981', 'description' => '原为维多利亚殖民地，独立后迅速工业化，莱茵生命等大型研究机构以此为基地。', 'children' => [
                // 莱茵生命是哥伦比亚的研究机构，挂到母政体下之后，
                // 「按哥伦比亚筛选」会自动把莱茵生命相关的条目也带出来
                ['name' => '莱茵生命', 'full_name' => '莱茵生命实验室', 'color' => '#34d399'],
            ]],
            ['name' => '伊比利亚', 'color' => '#22d3ee'],
            ['name' => '炎国', 'color' => '#fb923c'],
            ['name' => '叙拉古', 'color' => '#c084fc'],
            ['name' => '喀兰贸易', 'color' => '#60a5fa'],
            ['name' => '谢拉格', 'color' => '#93c5fd'],
            ['name' => '卡兹戴尔', 'full_name' => '卡兹戴尔 / 萨卡兹流亡政权', 'color' => '#ef4444'],
            ['name' => '拉特兰', 'color' => '#e2e8f0'],
            ['name' => '萨尔贡', 'color' => '#facc15'],
            ['name' => '深池', 'full_name' => '维多利亚感染者组织 · 深池', 'color' => '#e879f9'],

            // 《大地巡礼》「国家与地区」卷覆盖、但此前未进入检索维度的政体。
            // 只填在有把握的字段上：不做正式国名的推测，拿不准的一律留空。
            ['name' => '莱塔尼亚', 'color' => '#818cf8', 'description' => '以双王共治体制与术师传统著称的政体。'],
            ['name' => '米诺斯', 'color' => '#2dd4bf', 'description' => '由多个城邦构成的地区，保有古老的信仰与竞技传统。'],
            ['name' => '玻利瓦尔', 'color' => '#fb7185', 'description' => '长期陷入内乱与外部势力干涉的地区。'],
            ['name' => '雷姆必拓', 'color' => '#a3a3a3', 'description' => '以矿业与资源贸易立身的企业化政体。'],
            ['name' => '萨米', 'color' => '#bae6fd', 'description' => '泰拉北境雪原地区，以部族与萨满信仰为组织形态。'],
            ['name' => '阿戈尔', 'color' => '#0891b2', 'description' => '与深海威胁直接相关的海洋文明。'],
        ];

        foreach ($tree as $order => $faction) {
            $parent = $this->upsertFaction($faction, $order);

            foreach ($faction['children'] ?? [] as $childOrder => $child) {
                $this->upsertFaction($child, $childOrder, $parent->id);
            }
        }
    }

    private function upsertFaction(array $data, int $order, ?int $parentId = null): Faction
    {
        return Faction::updateOrCreate(
            ['slug' => $data['slug'] ?? 'fac-'.md5($data['name'])],
            [
                'name' => $data['name'],
                'full_name' => $data['full_name'] ?? null,
                'color' => $data['color'] ?? '#64748b',
                'description' => $data['description'] ?? null,
                'parent_id' => $parentId,
                'sort_order' => $order,
            ],
        );
    }

    // ------------------------------------------------------------------ 纪元

    /**
     * 纪元切分是「检索维度」而非「世界观设定」，因此按剧情推进的节点划分，
     * 区间用 TerraDate 网格索引表示，条目的 era_id 与区间不一致时会被一致性巡检点名。
     *
     * 配色刻意采用**单一色族的递进梯度**（远古灰 → 深黄 → 标志黄 → 琥珀 → 橙 → 红），
     * 而不是七个互不相干的色相：整站只有「近黑 / 白 / 标志黄」三种主色，
     * 纪元的可辨识度靠明度与暖度递进获得。时间顺序上的「逐步升级」也正好被颜色表达出来。
     */
    private function seedEras(): void
    {
        $eras = [
            [
                'name' => '远古 · 前纪元',
                'slug' => 'prehistory',
                'subtitle' => '源石与文明的原点',
                'date_label' => '泰拉历前 — 泰拉历 999 年',
                'start' => TerraDate::toIndex(-500),
                'end' => TerraDate::toIndex(999, 12, 31),
                'color' => '#808080',
                'description' => '源石显现、源石技艺普及、各古老政体成型的时期。年表大量缺失，条目多需推断。',
            ],
            [
                'name' => '旧秩序的裂痕',
                'slug' => 'era-1000-1093',
                'subtitle' => '卡兹戴尔内战与列国博弈',
                'date_label' => '泰拉历 1000 年 — 1093 年',
                'start' => TerraDate::toIndex(1000),
                'end' => TerraDate::toIndex(1093, 12, 31),
                'color' => '#9a7d18',
                'description' => '萨卡兹流亡、伊比利亚大静谧余波、乌萨斯与卡西米尔的长期对抗。',
            ],
            [
                'name' => '切城事变前夕',
                'slug' => 'era-1094-1095',
                'subtitle' => '巴别塔落幕与罗德岛重建',
                'date_label' => '泰拉历 1094 年 — 1095 年',
                'start' => TerraDate::toIndex(1094),
                'end' => TerraDate::toIndex(1095, 12, 31),
                'color' => '#c9a227',
            ],
            [
                'name' => '切尔诺伯格事变与龙门危机',
                'slug' => 'era-1096-1097',
                'subtitle' => '主线第 0 — 8 章',
                'date_label' => '泰拉历 1096 年 12 月 — 1097 年',
                'start' => TerraDate::toIndex(1096, 12),
                'end' => TerraDate::toIndex(1097, 12, 31),
                'color' => '#ffd400',
                'description' => '整合运动崛起、切尔诺伯格遭天灾、罗德岛介入救援并卷入龙门危机。',
            ],
            [
                'name' => '整合运动余波',
                'slug' => 'era-1098-1099',
                'subtitle' => '主线第 8 — 9 章',
                'date_label' => '泰拉历 1098 年 — 1099 年',
                'start' => TerraDate::toIndex(1098),
                'end' => TerraDate::toIndex(1099, 12, 31),
                'color' => '#ffa32e',
            ],
            [
                'name' => '维多利亚战争',
                'slug' => 'era-1100',
                'subtitle' => '主线第 10 — 11 章 · 伦蒂尼姆',
                'date_label' => '泰拉历 1100 年',
                'start' => TerraDate::toIndex(1100),
                'end' => TerraDate::toIndex(1100, 12, 31),
                'color' => '#ff7038',
            ],
            [
                'name' => '萨卡兹的终局',
                'slug' => 'era-1101',
                'subtitle' => '主线第 12 — 15 章',
                'date_label' => '泰拉历 1101 年',
                'start' => TerraDate::toIndex(1101),
                'end' => TerraDate::toIndex(1101, 12, 31),
                'color' => '#ff4242',
            ],
        ];

        foreach ($eras as $order => $era) {
            Era::updateOrCreate(
                ['slug' => $era['slug']],
                [
                    'name' => $era['name'],
                    'subtitle' => $era['subtitle'],
                    'date_label' => $era['date_label'],
                    'start_index' => $era['start'],
                    'end_index' => $era['end'],
                    'description' => $era['description'] ?? null,
                    'color' => $era['color'],
                    'sort_order' => $order,
                ],
            );
        }
    }

    // ------------------------------------------------------------------ 人物

    private function seedCharacters(): void
    {
        $factionSlugs = Faction::pluck('id', 'name');

        $characters = [
            ['博士', 'Doctor', '罗德岛', '未公开'],
            ['阿米娅', 'Amiya', '罗德岛', '卡特斯'],
            ['凯尔希', 'Kal\'tsit', '罗德岛', '菲林'],
            ['特蕾西娅', 'Theresa', '巴别塔', '萨卡兹'],
            ['特雷西斯', 'Theresis', '卡兹戴尔', '萨卡兹'],
            ['塔露拉', 'Talulah', '整合运动', '德拉克'],
            ['爱国者', 'Patriot', '整合运动', '温迪戈'],
            ['霜星', 'Frostnova', '整合运动', '萨卡兹'],
            ['梅菲斯特', 'Mephisto', '整合运动', '萨卡兹'],
            ['浮士德', 'Faust', '整合运动', '萨卡兹'],
            ['陈', 'Ch\'en', '龙门', '龙族'],
            ['魏彦吾', 'Wei Yenwu', '龙门', '龙族'],
            ['玛莉娅·临光', 'Maria Nearl', '卡西米尔', '库兰塔'],
            ['玛恩纳', 'Młynar', '卡西米尔', '库兰塔'],
            ['耀骑士临光', 'Nearl', '卡西米尔', '库兰塔'],
            ['塞雷娅', 'Saria', '莱茵生命', '瓦伊凡'],
            ['缪尔赛思', 'Muelsyse', '莱茵生命', '精灵'],
            ['伊芙利特', 'Ifrit', '莱茵生命', '萨弗拉'],
            ['赫默', 'Silence', '莱茵生命', '黎博利'],
            ['棘刺', 'Thorns', '伊比利亚', '阿戈尔'],
            ['银灰', 'SilverAsh', '谢拉格', '菲林'],
            ['初雪', 'Pramanix', '谢拉格', '菲林'],
            ['尤里卡', null, '谢拉格', null],
            ['拉普兰德', 'Lappland', '叙拉古', '鲁珀'],
            ['德克萨斯', 'Texas', '叙拉古', '鲁珀'],
            ['能天使', 'Exusiai', '拉特兰', '萨科塔'],
            ['岁', null, '炎国', null],
            ['夕', 'Dusk', '炎国', null],
            ['令', 'Ling', '炎国', null],
            ['重岳', 'Chongyue', '炎国', null],
            ['赫拉格', 'Hellagur', '乌萨斯帝国', '黎博利'],
            ['亚叶', null, '罗德岛', null],
            ['瓦拉', null, '卡兹戴尔', '萨卡兹'],

            // 《大地巡礼》「国家与地区」卷涉及、此前缺失的关联人物。
            // 种族字段留空而不是靠印象填：拿不准的字段宁可缺失，也不要写错。
            ['帕拉斯', 'Pallas', '米诺斯', null],
            ['斯卡蒂', 'Skadi', '阿戈尔', null],
            ['幽灵鲨', 'Specter', '阿戈尔', null],
            ['歌蕾蒂娅', 'Gladiia', '阿戈尔', null],
        ];

        foreach ($characters as $order => [$name, $codename, $faction, $race]) {
            Character::updateOrCreate(
                ['slug' => 'chr-'.md5($name)],
                [
                    'name' => $name,
                    'codename' => $codename,
                    'faction_id' => $factionSlugs[$faction] ?? null,
                    'race' => $race,
                    'sort_order' => $order,
                ],
            );
        }
    }

    // ------------------------------------------------------------------ 标签

    private function seedTags(): void
    {
        $tags = [
            ['战役', '#ef4444'], ['战争', '#f87171'], ['政变', '#a78bfa'],
            ['条约', '#38bdf8'], ['灾害', '#fbbf24'], ['内战', '#fb923c'],
            ['骑士竞技', '#f472b6'], ['感染者', '#34d399'], ['天灾', '#e879f9'],
            ['源石', '#22d3ee'], ['萨卡兹', '#c084fc'], ['政权更迭', '#94a3b8'],
        ];

        foreach ($tags as [$name, $color]) {
            Tag::updateOrCreate(
                ['slug' => 'tag-'.md5($name)],
                ['name' => $name, 'color' => $color],
            );
        }
    }

    // ------------------------------------------------------------------ 出处

    private function seedSources(): void
    {
        $sources = [
            // ---- 主线 ----
            ['主线 · 序章「黑暗时代·上」', 'main-story-0', SourceType::MainStory, '0-1', '黑暗时代·上', 1],
            ['主线 · 第一章「黑暗时代·下」', 'main-story-1', SourceType::MainStory, '1-1', '黑暗时代·下', 2],
            ['主线 · 第二章「异卵同生」', 'main-story-2', SourceType::MainStory, '2-1', '异卵同生', 3],
            ['主线 · 第三章「二次呼吸」', 'main-story-3', SourceType::MainStory, '3-1', '二次呼吸', 4],
            ['主线 · 第四章「大地的尽头」', 'main-story-4', SourceType::MainStory, '4-1', '大地的尽头', 5],
            ['主线 · 第五章「靶心」', 'main-story-5', SourceType::MainStory, '5-1', '靶心', 6],
            ['主线 · 第六章「局部坏死」', 'main-story-6', SourceType::MainStory, '6-1', '局部坏死', 7],
            ['主线 · 第七章「苦海」', 'main-story-7', SourceType::MainStory, '7-1', '苦海', 8],
            ['主线 · 第八章「怒号光明」', 'main-story-8', SourceType::MainStory, '8-1', '怒号光明', 9],
            ['主线 · 第九章「风暴瞭望」', 'main-story-9', SourceType::MainStory, '9-1', '风暴瞭望', 10],
            ['主线 · 第十章「破碎日冕」', 'main-story-10', SourceType::MainStory, '10-1', '破碎日冕', 11],
            ['主线 · 第十一章「淬火尘霾」', 'main-story-11', SourceType::MainStory, '11-1', '淬火尘霾', 12],
            ['主线 · 第十二章「惊霆无声」', 'main-story-12', SourceType::MainStory, '12-1', '惊霆无声', 13],
            ['主线 · 第十三章「恶兆湍流」', 'main-story-13', SourceType::MainStory, '13-1', '恶兆湍流', 14],
            ['主线 · 第十四章「慈悲灯塔」', 'main-story-14', SourceType::MainStory, '14-1', '慈悲灯塔', 15],
            ['主线 · 第十五章「离解复合」', 'main-story-15', SourceType::MainStory, '15-1', '离解复合', 16],

            // ---- 活动剧情 ----
            ['活动 · 骑兵与猎人', 'event-granis', SourceType::Event, 'GT', '骑兵与猎人', 20],
            ['活动 · 火蓝之心', 'event-heart', SourceType::Event, 'OF', '火蓝之心', 21],
            ['活动 · 生于黑夜', 'event-night', SourceType::Event, 'NL', '生于黑夜', 22],
            ['活动 · 玛莉娅·临光', 'event-maria', SourceType::Event, 'MN', '玛莉娅·临光', 23],
            ['活动 · 覆潮之下', 'event-tides', SourceType::Event, 'SV', '覆潮之下', 24],
            ['活动 · 沃伦姆德的薄暮', 'event-wolumonde', SourceType::Event, 'TW', '沃伦姆德的薄暮', 25],
            ['活动 · 风雪过境', 'event-snow', SourceType::Event, 'BI', '风雪过境', 26],
            ['活动 · 孤星', 'event-lone-trail', SourceType::Event, 'RS', '孤星', 27],
            ['活动 · 叙拉古人', 'event-siracusa', SourceType::Event, 'IS', '叙拉古人', 28],
            ['活动 · 愚人号', 'event-navis', SourceType::Event, 'SN', '愚人号', 29],
            ['活动 · 将进酒', 'event-jiangjinjiu', SourceType::Event, 'IW', '将进酒', 30],
            ['活动 · 登临意', 'event-denglini', SourceType::Event, 'WB', '登临意', 31],

            // ---- 干员档案 ----
            ['干员档案 · 特蕾西娅', 'record-theresa', SourceType::OperatorRecord, null, '干员档案', 40],
            ['干员档案 · 塔露拉', 'record-talulah', SourceType::OperatorRecord, null, '干员档案', 41],

            // ---- 设定集 ----
            ['官方设定集 Vol.1', 'artbook-1', SourceType::Artbook, 'Vol.1', '世界观 / 阵营 / 年表', 50],
            ['官方设定集 Vol.2', 'artbook-2', SourceType::Artbook, 'Vol.2', '世界观 / 干员', 51],
            ['官方设定集 Vol.3', 'artbook-3', SourceType::Artbook, 'Vol.3', '世界观 / 干员', 52],
            ['《大地巡礼》', 'terra-tour', SourceType::Artbook, '官方世界观设定集', '世界卷 / 国家与地区卷', 48],

            // ---- 世界观设定 ----
            ['世界观设定 · 泰拉纪年表', 'setting-chronicle', SourceType::Setting, null, '泰拉编年', 60],
            ['世界观设定 · 源石与天灾', 'setting-originium', SourceType::Setting, null, '源石学', 61],
        ];

        foreach ($sources as [$name, $slug, $type, $code, $chapter, $order]) {
            Source::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'type' => $type->value,
                    'code' => $code,
                    'chapter' => $chapter,
                    'release_order' => $order,
                    'description' => $type->label().' · 用于为事件条目提供可溯源的出处。',
                ],
            );
        }

        // 《大地巡礼》的定位说明。这里刻意**不预置 raw_text、也不填引文**：
        // 这本书的具体表述尚未逐页录入，而编造引文会直接摧毁「引用可定位」这条校验的价值。
        // 正确的工作流是：录入原文 → 出处页「开始梳理」→ 审核台放行，引文与时间随之补齐。
        Source::where('slug', 'terra-tour')->update([
            'description' => '官方世界观设定集。系统收录其中的世界观机制与国家／地区背景条目；'
                .'章节定位为粗粒度标注（世界卷 / 国家与地区卷），引文待按页码录入后补齐。',
        ]);

        // 预置示例语料，让「AI 梳理 → 提案 → 人工放行」这条链路开箱可演示
        Source::where('slug', 'setting-chronicle')->update([
            'raw_text' => <<<'TXT'
泰拉纪年表（节选）

泰拉历前500年，源石首次被系统性地记录与利用，源石技艺自此开始向各文明扩散。
泰拉历1038年，伊比利亚沿海爆发被后世称为「大静谧」的灾难，海洋文明体系随之崩解。
泰拉历1063年，乌萨斯帝国与卡西米尔就边境领地的归属爆发长期战争，双方均未取得决定性战果。
泰拉历1086年，卡兹戴尔内战爆发，萨卡兹各派系围绕王位继承展开争夺。
泰拉历1094年，巴别塔在卡兹戴尔内战中解体，其残部改组为罗德岛制药公司。
泰拉历1096年12月23日，切尔诺伯格事变爆发，整合运动占领切尔诺伯格城区。
泰拉历1097年1月，罗德岛抵达龙门，龙门危机爆发。
泰拉历1099年，维多利亚小丘郡爆发感染者冲突，深池组织公开露面。
泰拉历1100年，维多利亚内战全面爆发，伦蒂尼恩成为各方争夺的核心。
泰拉历1101年，萨卡兹诸部在维多利亚的战争进入终局阶段。
TXT,
        ]);

        Source::where('slug', 'main-story-0')->update([
            'raw_text' => <<<'TXT'
【黑暗时代·上】

泰拉历1096年12月23日，切尔诺伯格事变爆发，整合运动武装攻入切尔诺伯格城区，
乌萨斯军警在混乱中失去对城市的控制，多座城区燃起大火。

同日，罗德岛小队在切尔诺伯格地下设施中发现「石棺」，
与石棺中沉睡的博士接触并将其唤醒。这次接触并未出现在任何官方记录中。

泰拉历1096年12月24日，源石风暴逼近切尔诺伯格，罗德岛小队与博士决定撤离该城。
撤离途中，小队与整合运动先遣队发生交火。
TXT,
        ]);
    }

    // ------------------------------------------------------------------ 事件

    /**
     * 事件条目。
     *
     * 时间结论的可信度分级说明：
     *  - confirmed：官方文本 / 关卡内明写（如 1096 年 12 月 23 日的切尔诺伯格事变）；
     *  - inferred ：由上下文物证推断（多数章节年代、活动剧情的时间点属于此类）。
     */
    private function seedEvents(User $admin): void
    {
        $writer = app(EventWriter::class);

        $events = [
            // ===== 远古 / 前纪元 =====
            [
                'title' => '源石被系统性利用',
                'date' => '泰拉历前500年',
                'era' => 'prehistory',
                'confidence' => 'inferred',
                'summary' => '源石首次被系统性记录与利用，源石技艺自此向各文明扩散，成为泰拉一切技术与灾难的源头。',
                'details' => '年表仅给出「前500年」这一上限；各文明对源石的接触时间并不一致，实际起点可能更早。',
                'location' => null,
                'sources' => [['setting-chronicle', null, '泰拉历前500年，源石首次被系统性地记录与利用。'], ['setting-originium', null, null]],
                'factions' => [['萨尔贡', 'involved']],
                'tags' => ['源石'],
            ],
            [
                'title' => '大静谧（伊比利亚）',
                'date' => '泰拉历1038年',
                'era' => 'era-1000-1093',
                'confidence' => 'inferred',
                'summary' => '伊比利亚沿海爆发被后世称为「大静谧」的灾难，海洋文明体系随之崩解，伊比利亚自此长期封闭。',
                'details' => '灾难的具体成因在设定中始终缺乏统一解释，存在多种互相矛盾的假说。',
                'location' => '伊比利亚',
                'sources' => [['setting-chronicle', null, '泰拉历1038年，伊比利亚沿海爆发被后世称为「大静谧」的灾难。'], ['artbook-2', null, null]],
                'factions' => [['伊比利亚', 'victim']],
                'tags' => ['灾害', '源石'],
                'status' => 'verified',
            ],
            [
                'title' => '乌萨斯—卡西米尔边境战争',
                'date' => '泰拉历1063年',
                'era' => 'era-1000-1093',
                'confidence' => 'inferred',
                'summary' => '乌萨斯帝国与卡西米尔就边境领地归属爆发长期战争，双方均未取得决定性战果，边境格局长期僵持。',
                'location' => '乌萨斯—卡西米尔边境',
                'sources' => [['setting-chronicle', null, '泰拉历1063年，乌萨斯帝国与卡西米尔就边境领地的归属爆发长期战争。'], ['artbook-1', null, null]],
                'factions' => [['乌萨斯帝国', 'instigator'], ['卡西米尔', 'victim']],
                'tags' => ['战争'],
                'status' => 'verified',
            ],
            [
                'title' => '卡兹戴尔内战爆发',
                'date' => '泰拉历1086年',
                'era' => 'era-1000-1093',
                'confidence' => 'inferred',
                'summary' => '萨卡兹各派系围绕王位继承展开争夺，卡兹戴尔内战爆发。',
                'details' => '内战持续多年，是理解罗德岛前身「巴别塔」与萨卡兹流亡史的关键背景。',
                'location' => '卡兹戴尔',
                'sources' => [['setting-chronicle', null, '泰拉历1086年，卡兹戴尔内战爆发。'], ['artbook-1', null, null]],
                'factions' => [['卡兹戴尔', 'involved']],
                'characters' => [['特蕾西娅', 'support'], ['特雷西斯', 'support']],
                'tags' => ['内战', '萨卡兹'],
                'status' => 'verified',
            ],

            // ===== 1094 – 1095 =====
            [
                'title' => '巴别塔解体与罗德岛成立',
                'date' => '泰拉历1094年',
                'era' => 'era-1094-1095',
                'confidence' => 'inferred',
                'summary' => '巴别塔在卡兹戴尔内战中解体，其残部改组为罗德岛制药公司，以医疗与感染者救助为对外身份延续下来。',
                'details' => '「巴别塔 → 罗德岛」的转变是本作最重要的组织史节点：对外是制药公司，对内仍延续着巴别塔的目标。',
                'location' => '卡兹戴尔 / 罗德岛',
                'sources' => [['setting-chronicle', null, '泰拉历1094年，巴别塔在卡兹戴尔内战中解体，其残部改组为罗德岛制药公司。'], ['record-theresa', null, null], ['artbook-1', null, null]],
                'factions' => [['罗德岛', 'involved'], ['巴别塔', 'victim'], ['卡兹戴尔', 'involved']],
                'characters' => [['特蕾西娅', 'protagonist'], ['凯尔希', 'support'], ['阿米娅', 'support']],
                'tags' => ['政权更迭'],
                'status' => 'verified',
            ],
            [
                'title' => '博士进入石棺沉睡',
                'date' => '泰拉历1095年',
                'era' => 'era-1094-1095',
                'confidence' => 'inferred',
                'summary' => '博士进入切尔诺伯格地下设施中的「石棺」沉睡，直到次年事变当天被罗德岛小队唤醒。',
                'details' => '石棺的技术来源、博士沉睡的真实原因与时长，至今仍是主线中未完全揭开的悬念。',
                'location' => '切尔诺伯格',
                'sources' => [['main-story-0', '0-1', null], ['artbook-1', null, null]],
                'factions' => [['罗德岛', 'involved']],
                'characters' => [['博士', 'protagonist'], ['凯尔希', 'support']],
                'tags' => [],
            ],

            // ===== 1096 年 12 月 =====
            [
                'title' => '切尔诺伯格事变爆发',
                'date' => '泰拉历1096年12月23日',
                'era' => 'era-1096-1097',
                'confidence' => 'confirmed',
                'summary' => '整合运动武装攻入切尔诺伯格城区，乌萨斯军警失去对城市的控制，多座城区燃起大火。此事件是整个主线的时间原点。',
                'details' => '整合运动以「感染者夺回生存权」为旗帜发动总攻。乌萨斯方面对事件的定性长期语焉不详，救援与善后几乎由罗德岛与龙门承担。',
                'location' => '切尔诺伯格',
                'sources' => [['main-story-0', '0-1', '泰拉历1096年12月23日，切尔诺伯格事变爆发，整合运动武装攻入切尔诺伯格城区。'], ['setting-chronicle', null, '泰拉历1096年12月23日，切尔诺伯格事变爆发。']],
                'factions' => [['整合运动', 'instigator'], ['乌萨斯帝国', 'victim'], ['罗德岛', 'involved']],
                'characters' => [['塔露拉', 'protagonist'], ['阿米娅', 'support']],
                'tags' => ['战役', '感染者', '天灾'],
                'status' => 'verified',
            ],
            [
                'title' => '博士自石棺苏醒',
                'date' => '泰拉历1096年12月23日',
                'era' => 'era-1096-1097',
                'confidence' => 'confirmed',
                'summary' => '罗德岛小队在切尔诺伯格地下设施中发现石棺，并与沉睡其中的博士接触、将其唤醒。',
                'details' => '这次接触未被记入任何官方记录，却是玩家视角的起点。',
                'location' => '切尔诺伯格地下设施',
                'sources' => [['main-story-0', '0-1', '同日，罗德岛小队在切尔诺伯格地下设施中发现「石棺」，与石棺中沉睡的博士接触并将其唤醒。']],
                'factions' => [['罗德岛', 'involved']],
                'characters' => [['博士', 'protagonist'], ['阿米娅', 'support'], ['凯尔希', 'support']],
                'tags' => [],
                'sort_seq' => 1,
                'status' => 'verified',
            ],
            [
                'title' => '罗德岛撤离切尔诺伯格',
                'date' => '泰拉历1096年12月24日',
                'era' => 'era-1096-1097',
                'confidence' => 'inferred',
                'summary' => '源石风暴逼近切尔诺伯格，罗德岛小队带着博士撤离该城，途中与整合运动先遣队交火。',
                'location' => '切尔诺伯格',
                'sources' => [['main-story-0', '0-2', '泰拉历1096年12月24日，源石风暴逼近切尔诺伯格，罗德岛小队与博士决定撤离该城。'], ['main-story-1', '1-1', null]],
                'factions' => [['罗德岛', 'involved'], ['整合运动', 'involved']],
                'characters' => [['博士', 'protagonist'], ['阿米娅', 'protagonist']],
                'tags' => ['天灾'],
            ],

            // ===== 1097 =====
            [
                'title' => '龙门危机爆发',
                'date' => '泰拉历1097年1月',
                'era' => 'era-1096-1097',
                'confidence' => 'inferred',
                'summary' => '罗德岛抵达龙门，与龙门执政者就感染者安置与对整合运动的应对展开交涉，龙门危机爆发。',
                'details' => '龙门作为独立市在乌萨斯与炎国之间维持着脆弱的平衡，整合运动的介入直接冲击了这一平衡。',
                'location' => '龙门',
                'sources' => [['main-story-2', '2-1', null], ['setting-chronicle', null, '泰拉历1097年1月，罗德岛抵达龙门，龙门危机爆发。']],
                'factions' => [['龙门', 'involved'], ['罗德岛', 'involved'], ['整合运动', 'instigator']],
                'characters' => [['陈', 'protagonist'], ['阿米娅', 'protagonist'], ['魏彦吾', 'support']],
                'tags' => ['感染者'],
                'status' => 'verified',
            ],
            [
                'title' => '龙门贫民窟感染者骚乱',
                'date' => '泰拉历1097年2月',
                'era' => 'era-1096-1097',
                'confidence' => 'inferred',
                'summary' => '龙门贫民窟的感染者与治安力量发生大规模冲突，整合运动的渗透被公开化。',
                'location' => '龙门 · 贫民窟',
                'sources' => [['main-story-3', '3-1', null], ['main-story-4', '4-1', null]],
                'factions' => [['龙门', 'involved'], ['整合运动', 'instigator']],
                'characters' => [['陈', 'protagonist']],
                'tags' => ['感染者'],
            ],
            [
                'title' => '龙门外环攻防战',
                'date' => '泰拉历1097年3月',
                'era' => 'era-1096-1097',
                'confidence' => 'inferred',
                'summary' => '整合运动对龙门发动正面进攻，双方在龙门外环展开攻防。龙门守军与罗德岛协同作战。',
                'location' => '龙门 · 外环',
                'sources' => [['main-story-4', '4-4', null], ['main-story-5', '5-1', null]],
                'factions' => [['整合运动', 'instigator'], ['龙门', 'involved'], ['罗德岛', 'involved']],
                'characters' => [['塔露拉', 'protagonist'], ['陈', 'protagonist'], ['浮士德', 'support']],
                'tags' => ['战役'],
            ],
            [
                'title' => '霜星之死',
                'date' => '泰拉历1097年春',
                'era' => 'era-1096-1097',
                'confidence' => 'inferred',
                'summary' => '整合运动干部霜星在战斗后因体内源石结晶恶化而死，其结局成为罗德岛重新审视整合运动动机的转折点。',
                'details' => '霜星与罗德岛之间短暂的相互理解，是主线中最早触及「敌人也有正当性」的段落。',
                'location' => '龙门近郊',
                'sources' => [['main-story-5', '5-6', null], ['main-story-6', '6-1', null]],
                'factions' => [['整合运动', 'victim'], ['罗德岛', 'involved']],
                'characters' => [['霜星', 'protagonist'], ['阿米娅', 'support'], ['博士', 'support']],
                'tags' => ['感染者'],
            ],
            [
                'title' => '切尔诺伯格核心城坠落',
                'date' => '泰拉历1097年夏',
                'era' => 'era-1096-1097',
                'confidence' => 'inferred',
                'summary' => '切尔诺伯格核心城被引导坠向龙门，罗德岛与龙门方面全力阻止其对龙门造成直接撞击。',
                'details' => '此事件的处置结果是主线第 7 章的核心矛盾，也是塔露拉计划的关键一环。',
                'location' => '龙门外海',
                'sources' => [['main-story-7', '7-18', null], ['artbook-2', null, null]],
                'factions' => [['整合运动', 'instigator'], ['龙门', 'victim'], ['罗德岛', 'involved']],
                'characters' => [['塔露拉', 'protagonist'], ['爱国者', 'support'], ['陈', 'support']],
                'tags' => ['战役', '天灾'],
            ],
            [
                'title' => '爱国者战死',
                'date' => '泰拉历1097年夏',
                'era' => 'era-1096-1097',
                'confidence' => 'inferred',
                'summary' => '整合运动干部、温迪戈爱国者在与罗德岛的交战中战死，成为整合运动瓦解的前奏。',
                'location' => '切尔诺伯格核心城',
                'sources' => [['main-story-7', '7-16', null]],
                'factions' => [['整合运动', 'victim'], ['罗德岛', 'involved']],
                'characters' => [['爱国者', 'protagonist'], ['阿米娅', 'support'], ['博士', 'support']],
                'tags' => ['战役'],
                'sort_seq' => 1,
            ],
            [
                'title' => '整合运动在龙门的失败',
                'date' => '泰拉历1097年',
                'era' => 'era-1096-1097',
                'confidence' => 'inferred',
                'summary' => '整合运动对龙门的攻势被彻底击退，塔露拉被俘，组织随之分崩离析。',
                'location' => '龙门',
                'sources' => [['main-story-8', '8-1', null], ['record-talulah', null, null]],
                'factions' => [['整合运动', 'victim'], ['龙门', 'involved'], ['罗德岛', 'involved']],
                'characters' => [['塔露拉', 'protagonist'], ['陈', 'protagonist']],
                'tags' => ['战役'],
            ],

            // ===== 活动：1097 =====
            [
                'title' => '火蓝之心 · 汐斯塔事件',
                'date' => '泰拉历1097年夏',
                'era' => 'era-1096-1097',
                'confidence' => 'inferred',
                'summary' => '罗德岛在汐斯塔介入当地政治与感染者问题，事件以汐斯塔局势重组告终。',
                'location' => '汐斯塔',
                'sources' => [['event-heart', 'OF-1', null]],
                'factions' => [['罗德岛', 'involved']],
                'tags' => [],
            ],
            [
                'title' => '风雪过境 · 谢拉格政变',
                'date' => '泰拉历1097年冬',
                'era' => 'era-1096-1097',
                'confidence' => 'inferred',
                'summary' => '谢拉格内部围绕宗教权威与世俗权力的对立激化，喀兰贸易与罗德岛先后介入，最终推动谢拉格对外打开国门。',
                'location' => '谢拉格',
                'sources' => [['event-snow', 'BI-1', null]],
                'factions' => [['谢拉格', 'involved'], ['喀兰贸易', 'involved'], ['罗德岛', 'involved']],
                'characters' => [['银灰', 'protagonist'], ['初雪', 'protagonist']],
                'tags' => ['政变'],
            ],

            // ===== 1098 – 1099 =====
            [
                'title' => '卡西米尔骑士竞技风波',
                'date' => '泰拉历1099年',
                'era' => 'era-1098-1099',
                'confidence' => 'inferred',
                'summary' => '卡西米尔骑士竞技体系内部的腐败与商业化问题被公开化，临光家与相关企业在事件中扮演关键角色。',
                'location' => '卡西米尔 · 大骑士领',
                'sources' => [['event-night', 'NL-1', null], ['event-maria', 'MN-1', null]],
                'factions' => [['卡西米尔', 'involved'], ['罗德岛', 'involved']],
                'characters' => [['玛莉娅·临光', 'protagonist'], ['耀骑士临光', 'protagonist'], ['玛恩纳', 'support']],
                'tags' => ['骑士竞技'],
            ],
            [
                'title' => '覆潮之下 · 盐风城事件',
                'date' => '泰拉历1099年',
                'era' => 'era-1098-1099',
                'confidence' => 'inferred',
                'summary' => '伊比利亚盐风城出现与深海相关的异变，罗德岛介入调查，逐步揭开大静谧背后的线索。',
                'location' => '伊比利亚 · 盐风城',
                'sources' => [['event-tides', 'SV-1', null]],
                'factions' => [['伊比利亚', 'involved'], ['罗德岛', 'involved']],
                'characters' => [['棘刺', 'protagonist']],
                'tags' => ['灾害'],
            ],
            [
                'title' => '沃伦姆德事件',
                'date' => '泰拉历1099年12月',
                'era' => 'era-1098-1099',
                'confidence' => 'inferred',
                'summary' => '沃伦姆德城因感染者与市民的对立陷入失控，罗德岛在极端时间压力下试图阻止灾难发生。',
                'details' => '本事件常被视为「如果罗德岛来晚一步会怎样」的思想实验，对主线中的救援正当性构成直接提问。',
                'location' => '沃伦姆德',
                'sources' => [['event-wolumonde', 'TW-1', null], ['artbook-2', null, null]],
                'factions' => [['罗德岛', 'involved']],
                'tags' => ['灾害', '感染者'],
            ],
            [
                'title' => '维多利亚小丘郡事件',
                'date' => '泰拉历1099年',
                'era' => 'era-1098-1099',
                'confidence' => 'inferred',
                'summary' => '维多利亚小丘郡爆发感染者与地方势力的武装冲突，感染者组织「深池」公开露面。',
                'location' => '维多利亚 · 小丘郡',
                'sources' => [['main-story-9', '9-1', null], ['setting-chronicle', null, '泰拉历1099年，维多利亚小丘郡爆发感染者冲突，深池组织公开露面。']],
                'factions' => [['维多利亚', 'involved'], ['深池', 'instigator'], ['罗德岛', 'involved']],
                'tags' => ['感染者', '战役'],
            ],

            // ===== 1100 =====
            [
                'title' => '伦蒂尼姆攻防战开始',
                'date' => '泰拉历1100年',
                'era' => 'era-1100',
                'confidence' => 'inferred',
                'summary' => '维多利亚内战全面爆发，伦蒂尼姆成为各方争夺的核心，萨卡兹军事力量深度介入。',
                'location' => '维多利亚 · 伦蒂尼姆',
                'sources' => [['main-story-10', '10-1', null], ['setting-chronicle', null, '泰拉历1100年，维多利亚内战全面爆发，伦蒂尼姆成为各方争夺的核心。']],
                'factions' => [['维多利亚', 'involved'], ['卡兹戴尔', 'instigator'], ['罗德岛', 'involved'], ['深池', 'involved']],
                'characters' => [['特雷西斯', 'protagonist']],
                'tags' => ['战争', '内战'],
            ],
            [
                'title' => '伦蒂尼姆围城与感染者军团的建立',
                'date' => '泰拉历1100年',
                'era' => 'era-1100',
                'confidence' => 'inferred',
                'summary' => '围城期间，城内感染者被组织成军事力量，维多利亚各方势力的立场进一步分裂。',
                'location' => '维多利亚 · 伦蒂尼姆',
                'sources' => [['main-story-11', '11-1', null]],
                'factions' => [['维多利亚', 'involved'], ['深池', 'involved'], ['罗德岛', 'involved']],
                'tags' => ['战争', '感染者'],
                'sort_seq' => 1,
            ],
            [
                'title' => '孤星 · 特里蒙事件',
                'date' => '泰拉历1100年',
                'era' => 'era-1100',
                'confidence' => 'inferred',
                'summary' => '莱茵生命在特里蒙的研究项目引发严重事故，罗德岛与莱茵生命围绕研究与伦理的边界正面交锋。',
                'location' => '哥伦比亚 · 特里蒙',
                'sources' => [['event-lone-trail', 'RS-1', null]],
                'factions' => [['莱茵生命', 'instigator'], ['罗德岛', 'involved']],
                'characters' => [['塞雷娅', 'protagonist'], ['缪尔赛思', 'support'], ['伊芙利特', 'support'], ['赫默', 'support']],
                'tags' => ['源石'],
            ],
            [
                'title' => '叙拉古人 · 家族清算',
                'date' => '泰拉历1100年',
                'era' => 'era-1100',
                'confidence' => 'inferred',
                'summary' => '叙拉古各家族之间的矛盾激化，罗德岛与当地势力就秩序重建达成有限合作。',
                'location' => '叙拉古',
                'sources' => [['event-siracusa', 'IS-1', null]],
                'factions' => [['叙拉古', 'involved'], ['罗德岛', 'involved']],
                'characters' => [['拉普兰德', 'protagonist'], ['德克萨斯', 'support']],
                'tags' => ['政变'],
            ],

            // ===== 1101 =====
            [
                'title' => '维多利亚战局转折',
                'date' => '泰拉历1101年',
                'era' => 'era-1101',
                'confidence' => 'inferred',
                'summary' => '维多利亚战场上的力量对比发生变化，萨卡兹诸部的战略目标被迫调整。',
                'location' => '维多利亚',
                'sources' => [['main-story-13', '13-1', null], ['setting-chronicle', null, '泰拉历1101年，萨卡兹诸部在维多利亚的战争进入终局阶段。']],
                'factions' => [['维多利亚', 'involved'], ['卡兹戴尔', 'involved'], ['罗德岛', 'involved']],
                'tags' => ['战争'],
            ],
            [
                'title' => '萨卡兹战争进入终局',
                'date' => '泰拉历1101年',
                'era' => 'era-1101',
                'confidence' => 'inferred',
                'summary' => '围绕萨卡兹命运与维多利亚主权的长期战争走向终局，多方的立场与代价在此阶段被集中清算。',
                'location' => '维多利亚 · 伦蒂尼姆',
                'sources' => [['main-story-14', '14-1', null], ['main-story-15', '15-1', null]],
                'factions' => [['卡兹戴尔', 'involved'], ['维多利亚', 'involved'], ['罗德岛', 'involved']],
                'characters' => [['特雷西斯', 'protagonist'], ['阿米娅', 'protagonist']],
                'tags' => ['战争', '萨卡兹'],
                'sort_seq' => 1,
            ],
            [
                'title' => '愚人号 · 伊比利亚海域行动',
                'date' => '泰拉历1101年',
                'era' => 'era-1101',
                'confidence' => 'inferred',
                'summary' => '罗德岛参与伊比利亚方面的海上行动，围绕深海威胁与历史遗留问题的线索进一步展开。',
                'location' => '伊比利亚',
                'sources' => [['event-navis', 'SN-1', null]],
                'factions' => [['伊比利亚', 'involved'], ['罗德岛', 'involved']],
                'tags' => ['灾害'],
            ],
            [
                'title' => '将进酒 · 炎国尚蜀之行',
                'date' => '泰拉历1101年',
                'era' => 'era-1101',
                'confidence' => 'inferred',
                'summary' => '罗德岛在炎国尚蜀介入与「岁」相关的古老问题，炎国方向的长期线索被推进。',
                'location' => '炎国 · 尚蜀',
                'sources' => [['event-jiangjinjiu', 'IW-1', null]],
                'factions' => [['炎国', 'involved'], ['罗德岛', 'involved']],
                'characters' => [['夕', 'protagonist'], ['令', 'support']],
                'tags' => [],
            ],
            [
                'title' => '登临意 · 岁相相关事件',
                'date' => '泰拉历1101年',
                'era' => 'era-1101',
                'confidence' => 'inferred',
                'summary' => '炎国方向围绕「岁」的线索进一步展开，罗德岛与炎国方面就相关风险的处理达成共识。',
                'location' => '炎国',
                'sources' => [['event-denglini', 'WB-1', null]],
                'factions' => [['炎国', 'involved'], ['罗德岛', 'involved']],
                'characters' => [['重岳', 'protagonist'], ['岁', 'support']],
                'tags' => [],
                'sort_seq' => 1,
            ],

            // ===== 时间未定（演示「时间未定」泳道）=====
            [
                'title' => '「岁」的诞生',
                'date' => '泰拉历纪元前（年表未载）',
                'era' => 'prehistory',
                'confidence' => 'unknown',
                'summary' => '炎国传说中「岁」的诞生，与泰拉各地的远古实体叙事存在结构相似性，但缺乏可定位的纪年记录。',
                'details' => '本条目的价值恰在于「时间未知」：需要考据者从炎国方向的文本中反推其年代区间。',
                'location' => '炎国',
                'sources' => [['event-jiangjinjiu', 'IW-ST', null], ['artbook-3', null, null]],
                'factions' => [['炎国', 'involved']],
                'characters' => [['岁', 'protagonist']],
                'tags' => [],
                'status' => 'needs_review',
            ],
            [
                'title' => '萨尔贡的黄金时代',
                'date' => '泰拉历纪元前（年表未载）',
                'era' => 'prehistory',
                'confidence' => 'unknown',
                'summary' => '萨尔贡曾存在的繁盛时期，其遗迹与技术遗存遍布沙漠，但纪年已不可考。',
                'location' => '萨尔贡',
                'sources' => [['artbook-1', null, null], ['setting-chronicle', null, null]],
                'factions' => [['萨尔贡', 'involved']],
                'tags' => [],
                'status' => 'needs_review',
            ],
            [
                'title' => '深海的起源（海嗣相关）',
                'date' => '泰拉历纪元前（年表未载）',
                'era' => 'prehistory',
                'confidence' => 'unknown',
                'summary' => '与深海生物相关的起源叙事，其时间点在各出处中表述不一，尚无法收敛到具体纪年。',
                'details' => '该主题在多个出处中被提及，但表述互相矛盾，属于典型的「出处矛盾」待处置项。',
                'location' => '海洋',
                'sources' => [['event-navis', 'SN-ST', null], ['artbook-3', null, null]],
                'factions' => [['伊比利亚', 'victim']],
                'tags' => ['源石'],
                'status' => 'disputed',
                'confidence' => 'disputed',
            ],
        ];

        $byTitle = [];

        foreach ($events as $data) {
            $eraId = Era::where('slug', $data['era'])->value('id');

            $payload = [
                'title' => $data['title'],
                'summary' => $data['summary'],
                'details' => $data['details'] ?? null,
                'location' => $data['location'] ?? null,
                'date_display' => $data['date'],
                'date_precision' => $data['precision'] ?? null,
                'date_confidence' => $data['confidence'],
                'era_id' => $eraId,
                'sort_seq' => $data['sort_seq'] ?? 0,
                'status' => $data['status'] ?? ($data['confidence'] === 'confirmed' ? EventStatus::Verified->value : EventStatus::NeedsReview->value),
                // 显式给出 null，交给 TerraDateParser 从 date_display 推导区间
                'start_index' => null,
                'end_index' => null,
                'sources' => collect($data['sources'] ?? [])->map(fn ($s) => [
                    'id' => Source::where('slug', $s[0])->value('id'),
                    'stage_code' => $s[1] ?? null,
                    'quote' => $s[2] ?? null,
                    'is_primary' => false,
                ])->filter(fn ($s) => $s['id'])->values()->all(),
                'characters' => collect($data['characters'] ?? [])->map(fn ($c) => ['name' => $c[0], 'role' => $c[1]])->all(),
                'factions' => collect($data['factions'] ?? [])->map(fn ($f) => ['name' => $f[0], 'role' => $f[1]])->all(),
                'tags' => collect($data['tags'] ?? [])->map(fn ($t) => ['name' => $t])->all(),
                'caused_by_event_id' => isset($data['caused_by']) ? ($byTitle[$data['caused_by']] ?? null) : null,
            ];

            $event = $writer->create($payload, $admin, ChangeOrigin::Seed);

            $byTitle[$data['title']] = $event->id;
        }

        // 补一条显式因果关系，供一致性巡检演示「因果倒置」检测
        $cheng = $byTitle['切尔诺伯格事变爆发'] ?? null;
        $ruin = $byTitle['罗德岛撤离切尔诺伯格'] ?? null;

        if ($cheng && $ruin) {
            Event::whereKey($ruin)->update(['caused_by_event_id' => $cheng]);
        }
    }

    // ------------------------------------------------------------------ 《大地巡礼》

    /**
     * 《大地巡礼》（官方世界观设定集）承载的内容。
     *
     * 与主线 / 活动条目有本质差别：这本书的主体是**世界观机制与国家地区背景**，而不是编年史。
     * 它解释了「移动城市为什么存在」「天灾如何塑造政体」「各国政体如何运作」，
     * 却极少给出具体年份 —— 这正是这批条目全部落在「时间未定」泳道的原因，
     * 也是「时间未定必须是泳道而不是异常数据」这条设计的存在理由。
     *
     * 四点刻意的取舍（都在拒绝「看起来很完整的假数据」）：
     *
     *  1. **不编造年份。** 无法定位的一律 date_precision = unknown，进泳道等考据，
     *     而不是塞一个看起来合理的数字。
     *  2. **不编造引文。** quote 全部留空，出处页会如实显示「该出处未附引文」。
     *     编造引文会直接摧毁「引用可定位」这条校验的价值。
     *  3. **不编造正式国名。** 拿不准的 full_name 一律留空，宁可字段缺失也不要写错。
     *  4. **卷内条目不给纪元。** 世界卷的机制条目在当代之前即已成型，故归入「远古 · 前纪元」；
     *     国家与地区卷的政体条目成型时间无法定位，因此 era_id 留空，
     *     避免用「所属纪元」暗示一个不存在的年代结论。
     */
    private function seedTerraTourEvents(User $admin): void
    {
        $writer = app(EventWriter::class);

        $sourceId = Source::where('slug', 'terra-tour')->value('id');
        $eraIds = Era::pluck('id', 'slug');

        $events = [
            // ================= 世界卷：机制与世界观 =================
            [
                'title' => '「前文明」遗存的存在',
                'section' => '世界卷',
                'era' => 'prehistory',
                'summary' => '泰拉各地散布着无法归因于当代文明的遗迹与技术残片，学界普遍以「前文明」指称其创造者，其年代与覆灭原因均无定论。',
                'details' => '这类遗存既是遗迹探索的主要对象，也是「泰拉文明并非第一代」这一判断的核心依据。',
                'tags' => ['源石'],
            ],
            [
                'title' => '源石与天灾的共生关系',
                'section' => '世界卷',
                'era' => 'prehistory',
                'summary' => '天灾周期性地扫过泰拉，所过之处地表大面积源石结晶化；源石既是灾难的产物，也是当代文明赖以运转的资源。',
                'details' => '「天灾有规律」这件事直接塑造了泰拉的生存方式：定居点必须能够整体迁移。',
                'tags' => ['天灾', '源石'],
            ],
            [
                'title' => '移动城市技术的形成',
                'section' => '世界卷',
                'era' => 'prehistory',
                'summary' => '为躲避周期性天灾，泰拉各文明发展出可整体迁移的移动城市，城市本身成为最重要的战略资产。',
                'details' => '移动城市是理解本作地缘政治的前提：城市可以被「开走」，因此疆域、边境与主权的概念都与现实世界不同。',
                'tags' => ['天灾'],
            ],
            [
                'title' => '源石技艺的体系化分类',
                'section' => '世界卷',
                'era' => 'prehistory',
                'summary' => '源石技艺被系统化为若干流派与学科，并逐步划清「可传授的技艺」与「个体天赋」之间的边界。',
                'tags' => ['源石'],
            ],
            [
                'title' => '种族格局与源石适应性',
                'section' => '世界卷',
                'era' => 'prehistory',
                'summary' => '泰拉现存数十个种族，其分布与对源石环境的适应性存在相关性，并深刻影响各国的社会结构与阶层。',
                'details' => '种族差异与社会分层的关系，是理解各国对感染者政策为何分歧的背景。',
                'tags' => ['感染者'],
            ],

            // ================= 国家与地区卷：政体与背景 =================
            [
                'title' => '乌萨斯帝国的军事化体制',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '乌萨斯以军事力量为国家组织的核心，皇帝与军事委员会共同构成权力中枢，对外扩张与对内高压互为支撑。',
                'factions' => [['乌萨斯帝国', 'instigator']],
                'tags' => ['政权更迭'],
            ],
            [
                'title' => '维多利亚的君主制危机',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '维多利亚王权长期受贵族与议会势力牵制，围绕继承权与主权的矛盾为后来的内战埋下伏笔。',
                'details' => '理解这一结构性矛盾，才能理解伦蒂尼姆为何会成为各方争夺的核心。',
                'factions' => [['维多利亚', 'involved']],
                'tags' => ['政权更迭'],
            ],
            [
                'title' => '莱塔尼亚的双王共治',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '莱塔尼亚由两位君主共同统治，术师传统与国家权力深度绑定，形成独特的双头政体。',
                'factions' => [['莱塔尼亚', 'involved']],
            ],
            [
                'title' => '叙拉古的家族体制',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '叙拉古的秩序由各家族之间的势力平衡维持，中央权威薄弱，暴力与交易长期并行。',
                'factions' => [['叙拉古', 'involved']],
                'characters' => [['拉普兰德', 'support'], ['德克萨斯', 'support']],
            ],
            [
                'title' => '拉特兰的教权体系',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '拉特兰以宗教权威为核心组织社会，其教义与仪式体系对周边地区具有长期影响力。',
                'factions' => [['拉特兰', 'involved']],
            ],
            [
                'title' => '谢拉格的宗教权威与喀兰贸易',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '谢拉格以圣山信仰维系内部秩序，喀兰贸易则承担对外通商职能，宗教与商业势力长期共生又相互牵制。',
                'factions' => [['谢拉格', 'involved'], ['喀兰贸易', 'involved']],
                'characters' => [['银灰', 'support'], ['初雪', 'support']],
            ],
            [
                'title' => '卡西米尔骑士竞技制度的确立',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '卡西米尔将骑士竞技制度化、商业化，骑士头衔与资本、舆论之间形成复杂的交换关系。',
                'factions' => [['卡西米尔', 'involved']],
                'characters' => [['耀骑士临光', 'support'], ['玛莉娅·临光', 'support']],
                'tags' => ['骑士竞技'],
            ],
            [
                'title' => '米诺斯的城邦传统',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '米诺斯由多个城邦构成，古老的信仰与竞技传统长期延续，对外部世界的介入保持警惕。',
                'factions' => [['米诺斯', 'involved']],
                'characters' => [['帕拉斯', 'support']],
            ],
            [
                'title' => '哥伦比亚脱离维多利亚',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '哥伦比亚原为维多利亚的殖民地，取得独立后迅速工业化，成为研究机构与资本集中的新兴力量。',
                'details' => '莱茵生命等大型研究机构以哥伦比亚为基地，其研究伦理争议成为后续事件的核心矛盾之一。',
                'factions' => [['哥伦比亚', 'involved'], ['维多利亚', 'involved'], ['莱茵生命', 'involved']],
                'tags' => ['政权更迭'],
            ],
            [
                'title' => '雷姆必拓的矿业体系',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '雷姆必拓以矿业与资源贸易立身，其企业化政体使商业利益直接构成对外政策本身。',
                'factions' => [['雷姆必拓', 'involved']],
                'tags' => ['源石'],
            ],
            [
                'title' => '玻利瓦尔的长期战乱',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '玻利瓦尔长期处于内乱与外部势力干涉之中，政权更迭频繁，平民承受了主要代价。',
                'factions' => [['玻利瓦尔', 'victim']],
                'tags' => ['战争'],
            ],
            [
                'title' => '各国感染者政策的分歧',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '各国对感染者的处置政策差异极大，从隔离、驱逐到有限纳编不等，构成跨地区冲突的长期结构性根源。',
                'details' => '这一分歧是整合运动得以在多个地区获得响应的前提条件。',
                'factions' => [['乌萨斯帝国', 'instigator'], ['龙门', 'involved'], ['罗德岛', 'involved']],
                'tags' => ['感染者'],
            ],
        ];

        foreach ($events as $row) {
            $writer->create([
                'title' => $row['title'],
                'summary' => $row['summary'],
                'details' => $row['details'] ?? null,
                'date_display' => self::UNDATED_DATE,
                'date_confidence' => 'unknown',
                'era_id' => $row['era'] ? ($eraIds[$row['era']] ?? null) : null,
                'status' => 'needs_review',
                'sources' => [[
                    'id' => $sourceId,
                    'chapter' => $row['section'],
                    // 故意留空：见方法注释第 2 条
                    'quote' => null,
                    'is_primary' => true,
                ]],
                'factions' => collect($row['factions'] ?? [])
                    ->map(fn (array $f) => ['name' => $f[0], 'role' => $f[1]])->all(),
                'characters' => collect($row['characters'] ?? [])
                    ->map(fn (array $c) => ['name' => $c[0], 'role' => $c[1]])->all(),
                'tags' => collect($row['tags'] ?? [])
                    ->map(fn (string $t) => ['name' => $t])->all(),
            ], $admin, ChangeOrigin::Seed);
        }
    }

    // ------------------------------------------------------------------ AI 提案演示

    /**
     * 用预置语料真的跑一次 AI 梳理，让审核台开箱就有内容。
     *
     * 这里刻意使用默认驱动（未配置密钥时是离线规则抽取），因此不依赖任何外部服务。
     */
    private function seedProposalsFromCorpus(): void
    {
        $source = Source::where('slug', 'setting-chronicle')->first();

        if (! $source || blank($source->raw_text)) {
            return;
        }

        $actor = User::where('email', 'reviewer@terra.local')->first();

        if (! $actor) {
            return;
        }

        try {
            app(AiEventSynthesizer::class)->synthesize(
                source: $source,
                rawText: $source->raw_text,
                actor: $actor,
                era: null,
                instruction: '优先输出官方年表中明确给出年份的事件。',
            );
        } catch (\Throwable $e) {
            // 示例数据生成失败不应该阻断整个 seed
            $this->command?->warn('AI 提案示例生成失败：'.$e->getMessage());
        }
    }
}
