<?php

namespace Database\Seeders;

use App\Enums\ChangeOrigin;
use App\Enums\EventStatus;
use App\Enums\IdentityProvider;
use App\Enums\SourceType;
use App\Enums\World;
use App\Models\Character;
use App\Models\Era;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Source;
use App\Models\Tag;
use App\Models\User;
use App\Services\Ai\AiEventSynthesizer;
use App\Services\EventWriter;
use App\Services\Identity\ExternalProfile;
use App\Services\Identity\IdentityManager;
use App\Services\UserManager;
use App\Support\CorpusLocator;
use App\Support\TerraDate;
use App\Support\TerraTourCorpus;
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
 *   2. 《大地巡旅》条目        ——  世界观机制与国家背景，**年份普遍未载**，
 *                                  因此统一落在「时间未定」泳道，见 seedTerraTourEvents()；
 *   3. 示例语料与 AI 提案      ——  演示完整的梳理链路。
 *
 * 换句话说：这份数据本身就是产品要解决的问题的样本 —— 它需要被考据者继续纠错。
 */
class TimelineSeeder extends Seeder
{
    /** 《大地巡旅》条目的统一时间占位：明确「年份未载」，而不是伪造一个年份。 */
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
        // 塔卫二与泰拉的条目分别灌入，纪元、出处与索引各自成体系
        $this->seedTalosEvents($admin);
        $this->seedProposalsFromCorpus();
    }

    // ------------------------------------------------------------------ 账号

    private function seedUsers(): User
    {
        /*
         * 登录名（handle）用 ASCII，昵称用中文 —— 刻意让两者不一样，
         * 这样「昵称与登录名分离」在演示数据里就是可见的，
         * 而不需要读代码才知道它们是两个字段。
         */
        $accounts = [
            ['handle' => 'archivist', 'nickname' => '档案管理员', 'email' => 'admin@terra.local', 'role' => 'admin', 'password' => 'terra-admin'],
            ['handle' => 'reviewer', 'nickname' => '考据审核员', 'email' => 'reviewer@terra.local', 'role' => 'reviewer', 'password' => 'terra-reviewer'],
            ['handle' => 'editor', 'nickname' => '条目编辑者', 'email' => 'editor@terra.local', 'role' => 'editor', 'password' => 'terra-editor'],
            ['handle' => 'reader', 'nickname' => '访客读者', 'email' => 'viewer@terra.local', 'role' => 'viewer', 'password' => 'terra-viewer'],
            // 停用示例：账号列表、状态筛选与「已禁用」徽章都需要至少一条真实数据才有意义
            ['handle' => 'suspended', 'nickname' => '停用示例账号', 'email' => 'disabled@terra.local', 'role' => 'editor', 'password' => 'terra-disabled'],
        ];

        $admin = null;
        $created = [];

        foreach ($accounts as $account) {
            // withTrashed + 清空 deleted_at：让 seed 可重复执行
            // （软删除的账号仍占用 email / name / nickname 三个唯一索引，
            //   否则二次 seed 会撞唯一键）
            $user = User::withTrashed()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['handle'],
                    'nickname' => $account['nickname'],
                    'password' => $account['password'],
                    // 演示账号的密码都写在 README 里，属于「本人可知」
                    'password_set_at' => now(),
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

        /*
         * 外部身份绑定：一条「已核验」+ 一条「待核验」。
         *
         * 两条都走 IdentityManager 而不是直接插表，理由与上面一致 ——
         * 演示数据必须由生产代码路径生成，否则界面上的状态
         * （尤其是「待核验」这种需要人工介入的状态）会与实际逻辑脱节。
         */
        $identities = app(IdentityManager::class);

        $identities->link(
            $users['editor@terra.local'],
            new ExternalProfile(IdentityProvider::Hypergryph, '7788990', '条目编辑者'),
            verified: true,
            actor: $admin,
        );

        $identities->claimManually($users['reviewer@terra.local'], IdentityProvider::Hypergryph, '1234567');
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

            // 《大地巡旅》「国家与地区」卷覆盖、但此前未进入检索维度的政体。
            // 只填在有把握的字段上：不做正式国名的推测，拿不准的一律留空。
            ['name' => '莱塔尼亚', 'color' => '#818cf8', 'description' => '以双王共治体制与术师传统著称的政体。'],
            ['name' => '米诺斯', 'color' => '#2dd4bf', 'description' => '由多个城邦构成的地区，保有古老的信仰与竞技传统。'],
            ['name' => '玻利瓦尔', 'color' => '#fb7185', 'description' => '长期陷入内乱与外部势力干涉的地区。'],
            ['name' => '雷姆必拓', 'color' => '#a3a3a3', 'description' => '以矿业与资源贸易立身的企业化政体。'],
            ['name' => '萨米', 'color' => '#bae6fd', 'description' => '泰拉北境雪原地区，以部族与萨满信仰为组织形态。'],
            ['name' => '阿戈尔', 'color' => '#0891b2', 'description' => '与深海威胁直接相关的海洋文明。'],

            /*
             * ---- 塔卫二（《明日方舟：终末地》）----
             *
             * 阵营词典是**跨世界共享**的，刻意没有 world 列：罗德岛这类组织
             * 同时存在于两个世界的历史里（终末地工业由罗德岛等协同组建），
             * 强行按世界切分反而会把同一个组织拆成两份。
             */
            [
                'name' => '终末地工业',
                'full_name' => '终末地工业 · Endfield Industries',
                'color' => '#57c7d4',
                'description' => '塔卫二上最大的技术承包商，由罗德岛制药公司与其他合作方协同组建，'
                    .'承担开拓、协议回收与重建工业体系的使命。总部为轨道飞行器「帝江号」。',
                'children' => [
                    [
                        'name' => '协议回收部门',
                        'color' => '#6fc3d4',
                        'description' => '管理员直接带领的一线部门，负责回收塔卫二上失落的「协议」。',
                    ],
                ],
            ],
            ['name' => '联盟工团', 'color' => '#8a9aa8', 'description' => '塔卫二上的生产与开拓组织，四号谷地最初由其选定为生产开拓区域。'],
            ['name' => '天使', 'color' => '#e2e8f0', 'description' => '塔卫二的主要敌对势力，第一次与第二次天使战争均与其进犯有关。'],
            ['name' => '裂地者', 'color' => '#f87171', 'description' => '雅各布·迈森手下的匪帮，后被文明环带摧毁。社区资料中亦写作「掠地者」，两种写法并存。'],
            ['name' => '文明环带', 'color' => '#7dd3fc', 'description' => '塔卫二上的人类聚居带，四号谷地位于其边缘地区。'],
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
                    'world' => ($era['world'] ?? World::Terra)->value,
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

        $this->seedTalosEras();
    }

    /**
     * 塔卫二的纪元。
     *
     * 与泰拉的纪元**必须分开存储**，因为两套纪年的索引数值不可比较：
     * 泰拉的「远古 · 前纪元」覆盖 -186000 ~ 371627，而塔罗斯历 1-15 年的索引
     * 只有 372 ~ 5951 —— 落在里面。少了世界维度，纪元自动归属会把
     * 塔卫二的事件静默塞进泰拉的远古纪元。
     *
     * 划分依据是塔罗斯历的叙事阶段，区间端点取自社区整理中相对一致的年份；
     * 第二次天使战争的起始年社区未给出确切数字（只知道在 90 年之后），
     * 因此第三个纪元从 90 年起算，并在说明里如实标注这一点。
     */
    private function seedTalosEras(): void
    {
        $eras = [
            [
                'name' => '开拓与第一次天使战争',
                'slug' => 'talos-1-15',
                'subtitle' => '星门开启 · 人类南迁',
                'date_label' => '塔罗斯历 1 年 — 15 年',
                'start' => TerraDate::toIndex(1),
                'end' => TerraDate::toIndex(15, 12, 31),
                'color' => '#3f6b7a',
                'description' => '泰拉先民穿过星门抵达塔卫二「开拓」，随后与天使爆发第一次战争，'
                    .'前期人类失去北极地区并被迫南迁，后期重整势力完成抵抗。',
            ],
            [
                'name' => '战后重建与谷地开发',
                'slug' => 'talos-16-89',
                'subtitle' => '聚落成型 · 四号谷地',
                'date_label' => '塔罗斯历 16 年 — 89 年',
                'start' => TerraDate::toIndex(16),
                'end' => TerraDate::toIndex(89, 12, 31),
                'color' => '#5596a6',
                'description' => '第一次战争结束后的重建期：聚落建立、四号谷地开发、'
                    .'供能高地超域试验场落成。社区整理中的年份多为推断。',
            ],
            [
                'name' => '第二次天使战争与终末地重启',
                'slug' => 'talos-90-152',
                'subtitle' => '管理员苏醒',
                'date_label' => '塔罗斯历 90 年 — 152 年',
                'start' => TerraDate::toIndex(90),
                'end' => TerraDate::toIndex(152, 12, 31),
                'color' => '#6fc3d4',
                'description' => '该纪元起点取自 90 年（供能高地试验场建立），'
                    .'但第二次天使战争的确切起始年社区尚未给出 —— 这是一个待考的边界，'
                    .'不要把它当成已知事实。纪元终点为管理员在帝江号苏醒的 152 年。',
            ],
        ];

        foreach ($eras as $order => $era) {
            Era::updateOrCreate(
                ['slug' => $era['slug']],
                [
                    'name' => $era['name'],
                    'world' => World::Talos->value,
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
            ['博士', 'Doctor', '罗德岛', '未公开', '罗德岛的指挥官。主线开始时被罗德岛小队从切尔诺伯格地下设施的「石棺」中唤醒，此前的经历缺失。'],
            ['阿米娅', 'Amiya', '罗德岛', '卡特斯', '罗德岛的公开领袖。'],
            ['凯尔希', 'Kal\'tsit', '罗德岛', '菲林', '罗德岛的核心成员之一，与巴别塔时期的历史密切相关。'],
            ['特蕾西娅', 'Theresa', '巴别塔', '萨卡兹', '巴别塔的领导者（罗德岛的前身组织）。'],
            ['特雷西斯', 'Theresis', '卡兹戴尔', '萨卡兹', '卡兹戴尔一方的核心人物。'],
            ['塔露拉', 'Talulah', '整合运动', '德拉克', '整合运动的领袖，切尔诺伯格事变的发动方核心人物。'],
            ['爱国者', 'Patriot', '整合运动', '温迪戈', '整合运动的干部。'],
            ['霜星', 'Frostnova', '整合运动', '萨卡兹', '整合运动的干部。'],
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

            // 《大地巡旅》「国家与地区」卷涉及、此前缺失的关联人物。
            // 种族字段留空而不是靠印象填：拿不准的字段宁可缺失，也不要写错。
            ['帕拉斯', 'Pallas', '米诺斯', null],
            ['斯卡蒂', 'Skadi', '阿戈尔', null, '阿戈尔出身，与深海威胁相关的干员。'],
            ['幽灵鲨', 'Specter', '阿戈尔', null],
            ['歌蕾蒂娅', 'Gladiia', '阿戈尔', null],

            // ---- 塔卫二（《明日方舟：终末地》）----
            // 代号与种族一律留空：社区资料给的是中文名与转写，没有可靠出处，
            // 按本文件既有原则「拿不准的字段宁可缺失，也不要写错」处理。
            // 管理员与佩丽卡的英文写法在社区里就有多种转写，同样不猜。
            ['管理员', null, '终末地工业', null, '终末地工业协议回收部门的负责人，塔罗斯历 152 年于帝江号苏醒时失去记忆。', null, 'talos'],
            ['佩丽卡', null, '终末地工业', null, '终末地工业的技术监督。', null, 'talos'],
            ['陈千语', null, '终末地工业', null, '终末地工业的特勤干员。', null, 'talos'],
            ['阿伯莉', null, '联盟工团', null, '四号谷地遇袭时牺牲。', null, 'talos'],
        ];

        foreach ($characters as $order => $row) {
            [$name, $codename, $faction, $race] = $row;

            /*
             * 第 5 项是简介，第 6 项是该世界维基页面名覆盖，第 7 项是所属世界 —— 都可选。
             *
             * 用普通取值而不是在 foreach 的解构里写默认值：后者在 PHP 里是**编译错误**
             * （Assignments can only happen to writable values），
             * 默认值语法只在普通赋值里成立。
             */
            $profile = $row[4] ?? null;
            $wikiSlug = $row[5] ?? null;
            $world = $row[6] ?? 'terra';

            Character::updateOrCreate(
                ['slug' => 'chr-'.md5($name)],
                [
                    'name' => $name,
                    'codename' => $codename,
                    'world' => $world,
                    'faction_id' => $factionSlugs[$faction] ?? null,
                    'race' => $race,
                    'description' => $profile,
                    'wiki_slug' => $wikiSlug,
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
            // ---- 塔卫二 ----
            // 标签与阵营一样是跨世界的横切概念，不做世界隔离
            ['星门', '#7dd3fc'], ['协议', '#57c7d4'], ['开拓', '#a3e635'],
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
            ['《大地巡旅》', 'terra-tour', SourceType::Artbook, '官方世界观设定集', '世界卷 / 国家与地区卷 / 泰拉纪年', 48],

            // ---- 世界观设定 ----
            ['世界观设定 · 泰拉纪年表', 'setting-chronicle', SourceType::Setting, null, '泰拉编年', 60],
            ['世界观设定 · 源石与天灾', 'setting-originium', SourceType::Setting, null, '源石学', 61],
        ];

        /*
         * 第 7 项是世界，缺省泰拉 —— 既有条目保持 6 元组写法不必改动。
         *
         * 这里刻意写成先解构 6 项、再单独取第 7 项，而不是在 foreach 的解构里写默认值：
         * `foreach ($rows as [$a, $b = 'x'])` 在 PHP 里是**编译期错误**
         * （Assignments can only happen to writable values），
         * 而 list() 的默认值语法只在普通赋值里成立。
         */
        foreach ($sources as $row) {
            [$name, $slug, $type, $code, $chapter, $order] = $row;
            $world = $row[6] ?? World::Terra;

            Source::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'world' => ($world instanceof World ? $world : World::Terra)->value,
                    'type' => $type->value,
                    'code' => $code,
                    'chapter' => $chapter,
                    'release_order' => $order,
                    'description' => $type->label().' · 用于为事件条目提供可溯源的出处。',
                ],
            );
        }

        /*
         * ---- 塔卫二的出处 ----
         *
         * 两份都不预置 raw_text，理由与《大地巡旅》完全相同：
         * 游戏内文本尚未录入，而编造引文会直接摧毁「引用可定位」的价值。
         *
         * 关键在于**把二级来源标出来**：塔卫二的时间结论目前来自玩家社区整理，
         * 不是官方原文。把它当作官方出处，等于给整条时间线盖了一个假的确认章。
         */
        Source::updateOrCreate(['slug' => 'talos-canon'], [
            'name' => '《明日方舟：终末地》游戏内文本',
            'world' => World::Talos->value,
            'type' => SourceType::Setting->value,
            'code' => 'ZMD',
            'chapter' => '待录入',
            'release_order' => 200,
            'description' => '塔卫二的官方资料入口（世界观、剧情与游戏内文本）。'
                .'原文尚未逐条录入，因此引用一律留空 —— 录入后可走「开始梳理」补全。',
        ]);

        Source::updateOrCreate(['slug' => 'talos-survey'], [
            'name' => '塔卫二年表（社区考据整理）',
            'world' => World::Talos->value,
            'type' => SourceType::Other->value,
            'code' => 'SURVEY',
            'chapter' => '社区整理',
            'release_order' => 201,
            'description' => '玩家社区汇总的塔罗斯历年表（NGA / 贴吧 / 哔哩哔哩等）。
                **这是二级来源，不是官方文本**：本仓库中塔卫二条目的年份与可信度
                均以它为依据，因此那些条目一律标记为「推断」并等待用游戏内原文重新核验。',
        ]);

        // 《大地巡旅》的语料只收**被逐字引用的那一卷**（书末附录「泰拉纪年」）。
        // 不预置整本书有两个硬理由：版权边界（仓库只收引用所需的片段）与容量 ——
        // raw_text 是 MySQL TEXT（64 KB），而整书 1.16 MB 会被**静默截断**，
        // 那时引文偏移全部错位，比不存更危险。
        // 原文文件不入库（见 .gitignore）；本地缺失时这一栏留空，seed 照常跑完。
        $tourCorpus = TerraTourCorpus::excerpt();

        Source::where('slug', 'terra-tour')->update([
            'raw_text' => $tourCorpus['text'] ?? null,
            'description' => $tourCorpus === null
                ? '官方世界观设定集。系统收录其中的世界观机制与国家／地区背景条目；'
                    .'章节定位为粗粒度标注（世界卷 / 国家与地区卷）。'
                : sprintf(
                    '官方世界观设定集。系统收录其中的世界观机制与国家／地区背景条目；'
                    .'原文语料为书末附录「泰拉纪年」节录（全书第 %d–%d 行），'
                    .'引文的字符偏移与行号均相对该节录计算。',
                    $tourCorpus['from_line'],
                    $tourCorpus['to_line'],
                ),
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
泰拉历1100年，维多利亚内战全面爆发，伦蒂尼姆成为各方争夺的核心。
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

    // ------------------------------------------------------------------ 引文定位

    /**
     * 有语料的出处 → 定位器。
     *
     * 一次性构建：定位器要对全文做一次索引，逐条引文各建一个会重复做几十遍。
     *
     * @return array<string, CorpusLocator>
     */
    private function sourceLocators(): array
    {
        $locators = [];

        foreach (Source::whereNotNull('raw_text')->get(['slug', 'raw_text']) as $source) {
            $locators[$source->slug] = CorpusLocator::forText((string) $source->raw_text);
        }

        return $locators;
    }

    /**
     * 出处简写 → pivot 行。
     *
     * 引文的字符偏移与行号**在这里现算**，而不是手写：它们必须与 sources.raw_text
     * 严格一致，手抄的数字迟早漂移，而漂移过的定位比「没有定位」更误导人。
     * 算不出来时（出处没有语料、或引文不是逐字抄的）偏移留空、行号置 0 ——
     * 出处页会如实显示「未定位」，这本身就是一条该被看见的待办。
     *
     * @param  array<int, mixed>  $spec  [slug, 关卡号, 引文, 章节]
     * @param  array<string, CorpusLocator>  $locators
     * @return array<string, mixed>
     */
    private function citationRow(array $spec, array $locators): array
    {
        $slug = (string) ($spec[0] ?? '');
        $quote = $spec[2] ?? null;
        $chapter = $spec[3] ?? null;

        $hit = filled($quote) ? ($locators[$slug] ?? null)?->locate((string) $quote) : null;

        return [
            'id' => Source::where('slug', $slug)->value('id'),
            'stage_code' => $spec[1] ?? null,
            'quote' => $quote,
            'chapter' => $chapter,
            'quote_offset' => $hit?->charOffset,
            'source_line' => $hit?->line ?? 0,
            // 年表以 [] 标注凯尔希的补充条目（原书有注）。这层语义此前只能混在
            // 引文文本与详述里，既不可检索、也无法在界面上区分。
            'is_annotation' => str_starts_with(ltrim((string) $quote), '['),
            // 给了章节说明该出处就是条目的主要来源 —— 与 seedTerraTourEvents 的处理一致
            'is_primary' => $chapter !== null,
        ];
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
                // 引文一律逐字取自语料（宁可短到句子中途，也不把原文的「，」改写成「。」）——
                // 「出处可定位」是硬闸门，而闸门只认逐字一致。
                'sources' => [['setting-chronicle', null, '泰拉历前500年，源石首次被系统性地记录与利用'], ['setting-originium', null, null]],
                'factions' => [['萨尔贡', 'involved']],
                'tags' => ['源石'],
            ],

            // ===== 《大地巡旅》年表：797 – 999（附录「泰拉纪年」） =====
            /*
             * 《大地巡旅》书末附有官方年表，作者声明仅收录「明确存在的大事件」——
             * 这是全书里唯一成体系的**带年份**材料，因此这批条目敢标 confirmed/verified，
             * 且引文逐字取自年表原文（年表注：[] 为凯尔希补充）。
             */
            [
                'title' => '七城联邦建成第一座现代移动城市',
                'date' => '泰拉历797年',
                'era' => 'prehistory',
                'confidence' => 'confirmed',
                'summary' => '七城联邦建成泰拉历史上第一座现代移动城市，「整体迁移以躲避天灾」自此成为泰拉城市的标准形态。',
                'location' => null,
                'sources' => [['terra-tour', null, '797 七城联邦建成泰拉历史上第一座现代移动城市', '泰拉纪年']],
                'tags' => ['天灾'],
                'status' => 'verified',
            ],
            [
                'title' => '炎国首次派出驻外信使',
                'date' => '泰拉历845年',
                'era' => 'prehistory',
                'confidence' => 'confirmed',
                'summary' => '炎国首次向泰拉各国派出信使进行外交活动，开启了泰拉诸国交往的新局面。',
                'location' => null,
                'sources' => [['terra-tour', null, '845 炎国首次向泰拉各国派出信使进行外交活动，开启了泰拉诸国交往的新局面', '泰拉纪年']],
                'factions' => [['炎国', 'instigator']],
                'tags' => ['外交'],
                'status' => 'verified',
            ],
            [
                'title' => '玻利瓦尔主导权开始脱离伊比利亚',
                'date' => '泰拉历885年',
                'era' => 'prehistory',
                'confidence' => 'confirmed',
                'summary' => '玻利瓦尔的主导权开始从伊比利亚向外转移，这一进程最终导向玻利瓦尔的独立建国。',
                'location' => null,
                'sources' => [['terra-tour', null, '885 玻利瓦尔主导权开始从伊比利亚向外转移', '泰拉纪年']],
                'factions' => [['伊比利亚', 'involved'], ['玻利瓦尔', 'involved']],
                'status' => 'verified',
            ],
            [
                'title' => '莱塔尼亚控制下的玻利瓦尔国成立',
                'date' => '泰拉历897年',
                'era' => 'prehistory',
                'confidence' => 'confirmed',
                'summary' => '莱塔尼亚控制的玻利瓦尔国成立，玻利瓦尔进入被外国势力主导的时期。',
                'details' => "莱塔尼亚卷载：泰拉历 897 年，莱塔尼亚趁玻利瓦尔深陷内战泥潭之机悍然发兵，在那里建立了傀儡政权，以期实现对玻利瓦尔的实际控制。这是帝国数度主动引发军事冲突的一例。\n\n背景据《大地巡旅》莱塔尼亚卷。",
                'location' => null,
                'sources' => [['terra-tour', null, '897 莱塔尼亚控制的玻利瓦尔国成立', '泰拉纪年']],
                'factions' => [['莱塔尼亚', 'instigator'], ['玻利瓦尔', 'victim']],
                'tags' => ['政权更迭'],
                'status' => 'verified',
            ],
            [
                'title' => '三国联军进攻卡兹戴尔失败',
                'date' => '泰拉历898年',
                'era' => 'prehistory',
                'confidence' => 'confirmed',
                'summary' => '三国联军进攻卡兹戴尔失败，卡兹戴尔战争议会随后成立。',
                'details' => '年表未载明「三国」具体所指，不臆测。',
                'location' => '卡兹戴尔',
                'sources' => [['terra-tour', null, '898 三国联军进攻卡兹戴尔失败，卡兹戴尔战争议会成立', '泰拉纪年']],
                'factions' => [['卡兹戴尔', 'victim']],
                'tags' => ['战争'],
                'status' => 'verified',
            ],
            [
                'title' => '伊比利亚人与「岛民」相遇',
                'date' => '泰拉历913年',
                'era' => 'prehistory',
                'confidence' => 'confirmed',
                'summary' => '伊比利亚人与来自海洋的「岛民」相遇，两大文明的接触自此开始。',
                'details' => "伊比利亚卷载：泰拉历 913 年，伊比利亚迎来第一批来自海洋的移民——被称为「岛民」的阿戈尔人，他们希望在这片土地上定居。刚上岸的岛民不知伊比利亚的宫廷礼节，面对赤金铸成的王座，他们既不卑躬屈膝，也无花言巧语，但他们携带的艺术与技术产物令伊比利亚人惊叹不已：当看到晶莹的细流在精巧的罗盘表面规律地流动、指示出整片海域的洋流走向，伊比利亚的国王燃起了前所未有的野心。\n\n「岛民」即阿戈尔人；这次相遇为伊比利亚随后的黄金时代埋下伏笔。\n\n背景据《大地巡旅》伊比利亚卷。",
                'location' => '伊比利亚沿海',
                'sources' => [['terra-tour', null, '913 伊比利亚人与“岛民” 相遇', '泰拉纪年']],
                'factions' => [['伊比利亚', 'involved'], ['阿戈尔', 'involved']],
                'tags' => ['外交'],
                'status' => 'verified',
            ],
            [
                'title' => '伊比利亚进入黄金时代',
                'date' => '泰拉历930年',
                'era' => 'prehistory',
                'confidence' => 'confirmed',
                'summary' => '伊比利亚走向黄金时代，成为「第二个将土地与城塞喻作黄金的国家」。',
                'details' => "伊比利亚卷载：岛民上岸之后，伊比利亚成就了前所未有的繁荣，如今的伊比利亚人大多将这段岁月称作「黄金时代」。以首席船舶设计师布雷奥甘为代表，许多阿戈尔岛民对来自海洋的威胁抱有忌惮，尝试在灾难来临前做好准备，其努力极大地推动了伊比利亚的经济与技术发展；王室与贵族则总能找到方法，把岛民的科研与技术成果用于攫取财富和权力——黄金舰队竣工后并未驶入南方的开阔海域行使科考职能，而是浩浩荡荡穿过海峡，在维多利亚公爵与萨尔贡帕夏惊异的目光中沿着内海的海岸巡航。\n\n黄金时代同时也是一个认同焦虑的时代：到十一世纪初，越来越多的人抛下世代相传的财产与家业，盲目踏入全然未知的领域，寻觅配得上「黄金时代精神」的新异道路；踏实与本分反被视为失败者与落后者的特质，底层劳动者走上街头会听到斥责他们不思进取的声音。岛民的技术创造的过剩生产力，短暂地掩饰了社会上的许多裂痕。\n\n背景据《大地巡旅》伊比利亚卷。",
                'location' => '伊比利亚',
                'sources' => [['terra-tour', null, '930 伊比利亚走向黄金时代', '泰拉纪年']],
                'factions' => [['伊比利亚', 'involved']],
                'status' => 'verified',
            ],
            [
                'title' => '巫王赫尔昏佐伦即位',
                'date' => '泰拉历969年',
                'era' => 'prehistory',
                'confidence' => 'confirmed',
                'summary' => '莱塔尼亚巫王赫尔昏佐伦即位，莱塔尼亚进入巫王时代。',
                'details' => "背景：莱塔尼亚卷载，赫尔昏佐伦被推举登基时正值内忧外患——叙拉古的独立运动在 967 年爆发并波及九个大区，一时间国内叛乱四起；选帝侯们纷纷以镇压独立运动为由索要更多资源与政治倾斜，实则借机壮大自身实力，古老的帝国濒临崩溃。\n\n经过：他先以雷霆手腕将长期与帝国离心离德的叙拉古切割出去、承认其独立，随后以镇压国内叛乱为由，把选帝侯们的军队与一部分权力收归帝国宫廷，莱塔尼亚皇帝由此在历史上首次获得超越选帝侯的实权。此后他大刀阔斧改革，在保证全域文化一致的前提下，教育与经济急速壮大，帝国在他带领下蓬勃发展。\n\n转折：改革招致贵族日益增长的不满，他以强硬手段对付异见者，冲突与对抗不断升级，原先镇压叛乱的亲兵被用作密探和特务，国内大肆搜捕甚至直接屠杀反对分子，许多骇人听闻的惨案即在此时发生。他把自己关进高塔，越来越少以统治者的身份出现在大众面前，诡异的做派反而让追随者愈发崇拜、让其他人愈发畏惧，「巫王」这一名号逐渐取代了本名。其统治自 969 年起延续百余年，直至 1077 年九月起义。\n\n背景与经过据《大地巡旅》莱塔尼亚卷。",
                'location' => '莱塔尼亚',
                'sources' => [['terra-tour', null, '969 莱塔尼亚巫王赫尔昏佐伦即位', '泰拉纪年']],
                'factions' => [['莱塔尼亚', 'involved']],
                'status' => 'verified',
            ],
            [
                'title' => '叙拉古脱离莱塔尼亚',
                'date' => '泰拉历969年',
                'era' => 'prehistory',
                'confidence' => 'confirmed',
                'summary' => '叙拉古正式脱离莱塔尼亚。',
                'details' => "背景：莱塔尼亚立国时由九大部落与叙拉古地区共同缔结《金律乐章》，叙拉古以自治领身份入盟；受制于地理与政治条件，其文化与其余九区差异极大。967 年，叙拉古爆发声势浩大的独立运动，分离思潮随后波及另外九个大区，帝国一度濒临崩溃。\n\n经过：969 年巫王赫尔昏佐伦登基后，选择将这块长期与帝国离心离德之地切割、承认其独立。书中特别提到，《金律乐章》在莱塔尼亚漫长历史上几乎未曾有过变动，唯独一次便是巫王在登基时删除了有关叙拉古自治领的内容。\n\n背景与经过据《大地巡旅》莱塔尼亚卷。",
                'location' => '叙拉古',
                // 引文逐字取自年表。注意这一行在原文里是 969 条目的**续行**（本身不带年份）：
                // 原先给它补写「969」前缀看起来更整齐，却让引文不再是逐字抄录的 ——
                // 「出处可定位」校验正是靠这种差别工作的，因此宁可保留原文形态。
                'sources' => [['terra-tour', null, '叙拉古正式脱离莱塔尼亚', '泰拉纪年']],
                'factions' => [['叙拉古', 'protagonist'], ['莱塔尼亚', 'involved']],
                'tags' => ['政权更迭'],
                'status' => 'verified',
            ],
            [
                'title' => '维多利亚发现并命名哥伦比亚',
                'date' => '泰拉历990年',
                'era' => 'prehistory',
                'confidence' => 'confirmed',
                'summary' => '一片全新的地区由维多利亚首次发现，并被命名为「哥伦比亚」。',
                'location' => '哥伦比亚',
                'sources' => [['terra-tour', null, '990 一片全新的地区由维多利亚首次发现，并被命名为“哥伦比亚”', '泰拉纪年']],
                'factions' => [['维多利亚', 'instigator'], ['哥伦比亚', 'involved']],
                'status' => 'verified',
            ],
            [
                'title' => '大静谧（伊比利亚）',
                'date' => '泰拉历1038年',
                'era' => 'era-1000-1093',
                'confidence' => 'inferred',
                'summary' => '伊比利亚沿海爆发被后世称为「大静谧」的灾难，海洋文明体系随之崩解，伊比利亚自此长期封闭。',
                'details' => "伊比利亚卷载：来自海洋的灾难摧毁了伊比利亚的辉煌——黄金舰队悄无声息地消失在海上，伊比利亚之眼接二连三地熄灭，王宫中的笙歌与码头上的喧嚣失去了声音，人们在绝对的寂静中奋力翕动着嘴唇。灾难就在这样的静谧中降临，此后发生的一切无人能完整地讲述。\n\n后果：这场灾祸使伊比利亚的大半国土沉入海底，海水退去后残存的陆地支离破碎，曾经丰沃的耕地成了不毛的盐碱地；随着土地消失，庄园制的经济基础与采邑制的政治基础一并土崩瓦解，王室与贵族不再有维系军队的资源，也不再有供农户耕种的田地。饥荒与暴乱使伊比利亚分崩离析，幸存者逃往北方，蜷缩在曾经偏僻的内陆边境。\n\n灾后重建：大主教卡门多次回绝了将他立为教宗的提议，主持九名大主教抛却原本姓氏、加封圣徒之名，并将国教会全面改组为「伊比利亚审判庭」——它不仅是宗教组织，也是统治机关；将四分五裂的伊比利亚重新统合为一个国家的，正是审判庭。\n\n灾难的具体成因在设定中始终缺乏统一解释，存在多种互相矛盾的假说。背景与后果据《大地巡旅》伊比利亚卷。",
                'location' => '伊比利亚',
                'sources' => [['setting-chronicle', null, '泰拉历1038年，伊比利亚沿海爆发被后世称为「大静谧」的灾难'], ['artbook-2', null, null]],
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
                'details' => "背景：乌萨斯卷载，帝国在西拓的过程中遭遇了强大的对手卡西米尔——两国有着相似的起源，但卡西米尔拥有更精锐的骑士团。彼时乌萨斯征募兵勇的方式相当简易，配发的武器装备比较落后，专业军事训练更是无从谈起；国内又仍采用骏鹰留下的古老制度，从国内送往前线的补给严重不足，这两方面的因素导致了乌萨斯在第一次乌卡战争中的失利。\n\n影响：这次惨败促成了乌萨斯的第一次军事改革，议会、省级行政区、法律等也因此得以确立，乌萨斯的战争机器获得了前所未有的活力，并在此后千百年中数度向卡西米尔发起挑战——乌萨斯卷称，军事上的胜败都不能让帝国停下对外征战的脚步，只会让帝国变本加厉地往战争的方向奔去。\n\n背景据《大地巡旅》乌萨斯卷「帝国征服史」节。",
                'sources' => [['setting-chronicle', null, '泰拉历1063年，乌萨斯帝国与卡西米尔就边境领地的归属爆发长期战争'], ['artbook-1', null, null]],
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
                'details' => "历史背景：卡兹戴尔卷载，萨卡兹的历史是一部流亡史——第一座卡兹戴尔在约前 8000 年陷落于神民与先民的旷古之战，「提卡兹」（拥有家园之人）自此成为「萨卡兹」（丧家之人），流亡时代持续两千年，直至游侠王奎隆册封诸王庭之主。此后的魔王更迭多伴以弑杀：「砌城匠」戈渎在哀愁之地上为新卡兹戴尔砌下第一处地基、被尊为魔王，却因拒绝发动战争而被挚友「焕日者」霸迩萨削去双臂、溺毙水中，城市一度焚毁；而「青色怒火」奎隆追杀叛友的复仇记，甚至被莱塔尼亚剧作家改编为选帝侯之子的故事，在崔林特尔梅大受追捧。\n\n影响：内战持续多年，是理解罗德岛前身「巴别塔」与萨卡兹流亡史的关键背景。据卡兹戴尔卷的课堂讲述，直到特蕾西娅殿下成为魔王，诸王庭才在她的敕令下拆解旧卡兹戴尔城的废墟，兴建卡兹戴尔第一与唯一的一座移动城市；而 1086 年的战争中，萨卡兹的流动学校毁于炮火，师生带着课本在战地流亡。\n\n背景据《大地巡旅》卡兹戴尔卷。",
                'location' => '卡兹戴尔',
                'sources' => [['setting-chronicle', null, '泰拉历1086年，卡兹戴尔内战爆发'], ['artbook-1', null, null]],
                'factions' => [['卡兹戴尔', 'involved']],
                'characters' => [['特蕾西娅', 'support'], ['特雷西斯', 'support']],
                'tags' => ['内战', '萨卡兹'],
                'status' => 'verified',
            ],

            // ===== 《大地巡旅》年表：1016 – 1091（附录「泰拉纪年」） =====
            [
                'title' => '哥伦比亚独立战争',
                'date' => '泰拉历1016年-1019年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '哥伦比亚为脱离维多利亚爆发独立战争。',
                'details' => "年表记为 1016 – 1019 年，条目即按其起止年定位为一个区间（精度 range）。\n\n背景：维多利亚卷载，帝国在远离本土的地区建立了许多开拓区和飞地，并在各开拓区实行差别政策——旨在从开拓区获取尽可能多的资源，却只提供最低程度的支持。这样的态度在各开拓区激起了不满和抗议，其中最具代表性的两场运动，分别是哥伦比亚独立战争与围栏事件后尤立卡自治州加入雷姆必拓的斗争。\n\n影响：开拓区给维多利亚带来了难以计数的财富和资源，但书中也写道，对维多利亚来说，也许是时候重新考虑和规划与开拓区相关的政策了。这场独立战争的余波更在十余年后仍影响着帝国的决策——高卢正是据此判断维多利亚不会贸然接受另一次缺乏准备的军事干预。\n\n背景与影响据《大地巡旅》维多利亚卷。",
                'location' => '哥伦比亚',
                'sources' => [['terra-tour', null, '1016-1019 哥伦比亚独立战争', '泰拉纪年']],
                'factions' => [['哥伦比亚', 'protagonist'], ['维多利亚', 'instigator']],
                'tags' => ['战争', '政权更迭'],
                'status' => 'verified',
            ],
            [
                'title' => '高卢－莱塔尼亚战争与四国战争',
                'date' => '泰拉历1029年-1031年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '高卢与莱塔尼亚交战，并演变为牵涉多国的四国战争。',
                'details' => "（年表记为 1029 – 1031 年，条目即按其起止年定位为一个区间。）\n\n起因：高卢皇帝科西嘉一世多次通过皇家信使向莱塔尼亚表达外交诉求，希望将其纳入自身势力范围。这一次，他「邀请」巫王成为自己的盟友与姻亲，正式提议莱塔尼亚成为帝国的「同盟邦」，在一位高卢保护者的庇护下享有至少百年的稳定与繁荣。巫王怒不可遏，处决了莱塔尼亚境内所有来自高卢的信使——无论他们是否为高卢皇室效力——并将他们「重塑」为一座高度接近二十米的塑像「回赠」高卢皇帝。1029 年 8 月，高卢完成军事准备，正式向莱塔尼亚宣战。\n\n战略判断：科西嘉一世认为选帝侯与巫王之间的宿怨已积累到爆发边缘、莱塔尼亚贵族未必愿为巫王而战；维多利亚刚经开拓区独立、乌萨斯主力将被卡西米尔钉住，两国都不会做出有实际意义的干预；而高卢自身的黄金时代已过，比以往任何时候都更需要一位由自己掌握缰绳的盟友。三点判断中，前两点落空，第三点则道出了战争的真实动因。\n\n经过：高卢大军团合兵一路东进，迅速瓦解高塔贵族「牢不可破」的西部防线，四个月内夺下数座重要移动城市与四个选帝侯大区；直到 1029 年 12 月，巫王才再度将旋角的阴影投向战场——他没有亲临前线，却通过与数座高塔共鸣施展的源石技艺如天灾般撕碎军团先锋，以利奥波德大公为首、依然忠于莱塔尼亚的选帝侯同时发起反攻，战线就此止步。1030 年，维多利亚弗雷德里克三世与乌萨斯皇帝伊凡先后参战，战火从莱塔尼亚延烧至整个核心圈。1031 年 10 月，因四名君主亲临战阵而得名的「四皇会战」在下高卢地区的中央河谷打响：高卢军团先以抢攻占优，但其后续增援被维多利亚舰队阻截击溃——指挥这场关键截击战的正是后来被称为「帝国丧钟」的铁公爵威灵顿——主力遂在联军庞大的兵力与火力优势下被压垮，科西嘉一世的旗舰被击毁，老近卫军战至最后一人，皇帝遗骸不知所终。\n\n结果与影响：联军随后攻陷并彻底夷平拒绝投降的帝国首都林贡斯，高卢帝国从地缘政治版图上消失，参战各国瓜分其移动城市与领土，接管下高卢富庶谷地的维多利亚成为最大受益者；作为主战场的莱塔尼亚遭到严重破坏，巫王战后愈发醉心术法、愈发残暴；乌萨斯则借高卢技术获得改革工业生产体系、打造舰队的资本。大量源石军用制品投入这场战争，令罹患矿石病的战争难民与伤兵随处可见，战后激化的感染者问题首次把由前军事人员发起的感染者抵抗运动推入公众视野——四国战争由此成为感染者问题演变为「现代危机」的重要转折点。\n\n本书后记另称，四国战争导致农业技术发展带来的人口剧增几乎一朝被抹除。经过与影响据《大地巡旅》高卢卷「四国战争」节。",
                'location' => '高卢 / 莱塔尼亚',
                'sources' => [['terra-tour', null, '1029-1031 高卢－莱塔尼亚战争与四国战争', '泰拉纪年']],
                'factions' => [['高卢', 'instigator'], ['莱塔尼亚', 'victim']],
                'tags' => ['战争'],
                'status' => 'verified',
            ],
            [
                'title' => '卡兹戴尔军事委员会成立',
                'date' => '泰拉历1031年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '卡兹戴尔战争议会改组为卡兹戴尔军事委员会。',
                'location' => '卡兹戴尔',
                'sources' => [['terra-tour', null, '1031 卡兹戴尔战争议会改组为卡兹戴尔军事委员会', '泰拉纪年']],
                'factions' => [['卡兹戴尔', 'involved']],
                'tags' => ['政权更迭'],
                'status' => 'verified',
            ],
            [
                'title' => '叙拉古城邦联合议事会成立',
                'date' => '泰拉历1039年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '叙拉古城邦联合议事会成立，家族体制获得一个名义上的共同议事机构。',
                'location' => '叙拉古',
                'sources' => [['terra-tour', null, '1039 叙拉古城邦联合议事会成立', '泰拉纪年']],
                'factions' => [['叙拉古', 'involved']],
                'status' => 'verified',
            ],
            [
                'title' => '哥伦比亚组建玻利瓦尔联合政府',
                'date' => '泰拉历1047年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '哥伦比亚对玻利瓦尔的介入达到顶峰，其后组建玻利瓦尔联合政府。',
                'location' => '玻利瓦尔',
                'sources' => [['terra-tour', null, '1047 哥伦比亚对玻利瓦尔的介入达到顶峰，后组建玻利瓦尔联合政府', '泰拉纪年']],
                'factions' => [['哥伦比亚', 'instigator'], ['玻利瓦尔', 'victim']],
                'tags' => ['政权更迭'],
                'status' => 'verified',
            ],
            [
                'title' => '玻利瓦尔自治运动',
                'date' => '泰拉历1050年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '玻利瓦尔爆发自治运动，反抗外部势力的主导。',
                'location' => '玻利瓦尔',
                'sources' => [['terra-tour', null, '1050 玻利瓦尔爆发了自治运动', '泰拉纪年']],
                'factions' => [['玻利瓦尔', 'protagonist']],
                'tags' => ['政权更迭'],
                'status' => 'verified',
            ],
            [
                'title' => '第十次乌萨斯－卡西米尔战争结束',
                'date' => '泰拉历1062年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '第十次乌萨斯－卡西米尔战争结束，绵延近百年的乌卡纷争到此告一段落。',
                'details' => "背景：乌萨斯卷载，弗拉基米尔·伊凡诺维奇利用四国战争后接收的高卢技术开启了乌萨斯最强盛的时期，军事实力如其所愿地暴增；他迫不及待地发动了第九次和第十次乌萨斯与卡西米尔之间的战争，把卡西米尔的防线逼退近百千米。\n\n年表所记的 1062 年即这两次战争的终局：绵延近百年的乌卡纷争到此结束，而乌萨斯的扩张并未就此停步——同年之后，皇帝的注意力转向萨米、莱塔尼亚与东国，直至 1072 年血峰战役的惨败。\n\n背景据《大地巡旅》乌萨斯卷。",
                'location' => '乌萨斯－卡西米尔边境',
                'sources' => [['terra-tour', null, '1062 第十次乌萨斯－卡西米尔战争结束，代表着绵延了近百年的乌卡纷争到此结束', '泰拉纪年']],
                'factions' => [['乌萨斯帝国', 'involved'], ['卡西米尔', 'involved']],
                'tags' => ['战争'],
                'status' => 'verified',
            ],
            [
                'title' => '伊万杰利斯塔一世就任拉特兰教宗',
                'date' => '泰拉历1063年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '拉特兰的伊万杰利斯塔一世成为新一代拉特兰教宗。',
                'location' => '拉特兰',
                'sources' => [['terra-tour', null, '1063 拉特兰的伊万杰利斯塔一世成为新一代拉特兰教宗', '泰拉纪年']],
                'factions' => [['拉特兰', 'involved']],
                'status' => 'verified',
            ],
            [
                'title' => '维多利亚皇帝阿利斯泰尔被处绞刑',
                'date' => '泰拉历1072年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '维多利亚皇帝阿利斯泰尔被执行绞刑，维多利亚王权自此落入贵族与议会之手。',
                'details' => "经过：1072 年，维多利亚皇帝亨利·阿利斯泰尔·维多利亚被公开执行绞刑，无人继位。维多利亚卷将此事列为「末代皇帝谜案」——它毫无征兆、过于蹊跷，成为当代最著名的一桩悬案：不管是维多利亚本国人还是外国人都有各种各样的猜测，却没有人能够获得哪怕一丁点可靠的证据，许多试图深入调查此事的好事者都悄无声息地失踪了。\n\n后果：皇位空置后，维多利亚贵族间的斗争急剧激化。经过无数宫廷斗争与武装摩擦，八名公爵暂时击败其他竞争者、各自争取到实际控制一方领土的地位，即「八大公爵」——威灵顿公爵、开斯特公爵、温德米尔公爵、诺曼底公爵、高多汀公爵、亚伯科恩公爵、法夫公爵、阿什沃思公爵，其中权势最盛者为威灵顿、开斯特与温德米尔；公爵们尚未明确表示目的，但所有人都知道他们是为了皇位而互相攻伐。所幸维多利亚自 1072 年起虽君主缺位二十余年，整套国家机构仍然保持着基本的运转。\n\n背景与后果据《大地巡旅》维多利亚卷。",
                'location' => '维多利亚',
                'sources' => [['terra-tour', null, '1072 维多利亚皇帝阿利斯泰尔被执行绞刑', '泰拉纪年']],
                'factions' => [['维多利亚', 'victim']],
                'tags' => ['政权更迭'],
                'status' => 'verified',
            ],
            [
                'title' => '乌萨斯皇帝弗拉基米尔·伊凡诺维奇驾崩',
                'date' => '泰拉历1073年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '乌萨斯皇帝弗拉基米尔·伊凡诺维奇驾崩，帝位更替随即引发动荡。',
                'details' => "背景：弗拉基米尔·伊凡诺维奇亲历四国战争，在战中见证高卢源石技术爆发出的强大生产力，并利用战后接收的高卢技术开启了乌萨斯最强盛的时代，力图把帝国打造成一台攻无不克的战争机器。在他治下，大量源石军工厂拔地而起，国力和军力急速攀升，但也因此诞生了许多非军方背景的「产业贵族」，令以旧贵族为代表的军方势力非常不满。\n\n经过：他迫不及待地发动第九次和第十次乌萨斯与卡西米尔之间的战争，把卡西米尔的防线逼退近百千米；加剧对萨米的打击，让他们只能迁往更寒冷的冻土；还想把战争引向南方的莱塔尼亚与遥远的东国。1072 年，乌萨斯与东国的战场上，东部集团军群在血峰战役中突如其来地迎来惨败、草草撤军，弗拉基米尔皇帝因这场离奇的战败遭受重大打击，从此身患重疾，不到一年便撒手人寰。\n\n影响：这场失利在帝国内部引起轩然大波，帝国秩序乃至皇帝本人的地位都因此动摇——乌萨斯卷明言，这为后来的「大叛乱」埋下了祸根。他身后留下的是锐意改革的儿子费奥多尔，与一个军方权力亟待削减的帝国。\n\n背景与经过据《大地巡旅》乌萨斯卷。",
                'location' => '乌萨斯',
                'sources' => [['terra-tour', null, '1073 乌萨斯皇帝弗拉基米尔·伊凡诺维奇驾崩', '泰拉纪年']],
                'factions' => [['乌萨斯帝国', 'involved']],
                'status' => 'verified',
            ],
            [
                'title' => '乌萨斯「大叛乱」',
                'date' => '泰拉历1074年-1076年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '乌萨斯爆发「大叛乱」，帝国陷入内乱。',
                'details' => "（年表记为 1074 – 1076 年，条目即按其起止年定位为一个区间。）\n\n背景：弗拉基米尔之子费奥多尔是一位锐意改革的皇帝，登基之后立即着手削减军队的权力——大量起用非军事背景的议员，严格限制集团军属地的扩张规模，甚至提出「改地为省」的策略。\n\n起因：1074 年夏季，一队兵痞和一位贵族在临近圣骏堡的格里高利省一间酒馆内发生肢体冲突，士兵们将贵族殴伤，却要求省议会处罚贵族，这引起社会各界强烈抗议。同年冬季，第六集团军称抗议为「内乱」，发兵占领该省，并以「助长内乱」为名把省议会全员按军法处决。\n\n经过：费奥多尔闻讯勃然大怒，急召第六集团军统帅觐见；统帅非但没有遵旨面圣，还通知屯驻附近的第三、第八集团军和部分正在换防途中的第四集团军共同行动，强占了圣骏堡周边三省，号称「重塑军威」，要求费奥多尔「出让皇宫」。1074 年末，帝国议会宣布与集团军调解失败，第五集团军据称收到皇帝旨意，向第六集团军发起攻击；第六、第八集团军当即反击，并开始围攻圣骏堡、炮击皇宫，费奥多尔向全国下诏痛斥四个集团军为「叛军」。1075 年春季，第二、第七集团军从卡西米尔前线回援，同叛军展开激战，圣骏堡的战火点燃整个乌萨斯，烈度极高的内战全面爆发。战争持续到 1076 年冬季，最终以第六、第八集团军被全歼、叛乱遭到镇压而告终。\n\n影响：数以百万计的乌萨斯军民失去生命，无数工业设施被摧毁；第六、第八集团军被当作叛乱主谋，编制遭到裁撤，集团军属地被重新分配——作为同谋的第三、第四集团军被发配到偏远的边疆地区，勤王有功的部队接管剩下的土地；拥护皇帝的旧贵族借机发声，要求重获议会的多数席位。在接下来的二十余年中，贵族派系之间的政治分歧、愈演愈烈的感染者危机以及严重的社会不公平暴露在所有人面前。\n\n背景与经过据《大地巡旅》乌萨斯卷。",
                'location' => '乌萨斯',
                'sources' => [['terra-tour', null, '1074-1076 乌萨斯“大叛乱”', '泰拉纪年']],
                'factions' => [['乌萨斯帝国', 'victim']],
                'tags' => ['内战'],
                'status' => 'verified',
            ],
            [
                'title' => '巫王被推翻，双子女皇时代开始',
                'date' => '泰拉历1077年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '巫王被推翻，莱塔尼亚进入双子女皇的时代。',
                'details' => "背景：巫王统治的最后二三十年，陷入癫狂的他终年居于高塔之中，几乎断绝了和外界的一切联系。国家大权重新落入选帝侯手里，各选帝侯宫廷再度成为最大的实权机构；文化管控也稍有放松，许多曾被斥为「不入流」「不合格」「无意义」的艺术形式重新出现。彼时被称为「双子」的莉泽洛特与希尔德加德——两位有史以来最年轻的乐团首席——以和巫王党羽针锋相对的政治立场活跃于政治舞台，并能越发频繁地出入各个选帝侯宫廷，成为他们真正的座上宾。\n\n经过：1077 年 9 月，在选帝侯们的支持下，双子率领来自数个大区的联合部队突然出现在首都附近，「九月起义」爆发。选帝侯联军最初取得不小的优势，但巫王通过与高塔共鸣施展的源石技艺，将成千的联军战士化为灰烬；接下来六次向首都推进的攻势都被巫王及其爪牙逼退，联军陷入举步维艰的境地，许多人开始怀疑挑战巫王是否一开始就是死路一条。就在起义被逼到溃败边缘时，双子决定孤注一掷：率一支精锐部队在夜色和大部队佯攻的掩护下潜入首都，直奔巫王的高塔，在突破巫王布下的三十二道法术防线后，于塔中与其正面对峙。高塔中的对决几乎无人目击，但结果世人皆知——在黎明破晓时分，高塔上的源石技艺辉光终于彻底熄灭，莱塔尼亚巫王就此陨落。\n\n结果：尽管过程不符合传统，各方选帝侯仍然一致推举双子作为莱塔尼亚新的领导者，二人加冕成为双子女皇，并立刻着手处理巫王给国家带来的创伤；笼罩帝国的百年阴霾开始消散。\n\n背景与经过据《大地巡旅》莱塔尼亚卷「二重协奏」节。",
                'location' => '莱塔尼亚',
                'sources' => [['terra-tour', null, '1077 巫王被推翻，莱塔尼亚进入双子女皇的时代', '泰拉纪年']],
                'factions' => [['莱塔尼亚', 'involved']],
                'tags' => ['政权更迭'],
                'status' => 'verified',
            ],
            [
                'title' => '阿米娅出生',
                'date' => '泰拉历1083年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '阿米娅出生。',
                'details' => '年表注：[] 为凯尔希补充。',
                'location' => null,
                'sources' => [['terra-tour', null, '[1083年 阿米娅出生]', '泰拉纪年']],
                'characters' => [['阿米娅', 'protagonist']],
                'status' => 'verified',
            ],
            [
                'title' => '「罗德岛」号主体修缮完成',
                'date' => '泰拉历1089年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '「罗德岛」号主体修缮工作完成。',
                'details' => '年表注：[] 为凯尔希补充。',
                'location' => null,
                'sources' => [['terra-tour', null, '[1089年 “罗德岛”号主体修缮工作完成]', '泰拉纪年']],
                'factions' => [['罗德岛', 'involved']],
                'status' => 'verified',
            ],
            [
                'title' => '博士在「罗德岛」号苏醒',
                'date' => '泰拉历1090年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '「博士」在「罗德岛」号苏醒。',
                'details' => '年表注：[] 为凯尔希补充。这与 1096 年切尔诺伯格的第二次「苏醒」是两回事。',
                'location' => '罗德岛',
                'sources' => [['terra-tour', null, '[1090年 “博士”在“罗德岛”号苏醒]', '泰拉纪年']],
                'factions' => [['罗德岛', 'involved']],
                'characters' => [['博士', 'protagonist']],
                'status' => 'verified',
            ],
            [
                'title' => '阿米娅与博士踏上旅程',
                'date' => '泰拉历1090年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '阿米娅与「博士」一起踏上旅程。',
                'details' => '年表注：[] 为凯尔希补充。',
                'location' => null,
                'sources' => [['terra-tour', null, '[1090年 阿米娅与“博士”一起踏上旅程]', '泰拉纪年']],
                'characters' => [['阿米娅', 'protagonist'], ['博士', 'protagonist']],
                'status' => 'verified',
            ],
            [
                'title' => '谢拉格对外开放',
                'date' => '泰拉历1091年',
                'era' => 'era-1000-1093',
                'confidence' => 'confirmed',
                'summary' => '谢拉格对外开放，结束了长期与大地保持距离的封闭状态。',
                'location' => '谢拉格',
                'sources' => [['terra-tour', null, '1091 谢拉格对外开放', '泰拉纪年']],
                'factions' => [['谢拉格', 'involved']],
                'tags' => ['外交'],
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

            // ===== 《大地巡旅》年表：1094 – 1099（附录「泰拉纪年」） =====
            [
                'title' => '博士被放入「石棺」',
                'date' => '泰拉历1094年',
                'era' => 'era-1094-1095',
                'confidence' => 'confirmed',
                'summary' => '「博士」被放入「石棺」。',
                'details' => '年表注：[] 为凯尔希补充。注意：现有条目「博士进入石棺沉睡」记为 1095 年，与本书年表的 1094 年存在出入，留待一致性巡检收敛。',
                'location' => '切尔诺伯格',
                'sources' => [['terra-tour', null, '[1094年 “博士”被放入“石棺”]', '泰拉纪年']],
                'factions' => [['罗德岛', 'involved']],
                'characters' => [['博士', 'protagonist'], ['凯尔希', 'support']],
                'status' => 'verified',
            ],
            [
                'title' => '博士第二次「苏醒」',
                'date' => '泰拉历1096年',
                'era' => 'era-1096-1097',
                'confidence' => 'confirmed',
                'summary' => '「博士」第二次「苏醒」。',
                'details' => '年表注：[] 为凯尔希补充。即切尔诺伯格事变当日自石棺被罗德岛小队唤醒之事，与 1090 年的首次苏醒是两回事。',
                'location' => '切尔诺伯格',
                'sources' => [['terra-tour', null, '[1096年 “博士”第二次“苏醒”]', '泰拉纪年']],
                'factions' => [['罗德岛', 'involved']],
                'characters' => [['博士', 'protagonist']],
                'status' => 'verified',
            ],
            [
                'title' => '维多利亚事件',
                'date' => '泰拉历1098年',
                'era' => 'era-1098-1099',
                'confidence' => 'confirmed',
                'summary' => '《大地巡旅》年表在 1098 年记有「维多利亚事件」，未作展开。',
                'details' => '年表原文仅此一行；其具体所指需比对其他出处后确认，不臆测。',
                'location' => '维多利亚',
                'sources' => [['terra-tour', null, '1098 维多利亚事件', '泰拉纪年']],
                'factions' => [['维多利亚', 'involved']],
                'status' => 'verified',
            ],
            [
                'title' => '第一届拉特兰万国峰会',
                'date' => '泰拉历1099年',
                'era' => 'era-1098-1099',
                'confidence' => 'confirmed',
                'summary' => '第一届拉特兰万国峰会召开，各国往来与通讯随之便利。',
                'location' => '拉特兰',
                'sources' => [['terra-tour', null, '1099 第一届拉特兰万国峰会', '泰拉纪年']],
                'factions' => [['拉特兰', 'instigator']],
                'tags' => ['外交'],
                'status' => 'verified',
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
                'sources' => [['main-story-0', '0-1', '泰拉历1096年12月23日，切尔诺伯格事变爆发，整合运动武装攻入切尔诺伯格城区'], ['setting-chronicle', null, '泰拉历1096年12月23日，切尔诺伯格事变爆发']],
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
        $locators = $this->sourceLocators();

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
                'sources' => collect($data['sources'] ?? [])
                    ->map(fn ($s) => $this->citationRow($s, $locators))
                    ->filter(fn ($s) => $s['id'])->values()->all(),
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

    // ------------------------------------------------------------------ 《大地巡旅》

    /**
     * 《大地巡旅》（官方世界观设定集）承载的内容。
     *
     * 与主线 / 活动条目有本质差别：这本书的主体是**世界观机制与国家地区背景**，而不是编年史。
     * 它解释了「移动城市为什么存在」「天灾如何塑造政体」「各国政体如何运作」，
     * 却极少给出具体年份 —— 这正是这批条目全部落在「时间未定」泳道的原因，
     * 也是「时间未定必须是泳道而不是异常数据」这条设计的存在理由。
     *
     * 例外是书末附录「泰拉纪年」：那是全书唯一成体系的带年份材料，
     * 已作为带引文的条目进入 seedEvents()，不走本方法。
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
                'summary' => '乌萨斯以军事力量为国家组织的核心，皇帝同时是帝国议会与帝国军队的领袖，对外扩张与对内高压互为支撑。',
                'details' => "君主：乌萨斯深厚的君主专制传统脱胎于其战争建国的起源。皇帝对整个帝国拥有军事统帅对部下一般的权威，任何违背皇帝意志的举动都被视为对帝国的最大背叛；他同时是帝国议会和帝国军队的领袖，议会从议员到议长都要向他负责。皇权的维系因此严重依赖皇帝本人——能带来胜利的皇帝享有崇高威望，而无法取胜者难免遭到质疑，1072 年侵略东国失利、继而在 1073 年驾崩的弗拉基米尔即是一例。\n\n军队：帝国军以「集团军」为最高编制，统帅由皇帝直接授意、在具体战略上有比较高的自主权；多次改革后原有九支集团军，因「大叛乱」中第六、第八集团军被整建制歼灭而撤销编制，目前共七支。\n\n贵族：乌萨斯的爵位与军功赏赐密切相关，爵位高低直接由军事成就决定，由此膨胀出庞大的「旧贵族」；弗拉基米尔时期又出现以缴纳税金晋升、非军事出身的「产业贵族」。他力排众议大量起用外族人才，甚至将功勋卓著者封为贵族——战争机器长期的人力与资源稀缺，要求统治者让生活在这片土地上的所有种族都能为他所用。\n\n背景据《大地巡旅》乌萨斯卷。",
                'factions' => [['乌萨斯帝国', 'instigator']],
                'tags' => ['政权更迭'],
            ],
            [
                'title' => '维多利亚的君主制危机',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '维多利亚王权长期受贵族与议会势力牵制，围绕继承权与主权的矛盾为后来的内战埋下伏笔。',
                'details' => "体制：维多利亚行「君主立宪」，政权由皇帝和议会共同掌握。皇帝在理论上是国家的权力中心，拥有重要的行政、立法、司法和军事权力，并兼任议会议长；议会由大贵族构成，对皇权形成极强制衡，尽可能避免皇帝独断专行。贵族分两类：大贵族拥有封地与特权、头衔世代相传，传统上只有他们能任议员；小贵族无封地，以契约从封君处获取报酬，头衔多不可世袭。\n\n危机：1072 年阿利斯泰尔被处绞刑后，维多利亚君主缺位二十余年，整套国家机构虽处境艰难却仍保持基本运转——书中将其引为这套体制有效性的佐证。皇位空置同时激化了贵族斗争：「八大公爵」为皇位互相攻伐，其中威灵顿、开斯特与温德米尔三位权势最盛。此外，古老的争端逐渐浮出水面，塔拉居民祭出旧时旗号要求公道，边境因高卢地区归属问题而摩擦不断。\n\n理解这一结构性矛盾，才能理解伦蒂尼姆为何会成为各方争夺的核心。背景据《大地巡旅》维多利亚卷。",
                'factions' => [['维多利亚', 'involved']],
                'tags' => ['政权更迭'],
            ],
            [
                'title' => '莱塔尼亚的双王共治',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '莱塔尼亚由两位君主共同统治，术师传统与国家权力深度绑定，形成独特的双头政体。',
                'details' => "政体：莱塔尼亚名为帝国，内部运行的却是一套联盟系统，可简称为「选举君主制」。建国时的九个地区称「大区」或「选帝侯区」，全国只有九位选帝侯拥有选出皇帝的选举权与被选举权；皇帝对所有法律拥有最终阐释权，但选帝侯可援引宪章主张「莱塔尼亚的本质是各大区的联合」并拒绝承认——因此除巫王外的历代皇帝实际权力都不大。帝国没有固定的首都：新皇选出后中央政府随之迁往其治所，皇帝首先是本大区的最高领导人，然后才是帝国的皇帝。\n\n法理与传承：《金律乐章》是立国时九大部落与叙拉古共同缔结的宪章，分三大乐章，宣告帝国诞生、规定央地关系、点明世俗道德，并且是一部能够真实演奏的乐章；除原典外有十份抄本，分交九个选帝侯区与叙拉古自治领保管。历史上它几乎未曾变动，唯独巫王登基时删去了叙拉古自治领的内容。1077 年九月起义后，双子加冕为双子女皇，莱塔尼亚由此进入双王共治。\n\n背景据《大地巡旅》莱塔尼亚卷。",
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

            // ---- 本次据《大地巡旅》补齐的国家与地区（此前未立目） ----
            [
                'title' => '阿戈尔：「岛民」的海洋文明',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '阿戈尔是泰拉海洋一侧的文明，书中称其民众为「岛民」；泰拉历 913 年伊比利亚人与之相遇，此后伊比利亚的黄金时代与海外交往密不可分。',
                'details' => '见年表「913 伊比利亚人与岛民相遇」与伊比利亚卷。大静谧之后，沿岸秩序由这两个文明共同定义。',
                'factions' => [['阿戈尔', 'involved'], ['伊比利亚', 'involved']],
                'tags' => ['外交'],
            ],
            [
                'title' => '萨尔贡：难以尽述的南方大国',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '萨尔贡体量庞大、内部多样，作者在信中坦言因篇幅与内容敏感性删去了大部分相关章节；年表以萨尔贡「过去与未来之王」发现圣物之年为泰拉纪年元年。',
                'details' => '泰拉纪年即以那一年记为元年 —— 换言之，萨尔贡与泰拉通行历法的关系比一般认知更紧密。',
                'factions' => [['萨尔贡', 'involved']],
            ],
            [
                'title' => '萨米：景区之外的北方古国',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '萨米是北方群山中的古老国度，近年以旅游宣传为外人所知；作者提醒，景区之外另有真实的萨米。',
                'details' => '旅游热潮本身也是理解萨米与外界关系的材料：哥伦比亚旅行社的歌词成了许多人对它的第一印象。',
                'factions' => [['萨米', 'involved']],
            ],
            [
                'title' => '炎国：体量与含蓄并存的东方大国',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '炎国人口约三亿，主管户籍财政的户部可能是泰拉最繁重的行政机关；在维多利亚人的认知里，炎国常常只意味着龙门口岸与来自东方的茶叶。',
                'details' => '龙门是进入炎国的必经中转站，也是两国认知落差最集中的地方。',
                'factions' => [['炎国', 'involved'], ['龙门', 'involved']],
            ],
            [
                'title' => '极东：两大实体夹缝中的东国',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '极东处于乌萨斯与炎国两大地缘政治实体的夹缝之间；1072 年其联合信使团到访伦蒂尼姆而未获承诺，血峰战役的结果与随后的乌萨斯「大叛乱」改变了这一格局。',
                'details' => '信使团成员高川知彦的经历说明，外交承诺的缺席与国内的官僚倾轧同样扼住了这个国家的转机。',
                'factions' => [['极东', 'involved'], ['维多利亚', 'involved'], ['乌萨斯帝国', 'involved']],
                'tags' => ['外交'],
            ],
            [
                'title' => '卡兹戴尔：内战中的流动学校',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '1086 年卡兹戴尔内战中，萨卡兹的流动学校毁于炮火，师生带着课本在战地流亡；教育与历史记忆成为萨卡兹维系自身认同的方式。',
                'details' => '「卡兹戴尔历史上第八所，恐怕也是最后一所流动学校」—— 教师雷·坦卡的记述是这一卷里最直接的战时教育样本。',
                'factions' => [['卡兹戴尔', 'victim']],
                'characters' => [['特蕾西娅', 'support']],
                'tags' => ['内战', '萨卡兹'],
            ],
            [
                'title' => '高卢的遗产',
                'section' => '国家与地区卷',
                'era' => null,
                'summary' => '四国战争后高卢不复往日大国地位；其改革失败的经验与战争记忆，仍是理解莱塔尼亚、维多利亚现状的背景。',
                'details' => "人：高卢灭亡后，有将近三千万说高卢语的人失去了自己的国家。他们中大多数留在本地，成为维多利亚人或莱塔尼亚人；也有一部分出走他国，许多移动城市中的「高卢区」即由此而来。自称「复国主义者」的高卢人以文化而非血统定义「高卢人」，认为高卢子民是一种超越地区、时间、种族和血统限制的身份——这套话语对后世政治思潮与运动的影响，不亚于帝国覆灭本身。\n\n物：高卢的另一些遗产更为日常。林贡斯是泰拉首个把古老城市搬迁到移动平台上的首都，其重建中形成的巨大落地窗、铜质装饰与大理石建筑群被冠以「帝政主义」，在帝国倾覆后仍持续影响周边国家；自来水、下水道与供暖等设施被高卢首个「打包」提供给普通市民，由此养育出被当时人称作「美好年代」的生活方式，而与之相对的批判性创作也深刻影响了各国艺术家。\n\n书中与作者信件均以高卢为参照：四国战争的人口代价、以及「改革失败的高卢人自己也没有使用这样的一套制度」，都在提示这段遗产的分量。背景据《大地巡旅》高卢卷。",
                'factions' => [['高卢', 'involved'], ['莱塔尼亚', 'involved'], ['维多利亚', 'involved']],
                'tags' => ['战争'],
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
    /**
     * 塔卫二（《明日方舟：终末地》）的条目。
     *
     * 与泰拉部分的关键差别在**资料来源**：塔卫二的年表目前只存在于玩家社区的整理里，
     * 游戏内文本尚未逐条录入本仓库。因此这批条目：
     *
     *  1. 一律 `date_confidence = inferred`（社区自己就标注为推测的记 `disputed`），
     *     状态落在 needs_review —— 可信度字段存在的意义就是让这种情况如实表达，
     *     而不是把社区推断包装成「已确证」；
     *  2. **引文全部留空**。社区整理是二级来源，把它当引文写进 event_source.quote，
     *     会直接摧毁「引用可定位」这条校验的价值；
     *  3. 年份取社区整理中相对一致的数字，有分歧或不确切的写进 details 里说明，
     *     而不是挑一个好看的数字了事。
     *
     * 换句话说：这批数据要表达的不是「塔卫二发生过什么」，而是
     * 「目前我们据什么认为它发生过什么，以及这个依据有多硬」。
     */
    private function seedTalosEvents(User $admin): void
    {
        $writer = app(EventWriter::class);

        $events = [
            [
                'title' => '星门开启，泰拉先民抵达塔卫二',
                'date' => '塔罗斯历 1 年',
                'era' => 'talos-1-15',
                'confidence' => 'inferred',
                'summary' => '部分泰拉的神民与先民穿过星门抵达塔卫二，开始在这颗卫星上拓荒定居，史称「开拓」。'
                    .'后世知道内情的人则称其为「回归」。',
                'details' => '塔罗斯历以这一年为元年，因此「开拓 152 年」等价于「塔罗斯历 152 年」。'
                    .'两个名称的分歧本身就是一条线索：塔卫二并非全然陌生的土地。'
                    .'该结论目前只来自社区整理，尚无游戏内原文核对。',
                'location' => '塔卫二',
                'sources' => [['talos-survey', null, null]],
                'factions' => [['终末地工业', 'involved']],
                'tags' => ['星门', '开拓'],
            ],
            [
                'title' => '第一次天使战争爆发',
                'date' => '塔罗斯历 5 年',
                'era' => 'talos-1-15',
                'confidence' => 'inferred',
                'summary' => '天使的进犯摧毁星门附近的城市，并完全占据北极地区，人类被迫向南方迁移。',
                'details' => '社区整理把第一次天使战争划分为 5—11 年前后两段，本条目记的是爆发年份。'
                    .'「锚点」被摧毁与星门附近城市失守是同一段叙述里的两件事，因果关系尚无定论。',
                'location' => '塔卫二 · 北极地区',
                'sources' => [['talos-survey', null, null]],
                'factions' => [['天使', 'opponent'], ['文明环带', 'victim']],
                'tags' => ['战争', '天灾'],
            ],
            [
                'title' => '人类势力重组，第一次天使战争结束',
                'date' => '塔罗斯历 11 年',
                'era' => 'talos-1-15',
                'confidence' => 'inferred',
                'summary' => '管理员重整人类势力，对天使的进犯形成有效抵抗，第一次天使战争在此后结束。',
                'details' => '战争的确切结束年份社区未给出统一说法（资料多写作「11—15 年」这一段），'
                    .'本条只记「11 年发生重组」这一较为一致的节点。',
                'location' => null,
                'sources' => [['talos-survey', null, null]],
                'factions' => [['终末地工业', 'involved'], ['天使', 'opponent']],
                'characters' => [['管理员', 'involved']],
                'tags' => ['战争'],
            ],
            [
                'title' => '清波寨建立',
                'date' => '塔罗斯历 16 年',
                'era' => 'talos-16-89',
                'confidence' => 'inferred',
                'summary' => '第一次天使战争结束后，南迁人群在塔卫二上建立起新的聚落，清波寨是其中之一。',
                'details' => '社区整理把它系在 16—17 年之间，本条取起始年。',
                'location' => '清波寨',
                'sources' => [['talos-survey', null, null]],
                'tags' => ['开拓'],
            ],
            [
                'title' => '四号谷地开始开发',
                'date' => '塔罗斯历 70 年',
                'era' => 'talos-16-89',
                'confidence' => 'disputed',
                'summary' => '联盟工团在边缘地区选定的生产开拓区域开始发展，管理员在此种下源石大树。',
                'details' => '整理者本人把这一年标注为**推测**，且「种下源石大树」与「谷地开始发展」'
                    .'是否为同一年并无把握，因此本条目标记为存疑，等待原文核对。',
                'location' => '四号谷地',
                'sources' => [['talos-survey', null, null]],
                'factions' => [['联盟工团', 'involved']],
                'characters' => [['管理员', 'involved']],
                'tags' => ['开拓', '源石'],
            ],
            [
                'title' => '四号谷地遭天使集群袭击',
                'date' => '塔罗斯历 75 年',
                'era' => 'talos-16-89',
                'confidence' => 'inferred',
                'summary' => '四号谷地遭受天使集群袭击，阿伯莉在袭击中牺牲，同期采石场接近竣工。',
                'details' => '与上年一条一样，年份来自同一份社区整理，尚未与游戏内原文核对。',
                'location' => '四号谷地',
                'sources' => [['talos-survey', null, null]],
                'factions' => [['天使', 'opponent'], ['联盟工团', 'victim']],
                'characters' => [['阿伯莉', 'victim']],
                'tags' => ['战争'],
            ],
            [
                'title' => '供能高地超域试验场建立',
                'date' => '塔罗斯历 90 年',
                'era' => 'talos-90-152',
                'confidence' => 'inferred',
                'summary' => '供能高地上的超域试验场落成，成为此后塔卫二技术体系的关键设施之一。',
                'details' => '「超域」一词的准确含义在社区资料中尚无一致解释，本条只记录设施建立这一事件。',
                'location' => '供能高地',
                'sources' => [['talos-survey', null, null]],
                'factions' => [['终末地工业', 'involved']],
                'tags' => ['协议'],
            ],
            [
                'title' => '「裂地者」得名',
                'date' => '塔罗斯历 90 年',
                'era' => 'talos-90-152',
                'confidence' => 'disputed',
                'summary' => '雅各布·迈森将其手下的匪帮命名为「裂地者」，该势力后被文明环带摧毁。',
                'details' => '两种分歧都记在这里：一是名称在社区资料中同时写作「裂地者」与「掠地者」；'
                    .'二是「摧毁」的具体时间未定，只知道在 90 年之后。'
                    .'纪元归属只说明它落在这一段区间里，不代表年份精确。',
                'location' => null,
                'sources' => [['talos-survey', null, null]],
                'factions' => [['裂地者', 'involved'], ['文明环带', 'involved']],
                'tags' => ['内战'],
            ],
            [
                'title' => '管理员在帝江号上苏醒',
                'date' => '塔罗斯历 152 年',
                'era' => 'talos-90-152',
                'confidence' => 'inferred',
                'summary' => '第二次天使战争后陷入沉睡的管理员于塔罗斯历 152 年在轨道飞行器「帝江号」上苏醒，'
                    .'但失去了记忆。',
                'details' => '「第二次天使战争后沉睡」与「152 年苏醒」之间究竟隔了多久，'
                    .'社区资料没有给出可核对的数字 —— 这条时间差本身就是待考的内容。',
                'location' => '帝江号',
                'sources' => [['talos-survey', null, null]],
                'factions' => [['终末地工业', 'involved']],
                'characters' => [['管理员', 'involved']],
                'tags' => ['协议'],
            ],
            [
                'title' => '终末地工业重启，于四号谷地建立工业基地',
                'date' => '塔罗斯历 152 年',
                'era' => 'talos-90-152',
                'confidence' => 'inferred',
                'summary' => '终末地工业重新运转，以四号谷地为据点建立工业基地，'
                    .'由协议回收部门的管理员带队对抗天使与掠地者。',
                'details' => '这一条与「管理员苏醒」同年，是《终末地》主线叙事的起点。'
                    .'本仓库把它记为当前时间线的端点：塔卫二的条目都在这之前。',
                'location' => '四号谷地',
                'sources' => [['talos-survey', null, null]],
                'factions' => [['终末地工业', 'involved'], ['天使', 'opponent'], ['裂地者', 'opponent']],
                'characters' => [['管理员', 'involved'], ['佩丽卡', 'involved'], ['陈千语', 'involved']],
                'tags' => ['开拓', '协议'],
            ],
        ];

        foreach ($events as $data) {
            // 纪元按世界查：跨世界的 slug 即便存在，也不该被这条时间线取用
            $eraId = Era::ofWorld(World::Talos)->where('slug', $data['era'])->value('id');

            $payload = [
                'world' => World::Talos->value,
                'title' => $data['title'],
                'summary' => $data['summary'],
                'details' => $data['details'] ?? null,
                'location' => $data['location'] ?? null,
                'date_display' => $data['date'],
                'date_precision' => $data['precision'] ?? null,
                'date_confidence' => $data['confidence'],
                'era_id' => $eraId,
                'sort_seq' => $data['sort_seq'] ?? 0,
                // 非 confirmed 一律 needs_review：这批条目等的就是原文核对
                'status' => $data['status'] ?? EventStatus::NeedsReview->value,
                'start_index' => null,
                'end_index' => null,
                'sources' => collect($data['sources'] ?? [])->map(fn ($s) => [
                    'id' => Source::where('slug', $s[0])->value('id'),
                    'stage_code' => $s[1] ?? null,
                    // 引文一律留空：二级来源不是引文，见本方法的类注释
                    'quote' => null,
                    'is_primary' => false,
                ])->filter(fn ($s) => $s['id'])->values()->all(),
                'characters' => collect($data['characters'] ?? [])->map(fn ($c) => ['name' => $c[0], 'role' => $c[1]])->all(),
                'factions' => collect($data['factions'] ?? [])->map(fn ($f) => ['name' => $f[0], 'role' => $f[1]])->all(),
                'tags' => collect($data['tags'] ?? [])->map(fn ($t) => ['name' => $t])->all(),
            ];

            $writer->create($payload, $admin, ChangeOrigin::Seed);
        }
    }

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
