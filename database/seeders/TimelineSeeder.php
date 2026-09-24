<?php

namespace Database\Seeders;

use App\Enums\ChangeOrigin;
use App\Enums\EventStatus;
use App\Enums\FactionKind;
use App\Enums\IdentityProvider;
use App\Enums\SourceType;
use App\Enums\World;
use App\Models\Character;
use App\Models\Era;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Place;
use App\Models\Race;
use App\Models\Source;
use App\Models\Term;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        // 种族字典要先于人物：人物挂的是 race_id，字典不在就只有空值
        $this->seedRaces();
        $this->seedCharacters();
        $this->seedTags();
        // 地名树要先于条目：条目的 place_id 由 location 原文匹配而来
        $this->seedPlaces();
        $this->seedTerms();
        $this->seedSources();
        /*
         * 条目与示例提案**不是幂等的**：`EventWriter::create()` 每次都会新建一条，
         * 所以再跑一次 `db:seed` 会让时间线直接翻倍 —— 而对本项目来说，
         * 一份翻倍的年表比没有年表更糟，它恰好是「可被信赖」这件事的反面。
         *
         * 字典（种族 / 地名 / 词条 / 阵营）与出处走 updateOrCreate，重跑无妨，因此只跳过这几步。
         * 需要让种子里的条目改动真正落库时，用 `migrate:fresh --seed`。
         */
        if (Event::exists()) {
            $this->command?->warn(
                '时间线已有 '.Event::count().' 条条目，跳过条目与示例提案的种子（字典仍会照常刷新）。'
                .'要让种子里的条目改动生效，请运行 migrate:fresh --seed。'
            );

            return;
        }

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
            ['name' => '罗德岛', 'kind' => 'enterprise', 'full_name' => '罗德岛制药公司', 'color' => '#38bdf8', 'children' => [
                ['name' => '精英干员', 'kind' => 'agency', 'color' => '#0ea5e9'],
                ['name' => '医疗部', 'kind' => 'agency', 'color' => '#22d3ee'],
            ]],
            ['name' => '巴别塔', 'kind' => 'society', 'full_name' => '巴别塔（罗德岛前身）', 'color' => '#6366f1', 'description' => '罗德岛的前身组织，卡兹戴尔内战期间活跃，后改组为罗德岛。'],
            ['name' => '整合运动', 'kind' => 'society', 'full_name' => '感染者反抗组织 · 整合运动', 'color' => '#f87171'],
            ['name' => '乌萨斯帝国', 'kind' => 'polity', 'color' => '#94a3b8', 'children' => [
                ['name' => '乌萨斯军事委员会', 'kind' => 'agency', 'color' => '#64748b'],
            ]],
            ['name' => '龙门', 'kind' => 'polity', 'full_name' => '龙门独立市', 'color' => '#fbbf24', 'children' => [
                ['name' => '企鹅物流', 'kind' => 'enterprise', 'full_name' => '企鹅物流 · Penguin Logistics', 'color' => '#fcd34d',
                    'description' => '大帝在龙门创办的物流企业，要人护卫与货物运输之外也承接灰色业务。'],
                ['name' => '鲤氏侦探事务所', 'kind' => 'enterprise', 'full_name' => '鲤氏侦探事务所 · Lee\'s Detective Agency', 'color' => '#fde68a',
                    'description' => '老鲤在龙门经营的私人侦探事务所，兼作各方势力之间的中立咨询渠道。'],
            ]],
            ['name' => '维多利亚', 'kind' => 'polity', 'full_name' => '维多利亚王国', 'color' => '#a78bfa'],
            ['name' => '卡西米尔', 'kind' => 'polity', 'full_name' => '卡西米尔骑士之国', 'color' => '#f472b6'],
            ['name' => '哥伦比亚', 'kind' => 'polity', 'color' => '#10b981', 'description' => '原为维多利亚殖民地，独立后迅速工业化，莱茵生命等大型研究机构以此为基地。', 'children' => [
                // 这些机构挂到母政体下之后，「按哥伦比亚筛选」会自动把它们的条目也带出来
                ['name' => '莱茵生命', 'kind' => 'enterprise', 'full_name' => '莱茵生命实验室', 'color' => '#34d399'],
                ['name' => '黑钢国际', 'kind' => 'enterprise', 'full_name' => '黑钢国际 · Black Steel Worldwide', 'color' => '#f59e0b',
                    'description' => '巴伦基地起家的私人安全承包商，由萨科塔雇佣兵「桥夹」克里夫创办。'],
                ['name' => '雷神工业', 'kind' => 'enterprise', 'full_name' => '雷神工业 · Raythean Industries', 'color' => '#22c55e',
                    'description' => '两位创始人分别来自高卢与哥伦比亚的综合工业集团，以源石晶体单元研发见长。'],
            ]],
            ['name' => '伊比利亚', 'kind' => 'polity', 'color' => '#22d3ee'],
            // 「炎-岁」是 PRTS 势力表里的一个取值：炎国境内围绕「岁」的一组存在
            // （年、夕、令、重岳都归在其下）。挂进阵营树之后，
            // 他们既保留了「属炎国」的归属，又比笼统的「炎国」精确
            ['name' => '炎国', 'kind' => 'polity', 'color' => '#fb923c', 'children' => [
                ['name' => '炎-岁', 'kind' => 'society', 'color' => '#fdba74',
                    'description' => '炎国境内围绕远古实体「岁」的一组存在；年、夕、令、重岳在势力表里归于其下。'],
            ]],
            ['name' => '叙拉古', 'kind' => 'polity', 'color' => '#c084fc'],
            ['name' => '喀兰贸易', 'kind' => 'enterprise', 'color' => '#60a5fa'],
            ['name' => '谢拉格', 'kind' => 'polity', 'color' => '#93c5fd'],
            ['name' => '卡兹戴尔', 'kind' => 'polity', 'full_name' => '卡兹戴尔 / 萨卡兹流亡政权', 'color' => '#ef4444'],
            ['name' => '拉特兰', 'kind' => 'polity', 'color' => '#e2e8f0'],
            ['name' => '萨尔贡', 'kind' => 'polity', 'color' => '#facc15'],
            ['name' => '深池', 'kind' => 'society', 'full_name' => '维多利亚感染者组织 · 深池', 'color' => '#e879f9'],

            // 《大地巡旅》「国家与地区」卷覆盖、但此前未进入检索维度的政体。
            // 只填在有把握的字段上：不做正式国名的推测，拿不准的一律留空。
            ['name' => '莱塔尼亚', 'kind' => 'polity', 'color' => '#818cf8', 'description' => '以双王共治体制与术师传统著称的政体。'],
            ['name' => '米诺斯', 'kind' => 'polity', 'color' => '#2dd4bf', 'description' => '由多个城邦构成的地区，保有古老的信仰与竞技传统。'],
            ['name' => '玻利瓦尔', 'kind' => 'polity', 'color' => '#fb7185', 'description' => '长期陷入内乱与外部势力干涉的地区。'],
            ['name' => '雷姆必拓', 'kind' => 'polity', 'color' => '#a3a3a3', 'description' => '以矿业与资源贸易立身的企业化政体。'],
            ['name' => '萨米', 'kind' => 'polity', 'color' => '#bae6fd', 'description' => '泰拉北境雪原地区，以部族与萨满信仰为组织形态。'],
            // 深海猎人是 PRTS 势力表里的一个取值：幽灵鲨与歌蕾蒂娅的势力栏写着
            // 「阿戈尔 + 深海猎人」，而本表一人一职 —— 挂到更具体的这一个，
            // 其上级即阿戈尔，层级本身把两者都表达了出来
            ['name' => '阿戈尔', 'kind' => 'polity', 'color' => '#0891b2', 'description' => '与深海威胁直接相关的海洋文明。', 'children' => [
                ['name' => '深海猎人', 'kind' => 'society', 'color' => '#0e7490',
                    'description' => '阿戈尔的一支猎人组织；斯卡蒂、幽灵鲨、歌蕾蒂娅等干员在势力表里与之相关。'],
            ]],
            // 这两行此前只由条目引用时自动建档（slug 是随机串），因此一直没有颜色与类型。
            // 点名登记之后，它们才会作为政体出现在地名树与资料集里，而不是停在「未归类」。
            ['name' => '高卢', 'kind' => 'polity', 'color' => '#fde047', 'description' => '1031 年四国战争后从地缘政治版图上消失的帝国。'],
            ['name' => '极东', 'kind' => 'polity', 'color' => '#fda4af', 'description' => '东国在阵营表里的写法：夹在乌萨斯与炎国两大地缘实体之间。'],

            /*
             * ---- 《大地巡旅》「组织」卷登记、但无归属政体的组织 ----
             *
             * 锈锤与太阳谷都不属于任何现存国家的下属机构，因此保持为顶层：
             * 锈锤是跨越国界的松散团体，太阳谷的厂区社区自成一体
             * （书中提到它出现在雷姆必拓一带，但并未称其为该政体的下属机构，不臆断）。
             */
            ['name' => '锈锤', 'kind' => 'society', 'color' => '#a8a29e',
                'description' => '活动于荒野的松散团体，主张对「文明」本身复仇，被多国列为重大治安问题。'],
            ['name' => '太阳谷机械工业', 'kind' => 'enterprise', 'full_name' => '太阳谷机械工业 · Sun Valley Industries', 'color' => '#d6d3d1',
                'description' => '厂区即生活社区的工业集团，口号是「工作，是为了更好的生活」。'],

            /*
             * ---- 塔卫二（《明日方舟：终末地》）----
             *
             * 阵营词典是**跨世界共享**的，刻意没有 world 列：罗德岛这类组织
             * 同时存在于两个世界的历史里（终末地工业由罗德岛等协同组建），
             * 强行按世界切分反而会把同一个组织拆成两份。
             */
            [
                'name' => '终末地工业',
                'kind' => 'enterprise',
                'full_name' => '终末地工业 · Endfield Industries',
                'color' => '#57c7d4',
                'description' => '塔卫二上最大的技术承包商，由罗德岛制药公司与其他合作方协同组建，'
                    .'承担开拓、协议回收与重建工业体系的使命。总部为轨道飞行器「帝江号」。',
                'children' => [
                    [
                        'name' => '协议回收部门',
                        'kind' => 'agency',
                        'color' => '#6fc3d4',
                        'description' => '管理员直接带领的一线部门，负责回收塔卫二上失落的「协议」。',
                    ],
                ],
            ],
            ['name' => '联盟工团', 'kind' => 'society', 'color' => '#8a9aa8', 'description' => '塔卫二上的生产与开拓组织，四号谷地最初由其选定为生产开拓区域。'],
            ['name' => '天使', 'kind' => 'military', 'color' => '#e2e8f0', 'description' => '塔卫二的主要敌对势力，第一次与第二次天使战争均与其进犯有关。'],
            ['name' => '裂地者', 'kind' => 'society', 'color' => '#f87171', 'description' => '雅各布·迈森手下的匪帮，后被文明环带摧毁。社区资料中亦写作「掠地者」，两种写法并存。'],

            /*
             * 文明环带是**地域**而不是组织：它是塔卫二上的人类聚居带（四号谷地位于其边缘地区），
             * 只是历史原因一直登记在阵营表里。归入 polity / territory 之后，
             * 它不会再出现在资料集的「组织」页 —— 那页要回答的是「谁在做」，
             * 而聚居带回答的是「在哪里」。
             */
            ['name' => '文明环带', 'kind' => 'territory', 'color' => '#7dd3fc', 'description' => '塔卫二上的人类聚居带，四号谷地位于其边缘地区。'],
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
        /*
         * 按**名称**匹配，而不是按 slug。
         *
         * 条目引用一个尚未登记的阵营时，`EventWriter::firstOrCreateByName()` 会以
         * 「缺省 slug 回落成随机串」的方式自动建档（「高卢」「极东」就是这么来的）。
         * 若这里按 slug 匹配，种子会为同一个阵营再建一份 —— 两行同名阵营，
         * 各自挂着一半条目，而界面上完全看不出区别。
         */
        $faction = Faction::firstOrNew(['name' => $data['name']]);

        $faction->fill([
            'full_name' => $data['full_name'] ?? null,
            // 缺省落到「未归类」而不是政体：一个没归类的阵营应当显眼地待在那儿，
            // 而不是悄悄混进政体、或悄悄混进组织（`FactionKind::Other`）
            'kind' => $data['kind'] ?? FactionKind::Other->value,
            'color' => $data['color'] ?? '#64748b',
            'description' => $data['description'] ?? null,
            'parent_id' => $parentId,
            'sort_order' => $order,
        ]);

        // slug 只在新建时给：已存在的行（可能是自动建档的）保留它原本的标识
        $faction->slug ??= $data['slug'] ?? 'fac-'.md5($data['name']);
        $faction->save();

        return $faction;
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
        /*
         * 两处刻意的取舍：
         *
         *  1. **「结晶时代」来自书里，不是自造的分期。**《大地巡旅》年表的标题行写着
         *     「结晶时代 —— 泰拉历 797 年至今」，那是作者给这段历史的命名。此前的划分
         *     直接把它切成「远古 · 前纪元 / 旧秩序的裂痕…」，等于丢掉了书里现成的口径。
         *  2. **它的区间与子纪元重叠是当然的**：父是分期标签，子是条目的桶，
         *     父的区间本来就是子的并集。因此「区间不重叠」这条不变量按**同级**判定。
         *     为了让 797 这个起点是真的，`prehistory` 的终点从 999 收到 796 ——
         *     797–999 另立一个子纪元，年表里那段（移动城市成型、伊比利亚黄金时代、
         *     巫王崛起）从此有了自己的色带。
         *
         * `sort_order` 决定分组顺序；`parent` 在全部纪元建好后再统一回填。
         */
        $eras = [
            [
                'name' => '远古 · 前纪元',
                'slug' => 'prehistory',
                'subtitle' => '源石与文明的原点',
                'date_label' => '泰拉历前 — 泰拉历 796 年',
                'start' => TerraDate::toIndex(-500),
                'end' => TerraDate::toIndex(796, 12, 31),
                'color' => '#808080',
                'description' => '源石显现、源石技艺普及、各古老政体成型的时期。年表大量缺失，条目多需推断。'
                    .'终点取 796 年：次年起进入书里所说的「结晶时代」。',
            ],
            [
                'name' => '结晶时代',
                'slug' => 'crystalline-era',
                'subtitle' => '书里的分期：797 年至今',
                'date_label' => '泰拉历 797 年 — 1101 年',
                'start' => TerraDate::toIndex(797),
                'end' => TerraDate::toIndex(1101, 12, 31),
                'color' => '#6b6b6b',
                'description' => '《大地巡旅》年表只收录这段时期：作者自述它始于 797 年七城联邦建成'
                    .'第一座现代移动城市，并延续至今。它是一个**分期标签**，条目只挂下辖纪元。',
            ],
            [
                'name' => '结晶时代 · 兴起',
                'slug' => 'era-797-999',
                'subtitle' => '移动城市与列国成型',
                'date_label' => '泰拉历 797 年 — 999 年',
                'start' => TerraDate::toIndex(797),
                'end' => TerraDate::toIndex(999, 12, 31),
                'color' => '#8a7c33',
                'description' => '年表所记结晶时代的前半段：移动城市成为城市的标准形态，'
                    .'伊比利亚的黄金时代、巫王治下的莱塔尼亚、维多利亚命名哥伦比亚皆在此期间。',
                'parent' => 'crystalline-era',
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
                'parent' => 'crystalline-era',
            ],
            [
                'name' => '切城事变前夕',
                'slug' => 'era-1094-1095',
                'subtitle' => '巴别塔落幕与罗德岛重建',
                'date_label' => '泰拉历 1094 年 — 1095 年',
                'start' => TerraDate::toIndex(1094),
                'end' => TerraDate::toIndex(1095, 12, 31),
                'color' => '#c9a227',
                'parent' => 'crystalline-era',
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
                'parent' => 'crystalline-era',
            ],
            [
                'name' => '整合运动余波',
                'slug' => 'era-1098-1099',
                'subtitle' => '主线第 8 — 9 章',
                'date_label' => '泰拉历 1098 年 — 1099 年',
                'start' => TerraDate::toIndex(1098),
                'end' => TerraDate::toIndex(1099, 12, 31),
                'color' => '#ffa32e',
                'parent' => 'crystalline-era',
            ],
            [
                'name' => '维多利亚战争',
                'slug' => 'era-1100',
                'subtitle' => '主线第 10 — 11 章 · 伦蒂尼姆',
                'date_label' => '泰拉历 1100 年',
                'start' => TerraDate::toIndex(1100),
                'end' => TerraDate::toIndex(1100, 12, 31),
                'color' => '#ff7038',
                'parent' => 'crystalline-era',
            ],
            [
                'name' => '萨卡兹的终局',
                'slug' => 'era-1101',
                'subtitle' => '主线第 12 — 15 章',
                'date_label' => '泰拉历 1101 年',
                'start' => TerraDate::toIndex(1101),
                'end' => TerraDate::toIndex(1101, 12, 31),
                'color' => '#ff4242',
                'parent' => 'crystalline-era',
            ],
        ];

        $ids = [];

        foreach ($eras as $order => $era) {
            $model = Era::updateOrCreate(
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

            $ids[$era['slug']] = $model->id;
        }

        // 父级在第二遍统一回填：数组里的先后只是书写顺序，
        // 不该由此衍生出「新增一个写在父前面的子纪元就挂掉」这种脆弱约束。
        foreach ($eras as $era) {
            if (! isset($era['parent'])) {
                continue;
            }

            Era::whereKey($ids[$era['slug']])->update(['parent_id' => $ids[$era['parent']] ?? null]);
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
            // 阵营此前写成「谢拉格」，PRTS 干员页作「罗德岛」—— 按 PRTS 更正（2026-09-24）
            ['尤里卡', 'U-Official', '罗德岛', '札拉克'],
            ['拉普兰德', 'Lappland', '叙拉古', '鲁珀'],
            ['德克萨斯', 'Texas', '叙拉古', '鲁珀'],
            ['能天使', 'Exusiai', '拉特兰', '萨科塔'],
            // 「岁」**不是干员**，而是炎国传说里的远古实体（其条目为「「岁」的诞生」）。
            // PRTS 的干员是「年」（Nian），本文件此前把它记成了实体本身 —— 保留记录，
            // 但它出现在人员名单里这件事本身是待处理的（见 README 的「已知缺口」）。
            ['岁', null, '炎国', null],
            // 年、夕、令、重岳在 PRTS 的势力栏是「炎-岁」（炎国境内围绕「岁」的一组存在），
            // 因此从笼统的「炎国」细化为「炎-岁」
            ['夕', 'Dusk', '炎-岁', null],
            ['令', 'Ling', '炎-岁', null],
            ['重岳', 'Chongyue', '炎-岁', null],
            ['赫拉格', 'Hellagur', '乌萨斯帝国', '黎博利'],
            ['亚叶', 'Folinic', '罗德岛', '菲林'],
            ['瓦拉', null, '卡兹戴尔', '萨卡兹'],

            /*
             * 《大地巡旅》「国家与地区」卷涉及、此前缺失的关联人物。
             *
             * 种族与代号按 PRTS 干员页逐人核对后补上（2026-09-24）：
             * 帕拉斯＝丰蹄、亚叶＝菲林／Folinic、尤里卡＝札拉克／U-Official。
             *
             * 而**斯卡蒂、幽灵鲨、歌蕾蒂娅、夕、令、重岳在 PRTS 上种族就是「未公开」**，
             * 因此继续留空 —— 这批空缺不是遗漏：「拿不准的字段宁可缺失，也不要写错」
             * 在这里得到了正面印证，随便填一个种族名反而是错的。
             */
            ['帕拉斯', 'Pallas', '米诺斯', '丰蹄'],
            ['斯卡蒂', 'Skadi', '阿戈尔', null, '阿戈尔出身，与深海威胁相关的干员。'],
            // 幽灵鲨与歌蕾蒂娅的势力栏是「阿戈尔 + 深海猎人」；本表一人一职，
            // 因此挂到更具体的「深海猎人」（其上级即阿戈尔），层级本身表达了两者
            ['幽灵鲨', 'Specter', '深海猎人', null],
            ['歌蕾蒂娅', 'Gladiia', '深海猎人', null],

            // ---- 塔卫二（《明日方舟：终末地》）----
            // 代号与种族一律留空：社区资料给的是中文名与转写，没有可靠出处，
            // 按本文件既有原则「拿不准的字段宁可缺失，也不要写错」处理。
            // 管理员与佩丽卡的英文写法在社区里就有多种转写，同样不猜。
            ['管理员', null, '终末地工业', null, '终末地工业协议回收部门的负责人，塔罗斯历 152 年于帝江号苏醒时失去记忆。', null, 'talos'],
            ['佩丽卡', null, '终末地工业', null, '终末地工业的技术监督。', null, 'talos'],
            ['陈千语', null, '终末地工业', null, '终末地工业的特勤干员。', null, 'talos'],
            ['阿伯莉', null, '联盟工团', null, '四号谷地遇袭时牺牲。', null, 'talos'],
        ];

        /*
         * ---- 历史人物（《大地巡旅》各卷的君主、贵族与学者）----
         *
         * 与干员分开的理由见 Character::isHistorical()：干员有代号与干员页，
         * 历史人物有头衔与在位期。把在位区间结构化之后，「1074 年乌萨斯『大叛乱』
         * 发生在谁的在位期内」这类问题变成一次可查的事实，而不是要去详述里翻。
         *
         * 种族一律留空：书里多数时候没有点明，不猜。在位区间只写书里明写的年份 ——
         * 即位年或退位年未载的**只写一端**，绝不补一个看起来合理的数。
         *
         * 格式：[姓名, 阵营, 头衔, 起始年 | null, 结束年 | null, 简介]
         */
        $historical = [
            ['伊戈尔·拉齐萨尔', '乌萨斯帝国', '乌萨斯建国皇帝', 31, null,
                '以骏鹰册封的军事头衔「养熊人」（拉齐萨尔）号令军队，率乌萨斯起义推翻骏鹰王国，31 年攻克圣骏堡并加冕。逝世于攻打东部山脉的军中，在位结束年份未载。'],
            ['阿列克谢·伊戈洛维奇', '乌萨斯帝国', '乌萨斯皇帝 ·「少年皇」', null, null,
                '伊戈尔长子，十六岁于军中即位，把「东征」确立为国策，该战略延续三代皇帝、近百年。'],
            ['弗拉基米尔·伊凡诺维奇', '乌萨斯帝国', '乌萨斯皇帝', null, 1073,
                '亲历四国战争，利用战后接收的高卢技术开启乌萨斯最强盛的时期，发动第九、第十次乌卡战争；1072 年血峰战役惨败后身患重疾，次年驾崩，为「大叛乱」埋下祸根。'],
            ['费奥多尔', '乌萨斯帝国', '乌萨斯皇帝', 1073, null,
                '弗拉基米尔之子，登基后锐意改革、着手削减军队权力，直接引爆「大叛乱」；叛乱平定后仍面对贵族派系、感染者危机与社会不公。'],
            ['赫尔昏佐伦', '莱塔尼亚', '莱塔尼亚皇帝 ·「巫王」', 969, 1077,
                '以雷霆手腕解决即位时的内忧外患（承认叙拉古独立、收权于宫廷），此后转向恐怖统治；1077 年九月起义中于高塔内被双子女皇击败陨落。'],
            ['莉泽洛特', '莱塔尼亚', '莱塔尼亚双子女皇（之一）', 1077, null,
                '「双子」之一，曾是有史以来最年轻的乐团首席；1077 年九月起义中与希尔德加德夜袭巫王高塔，此后共同加冕。'],
            ['希尔德加德', '莱塔尼亚', '莱塔尼亚双子女皇（之一）', 1077, null,
                '「双子」之一，与莉泽洛特同为巫王之后的共治者。'],
            ['科西嘉一世', '高卢', '高卢帝国末代皇帝', null, 1031,
                '被称作帝国历史上最年轻的军事天才；1029 年发动对莱塔尼亚的战争，1031 年四皇会战战败，旗舰被击毁、遗骸不知所终。'],
            ['弗雷德里克三世', '维多利亚', '维多利亚皇帝', null, null,
                '维多利亚历代皇帝中著名的军事家，开展彻底的军事改革、建立由皇帝与议会直接指挥的职业军队，并在四皇会战中亲率主力舰队。'],
            ['亨利·阿利斯泰尔·维多利亚', '维多利亚', '维多利亚末代皇帝', null, 1072,
                '1072 年被公开执行绞刑，无人继位；此事毫无征兆、成为当代最著名的悬案，维多利亚自此皇位空置。'],
            ['威灵顿公爵', '维多利亚', '维多利亚公爵 ·「铁公爵」「帝国丧钟」', null, null,
                '四皇会战中指挥舰队穿插高卢军团后方、截断补给线，扭转整场战役；战后成为毫无疑问的战争英雄，在贵族中获得崇高地位。'],
            ['远逐者', '卡兹戴尔', '第一魔王', null, null,
                '提卡兹文明史上有记载的第一位罪人，也是第一位魔王：他停止以同类为食、被放逐后又戴黑色冠冕而返，带领提卡兹建起第一座卡兹戴尔。'],
            ['戈渎', '卡兹戴尔', '魔王 ·「砌城匠」「大制图师」', null, null,
                '土石之子出身的魔王，在哀愁之地上为新卡兹戴尔砌下第一处地基；因拒绝发动战争，被挚友霸迩萨削去双臂、溺毙水中。'],
            ['霸迩萨', '卡兹戴尔', '谴罚氏族领袖 ·「焕日者」', null, null,
                '炎魔出身的谴罚氏族领袖，主张萨卡兹不可能与任何人侵者共享这片大地；弑杀魔王戈渎后，被奎隆追杀。'],
            ['奎隆', '卡兹戴尔', '游侠王 ·「青色怒火」', null, null,
                '霸迩萨的持剑士、混血萨卡兹，剑术无出其右；魔王戈渎遇害后追杀叛友，其复仇记被传唱为歌谣、改编为戏剧，并册封诸王庭之主。'],
            ['卡门·伊·伊比利亚', '伊比利亚', '伊比利亚大主教', null, null,
                '大静谧后多次回绝将他立为教宗的提议，主持九名大主教抛却原姓、加封圣徒之名，并将国教会改组为「伊比利亚审判庭」。'],
            ['路德维格', '莱塔尼亚', '恩瓦德大区选帝侯 ·「学士」', null, null,
                '第一个把自己的高塔正式确立为「大学」的贵族，近乎狂热地不分尊卑传授知识；在选皇前夜突然发疯暴死，成为帝国史上未决的悬案。'],
            ['恩希欧迪斯·希瓦艾什', '喀兰贸易', '喀兰贸易董事长', null, null,
                '谢拉格贵族出身，曾留学维多利亚、后投奔开斯特公爵；1082 年十四岁继承家业，1090 年创立喀兰贸易，并在 1097 年末完成公司改组。'],
            ['克里夫', '黑钢国际', '黑钢国际创始人 ·「桥夹」', null, null,
                '萨科塔雇佣兵出身，以 1016 年哥伦比亚独立战争为起点建立黑钢；他说自己选择的「解药」不是和平，而是一种规范、可控且具有主动选择权的战争方式。'],
            ['大帝', '企鹅物流', '企鹅物流创始人', null, null,
                '哥伦比亚知名说唱歌手与制作人、黑胶唱片收藏家，1093 年在龙门创办企鹅物流；公司架构近乎没有，招聘由他亲自把关。'],
            ['老鲤', '鲤氏侦探事务所', '鲤氏侦探事务所创始人', null, null,
                '出身炎国腹地商贾世家，早年来到龙门并逐渐成为「龙门的活百科全书」；其关系网小到街头商贩、大到龙门管理者魏彦吾。'],
        ];

        $raceIds = Race::pluck('id', 'name');

        // 人物数据里写的是「龙族」，书里的立目名是「龙」—— 用别名归并到同一条
        $raceAliases = ['龙族' => '龙'];

        $order = 0;

        foreach ($characters as $row) {
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
                    // 「未公开」这类非种族值不建字典条目，落空即为「未知」
                    'race_id' => $race === null ? null : ($raceIds[$raceAliases[$race] ?? $race] ?? null),
                    'kind' => 'operator',
                    'title' => null,
                    'reign_start_index' => null,
                    'reign_end_index' => null,
                    'description' => $profile,
                    'wiki_slug' => $wikiSlug,
                    'sort_order' => $order++,
                ],
            );
        }

        foreach ($historical as [$name, $faction, $title, $fromYear, $toYear, $profile]) {
            Character::updateOrCreate(
                ['slug' => 'chr-'.md5($name)],
                [
                    'name' => $name,
                    'codename' => null,
                    'world' => World::Terra->value,
                    'faction_id' => $factionSlugs[$faction] ?? null,
                    'race_id' => null,
                    'kind' => 'historical',
                    'title' => $title,
                    'reign_start_index' => $fromYear === null ? null : TerraDate::toIndex($fromYear),
                    'reign_end_index' => $toYear === null ? null : TerraDate::toIndex($toYear, 12, 31),
                    'description' => $profile,
                    'wiki_slug' => null,
                    'sort_order' => $order++,
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
                    .'章节定位为粗粒度标注（世界卷 / 国家与地区卷 / 组织卷）。'
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

    /**
     * 由 location 原文匹配地名，取**最长**匹配。
     *
     * 为什么用「包含」而不是精确相等：`location` 是给人看的原文，写法自由
     * （「维多利亚 · 伦蒂尼姆」「乌萨斯－卡西米尔边境」），要求精确相等
     * 等于要求人再维护一份映射表。取最长匹配则让「维多利亚 · 伦蒂尼姆」
     * 落到更具体的「伦蒂尼姆」，而不是笼统的「维多利亚」。
     *
     * 匹配不到就留空：宁可没有结构化链接，也不要挂到错误的地名上 ——
     * 一个错的链接比没有链接更难发现。
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Place>  $places
     */
    private function matchPlace(?string $location, Collection $places): ?int
    {
        if (blank($location)) {
            return null;
        }

        $parentOf = $places->pluck('parent_id', 'id');

        // 深度用于打破平局：「炎国 · 尚蜀」里「炎国」与「尚蜀」同为两字，
        // 而这句话说的是尚蜀 —— 命中写法一样长时，层级更深的那个更具体。
        $depthOf = function (int $id) use ($parentOf): int {
            $depth = 0;

            while (($parent = $parentOf[$id] ?? null) !== null && $depth < 8) {
                $depth++;
                $id = (int) $parent;
            }

            return $depth;
        };

        // 每个地名取「命中写法里最长的那个」作为优先级。别名与下辖地名同样参与，
        // 因此「乌萨斯」能挂到「乌萨斯帝国」（简称写进了别名），
        // 「炎国 · 尚蜀」则按平局规则落到更具体的「尚蜀」。
        $best = $places
            ->map(function (Place $place) use ($location, $depthOf) {
                $lengths = collect($place->matchTokens())
                    ->filter(fn (string $token) => str_contains((string) $location, $token))
                    ->map(fn (string $token) => mb_strlen($token));

                return [
                    'id' => $place->id,
                    'rank' => [$lengths->max() ?? 0, $depthOf((int) $place->id)],
                ];
            })
            ->filter(fn (array $hit) => $hit['rank'][0] > 0)
            // 数组按元素依次比较：先比命中长度，再比深度
            ->sortByDesc('rank')
            ->first();

        return $best === null ? null : $best['id'];
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
                'era' => 'era-797-999',
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
                'era' => 'era-797-999',
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
                'era' => 'era-797-999',
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
                'era' => 'era-797-999',
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
                'era' => 'era-797-999',
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
                'era' => 'era-797-999',
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
                'era' => 'era-797-999',
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
                'era' => 'era-797-999',
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
                'era' => 'era-797-999',
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
                'era' => 'era-797-999',
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
        $places = Place::ofWorld(World::Terra)->get();

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
                // location 保留原文，place_id 是同一句话的结构化链接
                'place_id' => $this->matchPlace($data['location'] ?? null, $places),
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

    // ------------------------------------------------------------------ 字典

    /**
     * 种族字典（《大地巡旅》第四章）。
     *
     * 分两类：
     *
     *  1. **书里立目的种族** —— 附一句取自该章的概要；
     *  2. **人物数据里实际用到、但书中未单独立目的写法**（卡特斯、菲林、库兰塔…）——
     *     只登记名字，描述留空。这不是偷懒：这些写法确实是项目里已存在的值，
     *     要么给它们建条目、要么把人物上的种族抹掉，而后者等于凭空删信息。
     *     描述待读到相应章节再补，**不靠印象写**。
     *
     * 另外，`龙族` 只是人物数据里的写法，书里的立目名是「龙」，用别名归并到同一条。
     */
    private function seedRaces(): void
    {
        $documented = [
            ['萨弗拉', 'Savra', '以沙漠为家，多居萨尔贡，一部分迁居雷姆必拓。身体与尾巴长有鳞片，身手矫健、眼光敏锐，大部分个体全身覆鳞并能改变体表鳞片颜色，配合服装可与沙漠、荒地融为一体。大众文化把「长寿」与「来自萨尔贡」夸张成了固定形象。'],
            ['札拉克', 'Zalak', '人口庞大、分布广泛，近年有相当数量迁往哥伦比亚。头顶生有一对显眼的圆耳朵，耳簇生长自耳朵本身（一小部分菲林也有）；卡西米尔到谢拉格一带的个体耳簇更浓密、尾巴更大。体型普遍较小、动作灵活，听力与反应优异，多从事信使工作。'],
            ['杜林', 'Durin', '身材矮小的尖耳朵种族，以名为「城邦」的地下居住地为社会单位，分布在泰拉各处地表之下。建造与维护城邦的科技远比地表人掌握的更先进；极少透露来历，也看不出干涉地表的意图。所谓「矮小源于幼年疾病」是纯粹的谣言。'],
            ['塞拉托', 'Cerato', '原栖息于萨尔贡绿洲的族群之一，以强健体格著称。与库兰塔一样善于奔跑，但不常长途跋涉。自部落时期起以角为重要武器，爱护角的方式是面对面短途冲锋对撞磨砺，近年也有个体为角加装护甲。保留饲养羽兽的传统。'],
            ['安努拉', 'Anura', '主要居住在萨尔贡与玻利瓦尔，喜欢潮湿、水草丰沛之地。历史上因稀少与居于雨林深处而鲜为外人所见，萨尔贡宫廷长期邀请雨林部落的安努拉前往沙尔－阿加德担任要职。多数传承潜行与隐身的源石技艺，少部分能以自身分泌的剧毒物质为武器。'],
            ['阿达克利斯', 'Archosauria', '崇尚力量、以雨林为家，多生活在萨尔贡东部湿润之地，以部落形式居于沿河地带。有许多用尾巴进行的竞技（如拖拽原木），部分人自称「提亚卡乌」（骁勇善战之人），该认同源自古代萨尔贡皇帝的封赏。水中行动能力出众，游泳是与生俱来的天赋。'],
            ['依特拉', 'Itra', '多居寒冷的高原山地或冰原附近，常见于炎国、谢拉格、乌萨斯与萨米的部分地区。外观与卡普里尼、埃拉菲亚、库兰塔相似，但**不长角**，身体素质略逊于库兰塔。高寒生活使其变得多疑警惕，许多族群保留排外传统。'],
            ['匹特拉姆', 'Petram', '极其少见，许多人从未听说过。避世、居于水源丰富之地，生理构造独特，没有尾巴、角或鳞片等显著外观特征。学界认知极不充分，少数研究者甚至认为该种族并不存在。聚落多掌握高明的熔炼与生产技术，拒绝向外人分享。'],
            ['阿纳缇', 'Anaty', '常与山脉绑定的常见种族，长期生活在远离平原的山区，对客人热情相待。善奔走，崎岖山地如履平地，因此多从事行商与信使；随着移动城市发展，业务也转到城市上。偏远族群保留「成年礼」传统：把将成年者放逐到荒山独立生存一段时间。'],
            ['皮洛萨', 'Pilosa', '极易被误认、极少出现在人群中，连作者也未曾亲见，历史上有过记录但传闻不多。已知的反应迟缓特征见于矿场与漫画的记录：一位皮洛萨矿工因「反应迟缓」被困在铁丝网顶端，而东国漫画则描写过一位把迟缓化为剑理、名震一方的皮洛萨剑豪。'],
            ['德拉克', 'Draco', '在泰拉历史舞台上扮演重要角色的神民种族，人数稀少、零散分布，各地区族群有鲜明的地域特征。常以英雄或统治者的形象出现在神话中，近百年已有可信数据证明其在源石技艺适应性与某些身体素质上确有优势。「德拉克」这一统称直到近千年才在核心圈形成。'],
            ['龙', 'Lung', '炎国独有的神民种族，缔造了古老文明并统治着繁荣开明的帝国。炎国皇室祖先「真龙」以「炎」为名，皇帝继承其名号，皇室自称「真龙一族」；除皇族外炎国还生活着许多以龙自居、同为炎氏赐姓百氏后代的龙。炎国拥有庞大的文书体系，其起源历史比其他种族的传说更翔实可信。'],
            ['麒麟', 'Kylin', '炎国大地上人数稀少但地位崇高的种族，历来是朝堂与府衙的常客。外貌与埃拉菲亚相似，但作为神民种族更为入世。早在百氏之乱前就在炎氏部族中担任侍卫与术师；传说中一位麒麟术师在大湖施展术法使雷霆自湖中生出，那片大湖被称为「雷池」。其招引雷电的技艺传承至今，称为「雷法」。'],
            ['阿戈尔', 'AEgir', '分布从泰拉沿海到内陆水体附近，拥有许多分支，体质差异显著：大多亲近水体但并非都能水下呼吸；对水的依赖超越其他种族，大量失水的后果也更严重，因此常备保湿霜剂与蒸馏装备。迁徙图景多为由泰拉外沿向内陆散布，起源学学者推断其来自海洋。'],
            ['萨科塔', 'Sankta', '头顶光环、身后光翼，是荣受律法的象征，千年以来在拉特兰城过着秩序井然的生活。最神秘之处是律法赋予的共感能力——对他们是像观察表情一样不自觉的行为。随身携带的「铳」被视为来自律法的赐予与信仰的象征，称为「守护」，拉特兰城内每个适龄萨科塔都拥有一把甚至多把。'],
            ['萨卡兹', 'Sarkaz', '较为少见、长相各异的种族，在人类历史中始终遭受其他种族敌视。有以王庭为核心的十支重要氏族，也有歌利亚、阿纳萨等少量非王庭氏族，绝大多数萨卡兹是各氏族的混血。长期流动的流浪生活使许多带萨卡兹烙印的传统悄无声息地消失，现代人熟悉的萨卡兹雇佣兵亦受此影响。极易感染矿石病。'],
        ];

        /*
         * 人物数据里已有、但书中第四章未单独立目的写法。
         * 只登记名字，描述留空 —— 宁可缺失，也不要靠印象写。
         */
        // 丰蹄按 PRTS 干员页补入：帕拉斯的种族在那边是明确写出的「丰蹄」，而书里没有立目
        $registeredOnly = ['卡特斯', '菲林', '库兰塔', '瓦伊凡', '黎博利', '鲁珀', '温迪戈', '精灵', '丰蹄'];

        $order = 0;

        foreach ($documented as [$name, $english, $description]) {
            Race::updateOrCreate(['slug' => Str::slug($english) ?: 'race-'.$order], [
                'name' => $name,
                'english' => $english,
                'description' => $description,
                'sort_order' => $order++,
            ]);
        }

        foreach ($registeredOnly as $name) {
            Race::updateOrCreate(['slug' => 'race-'.md5($name)], [
                'name' => $name,
                'english' => null,
                'description' => null,
                'sort_order' => $order++,
            ]);
        }
    }

    /**
     * 地名树（《大地巡旅》第五章给出的政区层级）。
     *
     * 刻意只收录**书里明确写过**的地名与隶属关系，不做行政区划的推演：
     * 维度的价值在于可检索，而不在于铺满。
     */
    private function seedPlaces(): void
    {
        $factions = Faction::pluck('id', 'name');

        /*
         * [名称, 层级, 上级, 所属政体, 说明, 别名]
         *
         * 数组顺序即插入顺序，父必须在子之前 —— 子级的 parent_id 由 `$ids[上级]` 查得，
         * 上级尚未插入时会静默落成 null（这正是「龙门」此前挂空的原因）。
         */
        $terra = [
            ['维多利亚', 'nation', null, '维多利亚', '由维多利亚王国、塔拉王国、下高卢王国三个法理王国组成的帝国。'],
            ['维多利亚王国', 'kingdom', '维多利亚', '维多利亚', '三个法理王国之一，皇室直属领地与各级贵族领地的核心。'],
            ['塔拉王国', 'kingdom', '维多利亚', '维多利亚', '维多利亚南方地区；书中另有一份塔拉视角的记述，与官方版本出入较大。'],
            ['下高卢王国', 'kingdom', '维多利亚', '维多利亚', '维多利亚声称拥有其完整主权，因此在归属问题上与莱塔尼亚、乌萨斯摩擦不断。'],
            ['伦蒂尼姆', 'city', '维多利亚王国', '维多利亚', '维多利亚帝国的首都与政治、金融、文化中心，被其宣称是「泰拉的中心」；数年前起与外界断绝联系。'],
            ['莱塔尼亚', 'nation', null, '莱塔尼亚', '由九个选帝侯区组成的邦联帝国，没有固定的首都。'],
            ['海登施威尔大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['恩瓦德大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一；「学士」路德维格曾任该区统治者。'],
            ['厄登赫尔大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['鲁珀坎大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['施彤领大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['瓦瑟领大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一；此地选帝侯曾下令铸造杜卡特金币。'],
            ['福特冈大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['奥施登海姆大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['凯普拉尼亚大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['乌萨斯帝国', 'nation', null, '乌萨斯帝国', '以军事力量为国家组织核心的北方帝国；除省份外另有不受省长管辖的集团军属地。', ['乌萨斯']],
            ['圣骏堡', 'city', '乌萨斯帝国', '乌萨斯帝国', '乌萨斯首都，1033 年被迁到巨大的移动平台之上，此后成为帝国最重要的工业中心。'],
            ['格里高利省', 'province', '乌萨斯帝国', '乌萨斯帝国', '临近圣骏堡；1074 年的酒馆冲突与省议会被按军法处决，成为「大叛乱」的直接起因。'],
            ['切尔诺伯格', 'city', '乌萨斯帝国', '乌萨斯帝国', '乌萨斯的重要移动城市；1096 年 12 月 23 日整合运动在此发动事变，是主线的时间原点。'],
            ['哥伦比亚', 'nation', null, '哥伦比亚', '原为维多利亚的开拓区，1016—1019 年独立战争后成为联邦；巴伦矿场等旧源石矿场位于其南部荒地。'],
            ['汐斯塔', 'city', '哥伦比亚', '哥伦比亚', '哥伦比亚境外的自由邦 —— 具有完全自治权的独立城邦，理论上仍属哥伦比亚；附近有高强度火山活动。'],
            ['炎国', 'nation', null, '炎国', '东方大国，三亿人口，下设十九个行政区划；以「真龙」为皇帝，地方最高一级为宣政司，其下设府、属州与县。'],
            ['尚蜀', 'region', '炎国', '炎国', '炎国西南；本地人以四季如春为傲，城市地块分布在山峦之间。'],
            ['龙门', 'city', '炎国', '龙门', '炎国西北边陲的商贸重镇，进入炎国的必经中转站。'],
            ['谢拉格', 'nation', null, '谢拉格', '以耶拉冈德信仰为中心的北方山国，喀兰圣山在其境内。'],
            ['拉特兰', 'nation', null, '拉特兰', '律法庇护之国；拉特兰城在被改建为移动城市时，重工制品进口自伊比利亚。'],
            ['伊比利亚', 'nation', null, '伊比利亚', '大静谧前坐拥南方丰饶半岛、掌控连通内海与开阔海域的峡道；灾后国土沉没、由数个狭长三角洲组成。'],
            ['萨尔贡', 'nation', null, '萨尔贡', '南方大国；泰拉纪年即以其「过去与未来之王」发现圣物之年为元年。'],
            ['卡兹戴尔', 'nation', null, '卡兹戴尔', '萨卡兹的家园所在，游走在核心圈列强之间的狭缝中。'],
            ['卡西米尔', 'nation', null, '卡西米尔', '与乌萨斯有着相似起源、但拥有更精锐骑士团的国家。'],

            /*
             * 以下为第 5 章其余各卷（5.4 高卢 / 5.7 阿戈尔 / 5.11 玻利瓦尔 / 5.12 叙拉古 /
             * 5.14 米诺斯 / 5.15 萨米 / 5.16 雷姆必拓 / 5.17 炎国 / 5.18 东国）。
             *
             * 至此第 5 章十九卷的政区全部入树。此前只收了 10 卷，代价是条目的 `place_id`
             * 挂不上去（「叙拉古」「玻利瓦尔」这些 location 是照原文抄的短名），
             * 而挂不上不是留空，是**静默缺失**。
             */
            ['高卢', 'nation', null, '高卢', '1031 年四国战争的战败方：首都林贡斯被联军夷平、国土被瓜分，帝国从地缘政治版图上消失；如今只以「高卢区」与三千万说高卢语者的文化认同延续。'],
            ['阿戈尔', 'nation', null, '阿戈尔', '海中的知识与技术文明。其先民从各路水道汇入大海，后代即伊比利亚所称的「岛民」；如今面临海嗣的威胁。'],
            ['赫库兰尼姆', 'city', '阿戈尔', '阿戈尔', '阿戈尔的城市；以地热能为主要能源，开掘了三百余条能源井道，隔热穹顶可承受四级以下强度的火山喷发。'],
            ['玻利瓦尔', 'nation', null, '玻利瓦尔', '泰拉西部。三百多年间从富裕的伊比利亚玻利瓦尔总督区沦为「玻利瓦尔地区」；莱塔尼亚与哥伦比亚先后试图控制它，都没能成功。'],
            ['叙拉古', 'nation', null, '叙拉古', '家族割据城邦的地区，967 年脱离莱塔尼亚；此后长达七十年的内乱，直到西西里夫人以灰厅与城邦联合议事会重新立起秩序。'],
            ['西西里', 'city', '叙拉古', '叙拉古', '灰厅所在地。建城之初由数个小家族共治，一场政变后为西西里家族独占，该家族随着灰厅成立而走到尽头。'],
            ['米诺斯', 'nation', null, '米诺斯', '曾被萨尔贡占领的诸城邦；英雄崇拜与雕塑艺术的故乡，萨尔贡统治时期宏伟的英雄巨像尽数被拆毁。'],
            ['萨米', 'nation', null, '萨米', '大地的止境，森林与冻原的领土；体制最为独特的「国家」，以雪祀大会与独特的法术闻名。'],
            ['察帕特', 'settlement', '萨米', '萨米', '萨米南方的度假小镇，邻近楚阿维亚利湖。'],
            ['雷姆必拓', 'nation', null, '雷姆必拓', '泰拉东南方，塔尔干主矿脉所在地；以矿业厂区为单位构成的松散联合体，没有强力中央，只有各矿区自己的联防队。'],
            ['塔尔干主矿脉', 'landmark', '雷姆必拓', '雷姆必拓', '已探明的三大源石主矿脉之一，储量最大、环境最特殊：地表源石矿体最密集，天灾也最频繁剧烈。'],
            ['尤立卡自治州', 'region', '雷姆必拓', '雷姆必拓', '1090 年围栏事件后成立的自治州。'],
            ['东国', 'nation', null, '极东', '最东端的国家。以《皇敷记》为国书，经历御神神话、在与炎国的文化交流中形成律政国制，以及武家崛起的御神川幕府时代。'],
        ];

        $ids = [];

        foreach ($terra as $order => $row) {
            [$name, $kind, $parent, $faction, $description] = $row;
            // 别名可省：多数地名只有一个写法，只有书里确实并用过两种写法时才记
            $aliases = $row[5] ?? [];

            $model = Place::updateOrCreate(['slug' => 'place-'.md5($name)], [
                'name' => $name,
                'aliases' => $aliases,
                'kind' => $kind,
                'parent_id' => $parent === null ? null : ($ids[$parent] ?? null),
                'faction_id' => $factions[$faction] ?? null,
                'world' => World::Terra->value,
                'description' => $description,
                'sort_order' => $order,
            ]);

            $ids[$name] = $model->id;
        }

        /*
         * 塔卫二的地名与条目一一对应，同样只收出处里出现过的。
         *
         * 结构同为 [名称, 层级, 上级, 说明]，顺序同样是父先于子 ——
         * 「供能高地」是**四号谷地境内**的高地（超域试验场所在），不是与它并列的一级地名，
         * 上面泰拉那棵树踩过的坑（上级不存在时静默挂空）这里不能重踩。
         *
         * 「清波寨」的上级在出处里没有写明，因此留空：宁可缺失也不要猜。
         */
        $talos = [
            // 文明环带的依据是它自己的描述：「塔卫二上的人类聚居带，四号谷地位于其边缘地区」。
            // 它同时是阵营表里的一行（kind=territory）——那行是历史遗留，
            // 归入「地域」之后它不再出现在资料集的「组织」页，这里补上它的地名节点，
            // 否则它会在两个页面之间消失：既不算组织，又没有地方可查。
            ['文明环带', 'region', null, '塔卫二上的人类聚居带，四号谷地位于其边缘地区。'],
            ['四号谷地', 'region', '文明环带', '开拓区之一，终末地工业以此地为据点建立工业基地。'],
            ['供能高地', 'landmark', '四号谷地', '四号谷地境内的高地，超域试验场所在。'],
            ['清波寨', 'settlement', null, '塔罗斯历 70 年建立的聚落。'],
        ];

        $talosIds = [];

        foreach ($talos as $order => [$name, $kind, $parent, $description]) {
            $model = Place::updateOrCreate(['slug' => 'place-'.md5($name)], [
                'name' => $name,
                'kind' => $kind,
                'parent_id' => $parent === null ? null : ($talosIds[$parent] ?? null),
                'faction_id' => null,
                'world' => World::Talos->value,
                'description' => $description,
                'sort_order' => $order,
            ]);

            $talosIds[$name] = $model->id;
        }
    }

    /**
     * 词条：书里给出专门解释的术语与专名。
     *
     * 释义一律是本仓库据书中相应章节转写的**概括**，不是原文摘录 ——
     * 词条不附引文，也就不受「引用可定位」那条闸门约束，因此这里用 `origin`
     * 指回章节，让读者自己回去核对。
     */
    private function seedTerms(): void
    {
        $terms = [
            ['结晶时代', 'concept', '《大地巡旅》年表只收录这段时期的用语：作者称它始于泰拉历 797 年（七城联邦建成第一座现代移动城市），并延续至今。年表标题行的「结晶时代」即由此而来。', '附录 · 泰拉纪年'],
            ['移动城市', 'concept', '为躲避周期性天灾而发展出的可整体迁移的城市形态；城市本身因此成为最重要的战略资产，「开走一座城」在泰拉是现实的政治手段。', '第二章 · 工业科技'],
            ['天灾', 'concept', '周期性扫过泰拉、所过之处地表大面积源石结晶化的灾害。其规律性直接塑造了泰拉的生存方式。', '第一章 · 天灾'],
            ['矿石病', 'term', '源石接触引发的疾病。各国对感染者的政策差异极大：乌萨斯的境遇最为悲惨，维多利亚多实行隔离或收押，莱塔尼亚相对宽容但在居住上仍做分隔。', '第一章 · 矿石病'],
            ['源石技艺', 'term', '以源石为媒介施术的技艺体系。在莱塔尼亚被系统化为一门以「声音」为核心载体的艺术与文化传统，在乌萨斯则被纳入军用法术的编制。', '第一章 / 第二章'],
            ['金律乐章', 'object', '莱塔尼亚的立国宪章，由九大部落与叙拉古共同缔结、分为三大乐章，同时是一部能够真实演奏的乐章；除原典外有十份抄本分交九个选帝侯区与叙拉古自治领。历史上几乎未曾变动，唯独巫王登基时删去了叙拉古自治领的内容。', '莱塔尼亚卷'],
            ['选帝侯', 'term', '莱塔尼亚九个大区的最高长官，全国仅此九人拥有选出皇帝的选举权与被选举权。俗语「先有诸侯，再有皇帝」说的就是这套结构。', '莱塔尼亚卷'],
            ['高塔贵族', 'term', '莱塔尼亚的贵族体制：贵族传授知识与技艺、管理领地的场所多在独立的高塔中，塔越高通常主人地位越高。', '莱塔尼亚卷'],
            ['帝政主义', 'concept', '林贡斯重建中形成、并以高卢帝国命名的建筑风格（巨大落地窗、铜质装饰与大理石建筑群）。帝国倾覆后仍持续影响周边国家，被视为高卢文化延续的一种表现。', '高卢卷'],
            ['复国主义者', 'term', '高卢灭亡后自称「复国者」的高卢人，以文化而非血统定义「高卢人」，认为这是一种超越地区、时间、种族和血统限制的身份。其话语对后世政治思潮的影响不亚于帝国覆灭本身。', '高卢卷'],
            ['八大公爵', 'term', '皇位空置后暂时击败其他竞争者、各自实际控制一方领土的八名维多利亚公爵：威灵顿、开斯特、温德米尔、诺曼底、高多汀、亚伯科恩、法夫、阿什沃思。其中权势最盛者为前三名。', '维多利亚卷'],
            ['铁公爵', 'proper', '威灵顿公爵的称号。四皇会战初期，他指挥舰队穿插到高卢军团后方、截断补给线，扭转了整场战役，因此又被称为「帝国丧钟」。', '维多利亚卷'],
            ['集团军', 'term', '乌萨斯帝国军的最高一级编制，统帅由皇帝直接授意、在战略上有较高自主权。原有九支，因「大叛乱」中第六、第八集团军被整建制歼灭而减为七支。', '乌萨斯卷'],
            ['功勋贵族 / 产业贵族', 'term', '乌萨斯的两类贵族：前者源于军功爵制、随历史不断膨胀，即「旧贵族」；后者由非军事出身者缴纳巨额税金晋升而来，即「新贵族」。', '乌萨斯卷'],
            ['盖尔王', 'proper', '维多利亚皇室以帝国贵族体系规制宗室时，给曾统治塔拉一方的德拉克家族成员留下的敕封头衔。今时常有「盖尔为王本身姓名」的说法，属讹传。', '维多利亚卷 / 塔拉卷'],
            ['提卡兹', 'proper', '萨卡兹的自称，意为「拥有家园之人」；第一座卡兹戴尔陷落后，「提卡兹」成为「萨卡兹」，即「丧家之人」。', '卡兹戴尔卷'],
            ['谴罚氏族', 'proper', '以炎魔为主导、吸纳流亡萨卡兹的复仇之军。在戈渎、霸迩萨、奎隆三位未来魔王相遇后，它成为卡兹戴尔重建的武力后盾；「谴罚」后来演变为一种主张复仇归乡的主义。', '卡兹戴尔卷'],
            ['圣愚', 'term', '乌萨斯文化中尊重「圣愚」的传统：那些看似疯癫却时常表达对事物深刻感受的人被认为智慧超脱世俗局限。作者称其真相更接近于「诅咒」。', '乌萨斯卷'],
            ['莱茵生命', 'proper', '哥伦比亚的大型科研企业。1099 年 12 月 5 日其总辖构件科的奥利维亚·赫默在《特里蒙科学伦理联合宣言》发布会上公开要求为科学建立规则。', '组织卷'],
            ['巴伦基地', 'proper', '黑钢国际的行政总部、后勤补给中心与人员培训中心，由回收的巴伦矿区采矿作业平台与一艘哥伦比亚出口的废弃移动军舰组合改建而成，1086 年交付使用。', '组织卷'],
        ];

        foreach ($terms as $order => $row) {
            [$name, $category, $definition, $origin] = $row;

            /*
             * 第 5 位（可省）是世界归属。
             *
             * 这一批词条全部出自《大地巡旅》—— 一部**泰拉视角**的著作（作者是泰拉的历史学者），
             * 因此缺省即泰拉。将来为塔卫二补词条时在那一条上写 'talos'；
             * 若是两个世界都成立的概念（如「源石」），写 **null** —— 它会在两页都列出。
             *
             * 用 array_key_exists 而不是 `??`：null 是有意义的值（通用），
             * 而 `??` 会把显式写的 null 当成「没写」而回落到泰拉。
             */
            $world = array_key_exists(4, $row) ? $row[4] : World::Terra->value;

            Term::updateOrCreate(['slug' => 'term-'.md5($name)], [
                'name' => $name,
                'category' => $category,
                'definition' => $definition,
                'origin' => $origin,
                'world' => $world,
                'sort_order' => $order,
            ]);
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

            // ================= 组织卷：泰拉各地的组织档案 =================
            /*
             * 书里的第五章按「国家与地区」编排，第六章换了一个维度：**组织**。
             * 这里沿用与国家卷完全相同的处理方式（无年份、无引文、needs_review）——
             * 组织档案讲的是「它是什么、怎么来的」，同样不落在某个具体年份上。
             */
            [
                'title' => '莱茵生命：科学伦理的临界点',
                'section' => '组织卷',
                'era' => null,
                'summary' => '哥伦比亚的大型科研企业。1099 年 12 月 5 日，总辖构件科的奥利维亚·赫默在《特里蒙科学伦理联合宣言》发布会上公开指出：这片土地上的人总在用「必要的代价」「梦想的牺牲」这类托辞，回避那些在探求真理过程中成为代价的人。',
                'details' => '她点到的例子包括风口浪尖上的莱茵生命、早已有结局的洛肯水箱，以及「我们都看到的特莱顿工厂爆炸」，并呼吁建立规则去约束科学这种力量。书中该篇为第二版：作者说因「莱茵生命的新消息不断传来」而重写——同期刊出的消息是，能量科主任斐尔迪南·克鲁尼被哥伦比亚联邦执法者带走，据称涉及倒卖技术文件。\n\n背景据《大地巡旅》组织卷「莱茵生命」节。',
                'factions' => [['莱茵生命', 'involved'], ['哥伦比亚', 'involved']],
                'tags' => ['科研'],
            ],
            [
                'title' => '黑钢国际：从巴伦矿场到私人安全承包商',
                'section' => '组织卷',
                'era' => null,
                'summary' => '由萨科塔雇佣兵「桥夹」克里夫创办的哥伦比亚私人安全承包商。克里夫说，1016 年的哥伦比亚独立战争是他建立黑钢的起点——他选择的「解药」不是和平，而是一种对雇佣兵而言规范、可控且具有主动选择权的战争方式。',
                'details' => '转折点是 1083 年的巴伦矿场事件：塔山生物科技在哥伦比亚军方支持下重启这座废弃源石矿场，借开采掩人耳目，在场内设「源石变异实验室」，把活性源石植入受试物种体内；实验生物突破安保并在实验区造成严重伤亡后，塔山不得不雇用黑钢解救受困人员、清剿逃逸生物。黑钢经二十三天作战完成任务，民间顾问珍妮特·朗费罗博士参与其中；《特区电讯报》随后披露事件真相，国防部部长康博特引咎辞职，黑钢佣兵因之名声大振，并获梅兰德基金会「公民英雄奖」。\n\n1086 年「黑钢国际」正式注册，巴伦基地由回收的矿区采矿作业平台与一艘哥伦比亚出口的废弃移动军舰组合改建而成，兼作行政总部、后勤补给中心与新兵中心；新兵中心两年培训的淘汰率约百分之七十六。公司形成行政总部与三大业务部门的框架：武装人力派遣、装备与应用技术、矿石病与生化威胁处置（后者辖有朗费罗博士领导的 B.P.R.S.，是行业内少有的能处置工业源石污染与感染生物危机的机构）。\n\n背景据《大地巡旅》组织卷「黑钢国际」节。',
                'factions' => [['黑钢国际', 'involved'], ['哥伦比亚', 'involved']],
                'tags' => ['雇佣兵'],
            ],
            [
                'title' => '喀兰贸易：雪境之国的门面',
                'section' => '组织卷',
                'era' => null,
                'summary' => '成立于 1090 年的谢拉格企业，经营范围从日用品进出口到军火交易与土地买卖，也涉足教育、科技与建设；对绝大多数泰拉人来说，它是认识谢拉格的唯一途径。',
                'details' => '公司名字取自谢拉格境内的喀兰圣山——当地人尊奉那里为神明耶拉冈德的圣地。这个命名直接彰显了它与谢拉格官方非同寻常的关系，也解释了为什么常见其员工代表谢拉格出席外交场合。创始人恩希欧迪斯·希瓦艾什曾留学维多利亚，1082 年（十四岁）从去世的父母手里继承家业，随后投奔远在维多利亚的开斯特公爵；公司自称历史可追溯到 1065 年上代族长回国后建立的工厂与维多利亚式企业——那些产业当时遭到本地保守派的强烈反对。\n\n1097 年末公司大规模改组：原本一家独大的希瓦艾什家族吸纳了布朗陶与佩尔罗契两大家族的贵族进公司任职，开始允许非谢拉格出身的外聘专家担任中层以上职位，董事长也与其妹——蔓珠院的领导者、圣女——就未来发展达成共识。此后确定垂直管理的运作模式（决策者 / 管理者 / 执行者三级），组织上由董事会、生产部门、职能部门与咨询委员会组成；其中咨询委员会独立于常规架构、直属董事会，成员多为贵族与蔓珠院代表。为保证它始终为谢拉格利益服务，蔓珠院与三族议会同公司达成了一系列协议，包括定期提交工作报告、国防相关业务须先行协商。\n\n背景据《大地巡旅》组织卷「喀兰贸易」节。',
                'factions' => [['喀兰贸易', 'involved'], ['谢拉格', 'involved']],
                'tags' => ['贸易'],
            ],
            [
                'title' => '锈锤：对「文明」本身的复仇',
                'section' => '组织卷',
                'era' => null,
                'summary' => '活动于荒野的松散团体。哥伦比亚及卡西米尔等地的官方发言人多次强调它的恐怖主义背景，黑钢国际、雷神工业等企业则谴责其抢劫与杀戮并实施反制。它的宗旨、成立时间、成员数量与活动范围都不明，唯一清晰的是——极其危险。',
                'details' => '「锈锤」之名首次为公众所知是在 1040 年春：一支运送日用品的商队在哥伦比亚西部救下几名衣不蔽体、精神错乱的拓荒者。据其描述，上百名衣着褴褛的人堵住去路，在交涉时突然发难，当众焚烧车队携带的物资与哥伦比亚旗帜，并要幸存者将这起事件传扬出去、着重强调他们叫作「锈锤」。1042 年，全哥伦比亚共有五十二起与之相关的重大犯罪案件，受害者多为出行携带国旗、带官方背景的组织以及大企业相关人员，普通出行者完全没有遭遇袭击；其行动预伏多个地点、战术针对不同国家的车队，且在确认已留下恐惧之后才离开。\n\n他们高喊的口号自称「荒地的征服者」与「文明的复仇者」，主张对文明造物实施毁灭、主张泰拉荒野的「回归」；来自哥伦比亚与卡西米尔的报告称，锈锤把移动城市视为文明的终极象征。与其行动准备之充分相反，其组织架构非常松散凌乱：五十余年来各地都有目击报告，多以小团体活动，不同地区的小团体行事风格截然不同，只在名号、劫掠方式与所持主张上保持一致。1092 年，哥伦比亚、卡西米尔与维多利亚三地与「锈锤」相关的案件共有二百五十多起，其中半数以上经调查后被鉴定为冒用名号。\n\n背景据《大地巡旅》组织卷「锈锤」节。',
                'factions' => [['锈锤', 'instigator']],
                'tags' => ['治安'],
            ],
            [
                'title' => '雷神工业：高卢匠人的余波',
                'section' => '组织卷',
                'era' => null,
                'summary' => '产品线几乎无所不包的哥伦比亚工业集团。书中把它视作 1031 年四国战争后格局变化的一个佐证：接受了旧体系培养的匠人前往哥伦比亚落地生根，又依靠新格局享用者的身份成为跨国企业家。',
                'details' => '创始人之一亨利·布洛赫原是高卢第三军械研究所的重要研制员。战争后半程研发经费与周期一再缩水，他对僵硬的帝国体系彻底失望；高卢战败前，他拿走军械所里能带走的全部技术图纸，带妻儿汇入逃离的人流。另一位创始人欧文·布林雷是经历过哥伦比亚独立战争的「年轻老兵」，1035 年前后两人在一家名为「雷神」的路边酒馆相遇，随后分工合作——欧文负责推进生产与寻找销路，亨利在宅中完成研发订单。由于出自其手的装备在可靠性上远高于同类竞争者，伴随着拓荒需求增长，「经历过四国战争考验的武器设计者」的传说在荒地火旁流传开来，两人遂以「雷神」统一称谓；亨利不愿与「战争」「军火」这样的字眼绑定，公司至今定位仍是「工业集团」。\n\n如今它是一家以研发各式源石电子装备见长的综合工业集团，总部位于布林雷家曾经的宅基地上，主要股东是两位创始人各自的孩子，欧文的儿子伯尼·布林雷更热衷行政事务、成为主要负责人。其组织体系中最核心的是源石晶体单元相关研发部门——这种高度集成的电路单元是当前收益最高的产品，生产极度依赖研发与哥伦比亚学界的支撑。公司信条是「创新成就未来」。\n\n背景据《大地巡旅》组织卷「雷神工业」节。',
                'factions' => [['雷神工业', 'involved'], ['哥伦比亚', 'involved'], ['高卢', 'involved']],
                'tags' => ['工业'],
            ],
            [
                'title' => '太阳谷机械工业：厂区即生活',
                'section' => '组织卷',
                'era' => null,
                'summary' => '一家组织形态与泰拉多数公司都不同的工业集团：厂区里有完整的生活社区——医疗设施、集市、食堂、子弟学校、公共浴室、击球场，甚至供人休憩的俱乐部。住在这里的全是它的员工，口号是「工作，是为了更好的生活」。',
                'details' => '组织上它不像泰拉其他地方常见的公司形式，而由总秘书处、总办公室、总计划部、总服务部与各地分厂组成：总秘书处决定集团的重大事务，成员都是卡特斯家族内有权势的老人；总办公室负责内部行政；总计划部调度分布于泰拉各地的分厂生产；总服务部管理集团及分厂提供的配套设施福利，并对外兜售自身的基础设施服务。厂区内的公共设施使用内部凭证，且按级别区分（例如公共浴室二楼为级别较高者准备）。\n\n集团在萨尔贡与玻利瓦尔有业务，每年都有相当多的当地人竞争进入；对员工而言，为集团在各地的买卖感到骄傲并非抽象情绪，而与个人福利保障直接相关。子弟学校免费，击球队由职工子弟组成。\n\n背景据《大地巡旅》组织卷「太阳谷机械工业」节。',
                'factions' => [['太阳谷机械工业', 'involved']],
                'tags' => ['工业'],
            ],
            [
                'title' => '企鹅物流：随心所欲的企业',
                'section' => '组织卷',
                'era' => null,
                'summary' => '创立于 1093 年、总部设在炎国龙门的小型私人物流企业，提供要人护卫与货物运输等常规服务，偶尔也承接一些上不得台面的灰色业务；创始人是知名说唱歌手大帝。',
                'details' => '大帝宣布在龙门开设这家物流企业时，大部分人把它当成一种行为艺术；直到它在龙门建立员工安全屋、着手与独占龙门物流业鳌头的肇驰物流开展商业接洽、并同龙门市政府达成一系列合作，业界才大跌眼镜。人们转而研究它的运作方式，却发现它的组织架构近乎没有、业务方向随心所欲——与其说它引领了一种商业模式，不如说是大帝性格与处事方式的成功。\n\n公司成员原则上只有两种身份：彼此间不存在地位差距的雇员，以及大帝本人；每一位员工都由大帝亲自把关招聘。理念是「使命必达」，至于如何达成、中间经过什么波折，员工可以自由发挥，大帝也鲜少过问。与龙门官方合作之后，龙门的物流企业数量出现一次爆发性增长，又在几个月后纷纷倒闭。\n\n背景据《大地巡旅》组织卷「企鹅物流」节。',
                'factions' => [['企鹅物流', 'involved'], ['龙门', 'involved']],
                'tags' => ['物流'],
            ],
            [
                'title' => '鲤氏侦探事务所：龙门的活百科全书',
                'section' => '组织卷',
                'era' => null,
                'summary' => '位于炎国龙门的私人侦探事务所，业务从寻人寻址、调查外遇到搜证录音、法律咨询，并承接来自官方的各项委托。创始人老鲤人脉极广，事务所因此成了回旋于各方势力之间的「第三方中立咨询顾问」。',
                'details' => '老鲤出身炎国腹地的商贾世家，早年来到龙门这一炎国西北边陲的商贸重镇。精于人情世故的他很快站稳脚跟，并逐渐成为「龙门的活百科全书」；虽然本人深居简出，但小到街头商贩、大到龙门的管理者魏彦吾，都曾是他那张庞大关系网的一环，在许多关键场合他也频频现身。\n\n因炎国律法要求，他注册了「鲤氏侦探事务所」这一名号，但当时事务所只有他一人承接业务、也没有正式的办公室；后来他先后收留了一些年轻人、低价租下办公室，事务所才名副其实起来。平日只接待亲自登门的客户，若客户愿意显示诚意与气度，或实在无法忍受事务所杂乱的环境，也可以把老鲤请出他的安乐窝，在龙门大大小小的社交场所谈事。关于他的过去流传着许多版本，真实性从未得到本人证实。\n\n背景据《大地巡旅》组织卷「鲤氏侦探事务所」节。',
                'factions' => [['鲤氏侦探事务所', 'involved'], ['龙门', 'involved'], ['炎国', 'involved']],
                'tags' => ['治安'],
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
            $places = Place::ofWorld(World::Talos)->get();

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
                'place_id' => $this->matchPlace($data['location'] ?? null, $places),
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
