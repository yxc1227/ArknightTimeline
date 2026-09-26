<?php

namespace Database\Seeders;

use App\Enums\ChangeOrigin;
use App\Enums\CharacterKind;
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
use App\Support\CharacterAvatars;
use App\Support\CharacterSplashes;
use App\Support\CorpusLocator;
use App\Support\EntityEmblems;
use App\Support\TerraDate;
use App\Support\TerraTourCorpus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Generator;

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
        /*
         * 名单接在人工维护的那批之后（同名的一律不碰），也接在**地名树之后**：
         * 出身地要拿地名树去认（「谢拉格」→ 谢拉格节点），
         * 顺序错了不会报错，只会一条都对不上。
         */
        $this->seedOperatorRoster();
        // 头像接在名单之后：先有人物，才谈得上把头像挂到人身上
        $this->seedCharacterAvatars();
        // 立绘同理，只是一个人可能挂上精英一与精英二两张
        $this->seedCharacterSplashes();
        // 徽记接在地名树与阵营之后：它要往 places.logo 与 factions.logo 各写一次
        $this->seedEntityEmblems();
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
            /*
             * 罗德岛的内部编制。
             *
             * 「精英干员」「S.W.E.E.P.」「行动组A4」「行动预备组A1/A4/A6」都是
             * PRTS 势力表里的取值 —— 它们不是并列的组织，而是**罗德岛的下属编制**，
             * 因此挂成子级：「按罗德岛筛选」会连它们一起带出来，反之不然。
             */
            ['name' => '罗德岛', 'kind' => 'enterprise', 'full_name' => '罗德岛制药公司', 'color' => '#38bdf8',
                'description' => '以一艘陆行舰为基地的制药公司：本舰分顶层、上层、中层与下层甲板，配有舰载天灾预警系统与开采模块，'
                    .'上层甲板有行动指挥舰桥、食堂与住院中心，下层是工程走廊，舰上运行着 PRTS 系统。舰上的实践也颇有特色：'
                    .'干员的源石技艺适应性被分为卓越、优良、标准、普通与缺陷五级 —— 与为普通人服务的通用等级不同，'
                    .'这是衡量干员能力强度的指标；全体干员标配监测手环，与工程部搭建的舰内网络共同构成感染预警控制系统；'
                    .'自研的抑制装置能在血液源石结晶密度异常时注入少量抑制剂，遏制感染造成的源石技艺失控；'
                    .'它还以低价抑制剂压住了市面上的药物溢价。医疗部设有一种生物处理装置 —— '
                    .'让临终的感染者不再有被抛弃的感觉。工程部下设诸多工坊，改造过的东西从电热水壶到缴获的武装无人机都有；'
                    .'它在拉特兰城等地设有办事处。',
                'children' => [
                ['name' => '精英干员', 'kind' => 'agency', 'color' => '#0ea5e9'],
                ['name' => '医疗部', 'kind' => 'agency', 'color' => '#22d3ee'],
                ['name' => 'S.W.E.E.P.', 'kind' => 'agency', 'color' => '#0891b2'],
                ['name' => '行动组A4', 'kind' => 'agency', 'color' => '#38bdf8'],
                ['name' => '行动预备组A1', 'kind' => 'agency', 'color' => '#60a5fa'],
                ['name' => '行动预备组A4', 'kind' => 'agency', 'color' => '#7dd3fc'],
                ['name' => '行动预备组A6', 'kind' => 'agency', 'color' => '#a5b4fc'],
            ]],
            ['name' => '巴别塔', 'kind' => 'society', 'full_name' => '巴别塔（罗德岛前身）', 'color' => '#6366f1', 'description' => '罗德岛的前身组织，卡兹戴尔内战期间活跃，后改组为罗德岛。'],
            ['name' => '整合运动', 'kind' => 'society', 'full_name' => '感染者反抗组织 · 整合运动', 'color' => '#f87171',
                'description' => '感染者反抗组织。书中谈矿石病如何把感染者推向对抗时，把整合运动举为其中最典型、最突出的案例：'
                    .'崩解现象的传染风险断绝了感染者与非感染者共同生活的可能，被背离社会主流的人因此更倾向于对抗。'],
            ['name' => '乌萨斯帝国', 'kind' => 'polity', 'color' => '#94a3b8',
                'description' => '建立在骏鹰王国废墟上的北方帝国：黎博利神民骏鹰曾统治这片土地，被征作苦役的乌萨斯先民积下数百年的怒火；'
                    .'梦魔远征击碎骏鹰的权威后，乌萨斯族军官伊戈尔于泰拉历 31 年领导起义、加冕称帝，'
                    .'这是史上第一次先民有组织反抗神民而成功的样例。书中说，一系列严重的政治与社会危机正把庞大的帝国推向剧变的转折点。',
                'children' => [
                ['name' => '乌萨斯军事委员会', 'kind' => 'agency', 'color' => '#64748b'],
                // 名字里自带归属：乌萨斯的学生自治团体
                ['name' => '乌萨斯学生自治团', 'kind' => 'society', 'color' => '#7e8ea3'],
                // 第 6.9「组织名录」：乌萨斯少数乐于与外国企业合作的公司，也是外国企业进入乌萨斯的常见中介
                ['name' => '罗斯托夫实业', 'kind' => 'enterprise', 'color' => '#cbd5e1',
                    'description' => '承接采矿平台建设、移动城市地块检修等大型工程的乌萨斯工程企业。'
                        .'经营者罗斯托夫家族原是一支小贵族，在「大叛乱」后决定从商；家族对时势的审慎判断让企业在切尔诺伯格－龙门事件中失去总部与最大客户后仍存活至今。'],
            ]],
            // 龙门从顶层挪进炎国名下：PRTS 的势力取值是「炎-龙门」，
            // 而我们自己的地名树里「龙门」本来就在「炎国」之下 —— 两处不该各说各话。
            // 它自己还有下属（企鹅物流、鲤氏侦探事务所），因此阵营树必须能落三层
            ['name' => '维多利亚', 'kind' => 'polity', 'full_name' => '维多利亚王国', 'color' => '#a78bfa',
                'description' => '泰拉核心圈军事实力最强大、经济最繁荣的霸权帝国：本土与多个核心圈国家相邻，另有若干开拓区与飞地。'
                    .'以强权与扩张为纲，靠霸权与掠夺开拓区维持贵族利益；王公贵族借此握有巨大权力，而皇家的实力日渐衰弱。',
                'children' => [
                // 塔拉是维多利亚南方的法理王国（地名树里已如此），PRTS 也为它单列了一个势力取值
                ['name' => '塔拉', 'kind' => 'polity', 'color' => '#c4b5fd',
                    'description' => '维多利亚南方边界模糊的地区（边界随当地移动城市的巡弋路线变化），廷盖语居民的家园：'
                        .'自约泰拉历前 100 年起由来自维多利亚的德拉克游牧部族统治，后建立塔拉王国。'
                        .'书中特地为它留了一份「另一个版本」的地区简史，并声明与维多利亚卷的记述出入较大；'
                        .'当地新政权与维多利亚之间可能发生的分离，是一桩有争议且正在发展的事件。'],
                // 《大地巡旅》第 6.9「组织名录」与维多利亚卷提到的本地机构
                ['name' => '维多利亚银行', 'kind' => 'enterprise', 'color' => '#ddd6fe',
                    'description' => '发行维多利亚镑纸币的银行。'],
                ['name' => '维多利亚皇家科学院', 'kind' => 'agency', 'color' => '#8b5cf6',
                    'description' => '始建于 737 年的官方资助科研组织，宗旨为「促进科学事业的发展及表彰杰出的科学家」，皇帝为其庇护人；'
                        .'院士身份是维多利亚学术研究者的最高成就。'],
                ['name' => '王者之杖', 'kind' => 'society', 'color' => '#c4b5fd',
                    'description' => '由年轻人组成的小组织，宗旨是传承古之贤人的遗风，以知识与技能帮助遇到困难的人。'],
            ]],
            ['name' => '卡西米尔', 'kind' => 'polity', 'full_name' => '卡西米尔骑士之国', 'color' => '#f472b6',
                'description' => '库兰塔的骑士之国：天马一族在此立国，骑士团源自古时全民习武的军事传统，后来在梦魔可汗的会战中彻底溃败。'
                    .'今天的卡西米尔以骑士竞技为支柱，治权在由骑士构成的监正会手里，'
                    .'而经济与公共事务实际握在商业联合会手中 —— 它被称作「影子政府」，与监正会分庭抗礼。',
                'children' => [
                // 卡西米尔卷：国家机构、骑士体制与「影子政府」三条线
                ['name' => '监正会', 'kind' => 'agency', 'color' => '#db2777',
                    'description' => '由骑士构成、议行合一的委员会，负责组建政府并行使治权；大骑士长为行政长官兼国家元首。'],
                ['name' => '骑士协会', 'kind' => 'agency', 'color' => '#f9a8d4',
                    'description' => '专职协调骑士事务的机构，保留着骑士家族相关的长骑制度。'],
                ['name' => '国民院', 'kind' => 'agency', 'color' => '#f9a8d4',
                    'description' => '卡西米尔的立法与司法机构，是它最晚建立的重要国家机构。'],
                ['name' => '商业联合会', 'kind' => 'agency', 'color' => '#fbcfe8',
                    'description' => '民间经济组织，实际控制着卡西米尔的经济与公共事务，被视作「影子政府」，与监正会分庭抗礼。'],
                ['name' => '征战骑士团', 'kind' => 'military', 'color' => '#be185d',
                    'description' => '《征战骑士法》之后的国家军队：由贵族骑士团改为数十个直属监正会、实施募兵制的职业军队。'],
                // 第 6.9「组织名录」里的卡西米尔企业
                ['name' => '玫瑰报业联合集团', 'kind' => 'enterprise', 'color' => '#ec4899',
                    'description' => '卡西米尔的出版业巨头，旗下含《中央新闻报》《红酒报》《四城竞技报》等 172 家媒体，'
                        .'其理念是「足够新鲜的见闻即可作为新闻」；近年也提供城际网络门户服务与数字出版物。'],
                ['name' => '斯沃玛食品', 'kind' => 'enterprise', 'color' => '#f472b6',
                    'description' => '卡西米尔家喻户晓的食品加工制造企业，也是该国最大的农林牧综合体之一；'
                        .'1098 年的加工平台原料泄漏事故牵出它与工业巨头梅什科集团之间复杂的商业从属关系。'],
                ['name' => '梅什科集团', 'kind' => 'enterprise', 'color' => '#f472b6',
                    'description' => '经营范围涵盖金融、保险、源石工业、军工业与移动城市平台建造的综合企业集团，'
                        .'旗下有梅什科工业、电子、建筑、药业等子公司；起于 1062 年承接乌卡战争赔款城市项目的梅什科工业。'],
            ]],
            ['name' => '哥伦比亚', 'kind' => 'polity', 'color' => '#10b981', 'description' => '原为维多利亚殖民地，独立后迅速工业化，莱茵生命等大型研究机构以此为基地。', 'children' => [
                // 这些机构挂到母政体下之后，「按哥伦比亚筛选」会自动把它们的条目也带出来
                ['name' => '莱茵生命', 'kind' => 'enterprise', 'full_name' => '莱茵生命实验室', 'color' => '#34d399',
                    'description' => '哥伦比亚的源石科技企业。书中以「十字路口的莱茵生命」为题，用一份内部数据库与多方报道拼出这家公司：'
                        .'核心是被称为「莱茵十杰」的研究者 —— 克丽斯腾·莱特、缪尔赛思、斐尔迪南·克鲁尼、多萝西·弗兰克斯、'
                        .'阿伦茨·帕尔维斯、安德涅特·马里亚姆等人，并记下它在近年的动荡。'],
                ['name' => '黑钢国际', 'kind' => 'enterprise', 'full_name' => '黑钢国际 · Black Steel Worldwide', 'color' => '#f59e0b',
                    'description' => '巴伦基地起家的私人安全承包商，由萨科塔雇佣兵「桥夹」克里夫创办于 1026 年 —— 前身是包含他在内的五人核心团队「钢铁之环」，1083 年巴伦矿场事件之后几年才正式注册；敢在注册时就冠上「国际」二字的企业，在哥伦比亚数百家安保公司里也没有几家。它很少冒不必要的风险：接下政府与企业的正式合约，把判断为「非必要风险」的转包给其他雇佣兵团队（成功则分成，失败则用「补偿方案」把对方吸纳进队伍）。创始人带来的仿制拉特兰铳械与弹药技术是它的招牌，代价是训练昂贵 —— 培养一名非萨科塔铳射手，资源够练出三个熟练弩手。'],
                ['name' => '雷神工业', 'kind' => 'enterprise', 'full_name' => '雷神工业 · Raythean Industries', 'color' => '#22c55e',
                    'description' => '两位创始人分别来自高卢与哥伦比亚的综合工业集团，以源石晶体单元研发见长。它被视为 1031 年四国战争之后历史格局变化的佐证：在旧体系下受训的匠人前往哥伦比亚落地生根，再凭新格局享用者的身份成为跨国企业家 —— 创始人之一亨利·布洛赫原是前高卢第三军械研究所的重要研制员，高卢战败传闻四起时，他带走了军械所能拿走的全部技术图纸，携妻儿汇入逃离的人流。产品线几乎无所不包：工程机械、电子设备、机动车辆、机器人、武器弹药、防护装备乃至休闲服饰；连谢拉格这样少被关注的市场上也有它的雇员。'],

                /*
                 * 以下出自《大地巡旅》第 6.9「组织名录」—— 那一节逐个介绍了一批中小机构，
                 * 是全书写组织最集中的地方。说明一律据该节正文转写。
                 *
                 * 名校与名校之间是同类实体（都是「在哥伦比亚、有成员、有产出」的东西），
                 * 因此一并挂在政体之下：「按哥伦比亚筛选」会把它们一起带出来。
                 */
                ['name' => '塔山生物科技', 'kind' => 'enterprise', 'color' => '#059669',
                    'description' => '主营各类生物医学产品，主攻过敏性与感染性疾病；长期以矿石病研究为由进行源石活体实验。'
                        .'1083 年巴伦矿场感染生物袭击事件被曝光后一度陷入信誉危机，其公关部门随后数年主导传播科学阴谋论，转移了舆论焦点。'],
                ['name' => '梅兰德基金会', 'kind' => 'agency', 'color' => '#6ee7b7',
                    'description' => '「联邦之父」梅兰德·塞勒涅在与维多利亚的战争结束后拒绝在政府中担任要职，转而建立的私人非营利机构，'
                        .'以「维护哥伦比亚公民福利」为宗旨；下设梅兰德儿童权益基金会、天灾救援基金会等人道机构，以及历史协会、技术发展协会等学术支持机构。'],
                ['name' => '沃尔沃特科钦斯基', 'kind' => 'enterprise', 'color' => '#10b981',
                    'description' => '哥伦比亚最具影响力的巨型企业之一，起源于数名卡西米尔人建立的武器工坊，'
                        .'业务已伸入军工制造、民用制造、安全保障与银行金融。'],
                ['name' => '特里蒙大学', 'kind' => 'agency', 'color' => '#a7f3d0',
                    'description' => '1021 年成立的哥伦比亚第一座高等学府；1030 年内部学术纷争发展为学阀派系斗争，学者出走另立。'],
                ['name' => '特里蒙理工学院', 'kind' => 'agency', 'color' => '#a7f3d0',
                    'description' => '1032 年由离开特里蒙大学的学者建立，以物理学和数学闻名。'],
                ['name' => '哥伦比亚艺术设计中心', 'kind' => 'agency', 'color' => '#a7f3d0',
                    'description' => '1032 年与特里蒙理工学院同批建立，拥有哥伦比亚境内最出色的建筑专业与摄影专业。'],
                ['name' => '海德兄弟', 'kind' => 'enterprise', 'color' => '#34d399',
                    'description' => '建材企业：雷欧·海德与瓦尔·海德兄弟白手起家迅速崛起，后因不法交易与暴力行径曝光，商业帝国被肢解瓜分。'],
                ['name' => '约翰老妈', 'kind' => 'enterprise', 'color' => '#34d399',
                    'description' => '食品公司，它的商标被称作哥伦比亚大小餐厅里最常见的符号；近十余年业务从餐桌扩展到家具、电视乃至巨型货运舰船。'],
                ['name' => '联合议会', 'kind' => 'agency', 'color' => '#6ee7b7',
                    'description' => '全称「哥伦比亚自由城市联合议会」，哥伦比亚的最高立法机构，设参、众两院。'],
                ['name' => '机动骑兵部队', 'kind' => 'military', 'color' => '#047857',
                    'description' => '哥伦比亚联邦的精锐军事力量，由公民志愿参加，以身穿动力装甲执行快进快出的突袭而闻名。'],
            ]],
            ['name' => '伊比利亚', 'kind' => 'polity', 'color' => '#22d3ee',
                'description' => '曾被拉特兰信仰与开拓雄心共同塑造的海洋强国：开国诸王崇敬圣徒所象征的美德，后来王室更看重对求索与征服的执着 —— '
                    .'探险家德莱昂由此为它开拓出玻利瓦尔总督区，沿海也兴起了繁荣的贸易城邦联盟。'
                    .'泰拉历 1038 年「大静谧」自海洋降临，灯塔熄灭、宫殿沉入汪洋；此后的伊比利亚在审判庭的封锁中沉默，'
                    .'连伊比利亚人也说不清它是旧日辉煌的延续还是终结。',
                'children' => [
                // 伊比利亚卷：大静谧之后，审判庭取代国教会成为实际的最高权力
                ['name' => '审判庭', 'kind' => 'agency', 'color' => '#0891b2',
                    'description' => '伊比利亚实际上的最高权力机构，前身为伊比利亚国教会；中枢为圣徒—大审判官—审判官三级。'],
                ['name' => '伊比利亚国教会', 'kind' => 'society', 'color' => '#67e8f9',
                    'description' => '建国之初在王室干预下确立的官方信仰机构，后改组为审判庭。'],
                ['name' => '伊比利亚国防军', 'kind' => 'military', 'color' => '#22d3ee',
                    'description' => '依托古老城镇的佣兵公会组建的军队，见证了王国的辉煌。'],
                ['name' => '伊比利亚惩戒军', 'kind' => 'military', 'color' => '#0e7490',
                    'description' => '审判庭组建的常备武装，主要由大静谧之后出生的年轻人组成，由审判官带领巡行海岸。'],
                ['name' => '深海教会', 'kind' => 'society', 'color' => '#a5f3fc',
                    'description' => '大静谧后借社会秩序崩溃而扩张的组织，试图渗透王室与贵族，被审判庭视为异端。'],
            ]],
            // 「炎-岁」是 PRTS 势力表里的一个取值：炎国境内围绕「岁」的一组存在
            // （年、夕、令、重岳都归在其下）。挂进阵营树之后，
            // 他们既保留了「属炎国」的归属，又比笼统的「炎国」精确
            ['name' => '炎国', 'kind' => 'polity', 'color' => '#fb923c',
                'description' => '东方的古老帝国：约三亿人口，疆域内设有十九个行政区划（最年轻的是行政编码 0019 的龙门）。'
                    .'炎国有庞大的文书体系与清晰的行政区界，户部很可能是这片大地上工作最繁重的行政机关。',
                'children' => [
                ['name' => '炎-岁', 'kind' => 'society', 'color' => '#fdba74',
                    'description' => '炎国境内围绕远古实体「岁」的一组存在；年、夕、令、重岳在势力表里归于其下。'],
                // 炎国卷里的国家机器（司岁台与钦天监各司一头：一个管巨兽，一个管天灾）
                ['name' => '司岁台', 'kind' => 'agency', 'color' => '#f97316',
                    'description' => '专司「岁」的分身一应事务的炎国机构，其成员被称为「秉烛人」；主导巨兽的沟通、监看与镇封，保密工作做得相当出色。'],
                ['name' => '钦天监', 'kind' => 'agency', 'color' => '#f97316',
                    'description' => '除一般意义上的气象观测，还司掌炎国全域的天灾预报事宜，向全国差遣天灾信使。'],
                ['name' => '天师府', 'kind' => 'agency', 'color' => '#fdba74',
                    'description' => '真龙下令开设、广育贤才的机构，培养授业天师，是炎国源石技艺与技术专家的群体。'
                        .'「天」意为遵奉天命，「师」则意为导师与引领者。'],
                ['name' => '稷府学宫', 'kind' => 'agency', 'color' => '#fed7aa',
                    'description' => '坐落于常稷，炎国最重要的高等学府之一，享「东稷天学」之誉，也是民本学说的起点；率先打破办学的地域限制广招学子，为炎国治理体系输送人才。'],
                ['name' => '数舟茶庄', 'kind' => 'enterprise', 'color' => '#fb923c',
                    'description' => '发迹于尚蜀一家路边凉茶铺的后起茶庄；年轻掌门人推出口味清奇的罐装响午茶系列饮料并大举宣发，'
                        .'在尚蜀的年轻消费群体中一夜走红。'],
                ['name' => '龙门', 'kind' => 'polity', 'full_name' => '龙门独立市', 'color' => '#fbbf24',
                    'description' => '炎国最西端的不夜城与必经口岸，也是炎国最年轻的行政区划（编码 0019）：异国面孔随处可见，'
                        .'每天清晨五六点，生鲜卖场、早点车与饭馆的吆喝声次第上街，整座城市随之热闹起来。',
                    'children' => [
                    ['name' => '龙门近卫局', 'kind' => 'agency', 'color' => '#fcd34d',
                        'description' => '龙门的治安机构。书中一篇报道里，记者在龙门近卫局外采访到一位刚接受问讯的男性，'
                            .'他回忆了在近卫局成员帮助下想起的事发经过。'],
                    ['name' => '企鹅物流', 'kind' => 'enterprise', 'full_name' => '企鹅物流 · Penguin Logistics', 'color' => '#fcd34d',
                        'description' => '大帝在龙门创办的物流企业（1093 年），要人护卫与货物运输之外也承接灰色业务。他宣布开店时，大部分人把这当成一种行为艺术；而当企鹅物流在龙门积起名气、在城内各处建起员工安全屋、与独占龙门物流业鳌头的肇驰物流谈起了商业接洽、甚至与龙门市政府达成一系列合作时，业界终于大跌眼镜。'],
                    ['name' => '鲤氏侦探事务所', 'kind' => 'enterprise', 'full_name' => '鲤氏侦探事务所 · Lee\'s Detective Agency', 'color' => '#fde68a',
                        'description' => '老鲤在龙门经营的私人侦探事务所，兼作各方势力之间的中立咨询渠道。老鲤出身商贾世家，离开祖辈世代为家的炎国腹地，来到这座炎国西北边陲的商贸重镇，凭精于人情世故成了「龙门的活百科全书」—— 小到街头商贩、大到龙门的管理者魏彦吾，都曾是他关系网的一环，「魏彦吾邀他入伙被拒」也就成了市井传说。业务从寻人寻址、调查外遇，到搜证录音、法律咨询无所不包，还常受托于官方。事务所藏在龙门市中心一条要拐进小巷才能找到的窄街上，从不做广告，客户全靠口耳相传；除了老鲤，成员只有叶、阿与槐琥三人 —— 与其说是员工，不如说是他的亲人。'],
                    // 第 6.9「组织名录」：与企鹅物流在龙门物流业上正面相遇的另一家
                    ['name' => '肇驰物流', 'kind' => 'enterprise', 'color' => '#fde68a',
                        'description' => '跨国物流公司，致力于构建真正的跨国物流通道乃至网络；创始人欧厄尔·彼得斯与企鹅物流的大帝关系紧密。'
                            .'行事沉稳低调，在炎国的特色业务是短距离大宗货运，俗称「风驰搬家」。'],
                ]],
            ]],
            ['name' => '叙拉古', 'kind' => 'polity', 'color' => '#c084fc',
                'description' => '以「家族」为骨架的国家：荒野上由鲁珀兽亲组成的「狼群」给原始部族带来持续的生存压力与食物竞争，'
                    .'由此生长出格外严酷的家族秩序 —— 家族之间界限分明，彼此的敌意也被视作理所当然。'
                    .'谈及叙拉古的人，总会不厌其烦地讨论同一个话题：家族。',
                'children' => [
                // 叙拉古卷：灰厅与城邦联合议事会/法院是「家族秩序」被制度化之后的三件套
                ['name' => '灰厅', 'kind' => 'agency', 'color' => '#a855f7',
                    'description' => '代表家族联合秩序的机构，形式上模仿了拉特兰的七厅枢机会议，所在地为西西里城。'],
                ['name' => '城邦联合议事会', 'kind' => 'agency', 'color' => '#d8b4fe',
                    'description' => '叙拉古的行政机构，由各城邦代表组成并产生议事会主席。'],
                ['name' => '城邦联合法院', 'kind' => 'agency', 'color' => '#d8b4fe',
                    'description' => '叙拉古的立法与司法机构，被民众视为西西里夫人意志的代行机构。'],
                ['name' => '马蒂诺披萨', 'kind' => 'enterprise', 'color' => '#e9d5ff',
                    'description' => '最早是属于孔特家族的高档餐厅，孔特覆灭后转型为平价餐厅，'
                        .'以披萨小人商标与儿童动画《马蒂诺兄弟》打入各国食品市场。'],
            ]],
            ['name' => '喀兰贸易', 'kind' => 'enterprise', 'color' => '#60a5fa',
                'description' => '谢拉格的现代化企业：希瓦艾什家族族长恩希欧迪斯于 1090 年从维多利亚学成归国后创办，'
                    .'率先把现代公司管理制度带回谢拉格 —— 当时谢拉格人对「公司」这一组织形式仍相当陌生。'
                    .'他随后依据传统重回三族议会；人们一般把它简称为「喀兰贸易」。'],
            ['name' => '谢拉格', 'kind' => 'polity', 'color' => '#93c5fd',
                'description' => '由耶拉冈德信仰维系的北方山国：先祖是天灾难民，被雪山收留。'
                    .'近年国际学术团队的地质钻孔研究证实，至少千年来这片雪山地区确实没有发生过显著天灾，'
                    .'这在泰拉是难解的谜题；古代谢拉格人把这一现象归于神力，因此最先诞生的权力组织是代表神权的宗教机构。',
                'children' => [
                // 谢拉格卷：宗教权力与世俗权力分持在蔓珠院与三族议会手里
                ['name' => '蔓珠院', 'kind' => 'society', 'color' => '#60a5fa',
                    'description' => '代表雪境的最高宗教机关，掌宗教权力并拥有极大的世俗裁判权，领袖为喀兰圣女。'],
                ['name' => '三族议会', 'kind' => 'agency', 'color' => '#bfdbfe',
                    'description' => '总领谢拉格世俗事务的贵族领袖协商机制，与蔓珠院分享权力；无常驻机关。'],
                ['name' => '希瓦艾什家族', 'kind' => 'society', 'color' => '#93c5fd',
                    'description' => '谢拉格盆地三大家族之一，改革派；恩希欧迪斯·希瓦艾什创办了喀兰贸易。'],
                ['name' => '布朗陶家族', 'kind' => 'society', 'color' => '#93c5fd',
                    'description' => '谢拉格盆地三大家族之一。'],
                ['name' => '佩尔罗契家族', 'kind' => 'society', 'color' => '#93c5fd',
                    'description' => '谢拉格盆地三大家族之一。'],
            ]],
            ['name' => '卡兹戴尔', 'kind' => 'polity', 'full_name' => '卡兹戴尔 / 萨卡兹流亡政权', 'color' => '#ef4444',
                'description' => '萨卡兹的家园，也是近代萨卡兹政治的基石所在：据某些萨卡兹的说法，十支王庭氏族是卡兹戴尔的基石，'
                    .'而千余年的动荡让十王庭接近分崩离析。书中记叙了卡兹戴尔建造第一与唯一一座移动城市时的协作景象 —— '
                    .'土石之子把蓝图铭刻于地、歌利亚将旧城的遗骸重组成型、血魔与女妖从荒野的另一端夺来引擎部件、'
                    .'温迪戈与食腐者封锁平原，连羽兽也无法走漏消息。'],
            ['name' => '拉特兰', 'kind' => 'polity', 'color' => '#e2e8f0',
                'description' => '律法庇护的圣城之国：外界对它的认知往往分三段 —— 先以为它死板肃穆、教条无处不在；'
                    .'结识萨科塔之后发现它高度自由甚至崇尚享乐；真正踏足才明白，一切自由与享乐都是秩序的副产物，'
                    .'而这种近乎自发却井然不紊的秩序，正是它最值得探讨之处。拉特兰繁荣数千年，古物从未入土，'
                    .'因此考古学在这里从未存在过。',
                'children' => [
                // 拉特兰卷：中央政府叫「教皇厅」（俗称教廷），公证所是它第五厅的下设机构
                ['name' => '教皇厅', 'kind' => 'agency', 'color' => '#cbd5e1',
                    'description' => '拉特兰的中央政府机构，即俗称的「教廷」，设七个分厅。', 'children' => [
                        ['name' => '拉特兰中庭公证所', 'kind' => 'agency', 'color' => '#f1f5f9',
                            'description' => '教皇厅第五厅下设机构，负责治安、保卫、公证、消防等一系列繁杂事务；'
                                .'其外派执行者可出现在泰拉的任何角落。'],
                    ]],
            ]],
            ['name' => '萨尔贡', 'kind' => 'polity', 'color' => '#facc15',
                'description' => '自认「人类文明正统」的南方大国：官修五十卷《萨尔贡史》记载，一万五千年前一群流民自阿卡胡拉丛林走出、'
                    .'在沙漠边缘定居。撇开神话色彩，关键事实是萨尔贡人从别处迁来，且自认是泰拉人类文明的正统；'
                    .'泰拉纪年即以该国「过去与未来之王」发现圣物之年为元年。',
                'children' => [
                ['name' => '长生军', 'kind' => 'military', 'color' => '#eab308',
                    'description' => '萨尔贡唯一只接受万王之王指挥的军队：以勇士为素材、用源自古老萨卡兹巫术的源石技艺打造而成的不死者军队。'],
                // 第 6.9「组织名录」：以王酋尊名冠名的半官方商业组织
                ['name' => '依娜姆商会', 'kind' => 'enterprise', 'color' => '#fde047',
                    'description' => '以王酋尊名冠名、因与阿卡胡拉王酋领地（1099—）的建立直接相关而具有半官方性质的商业合作组织，'
                        .'目前是阿卡胡拉唯一的商业合作组织；纲领含监督各聚落物资流通、推进领地间贸易、防止第三势力利用雨林。'],
            ]],
            ['name' => '深池', 'kind' => 'society', 'full_name' => '维多利亚感染者组织 · 深池', 'color' => '#e879f9'],

            // 《大地巡旅》「国家与地区」卷覆盖、但此前未进入检索维度的政体。
            // 只填在有把握的字段上：不做正式国名的推测，拿不准的一律留空。
            ['name' => '莱塔尼亚', 'kind' => 'polity', 'color' => '#818cf8', 'description' => '以双王共治体制与术师传统著称的政体。', 'children' => [
                // 莱塔尼亚卷里的国家机器两条线：女皇的禁卫与选帝侯的常备军
                ['name' => '女皇之声', 'kind' => 'military', 'color' => '#6366f1',
                    'description' => '双子女皇的禁卫军；每一名女皇之声先是御前乐师，然后才是谕使与禁卫 —— 这支队伍的编制就长在音乐里。'],
                ['name' => '金律法卫', 'kind' => 'military', 'color' => '#6366f1',
                    'description' => '各选帝侯麾下最强大的常规作战部队：着厚重护具、持双手巨剑的强大施法者，可彼此共联源石技艺。'],
                // 第 6.9「组织名录」
                ['name' => '施密特工业作坊', 'kind' => 'enterprise', 'color' => '#a5b4fc',
                    'description' => '家族控制的轻工业作坊，以制造莱塔尼亚最顶尖的源石乐器而闻名于泰拉；'
                        .'创办者施密特·埃伯斯多夫本人是编曲家，主张「人与器的完全结合」。'],
                ['name' => '威廉－赫尔佐格－谷腾堡大学', 'kind' => 'agency', 'color' => '#c7d2fe',
                    'description' => '812 年由威廉侯爵与赫尔佐格侯爵在谷腾堡设立，设源石学、法学、自然科学、历史与文学四系；'
                        .'与谷腾堡大学、埃里希选帝侯大学并称「谷腾堡三校」。'],
            ]],
            ['name' => '米诺斯', 'kind' => 'polity', 'color' => '#2dd4bf', 'description' => '由多个城邦构成的地区，保有古老的信仰与竞技传统。'],
            ['name' => '玻利瓦尔', 'kind' => 'polity', 'color' => '#fb7185', 'description' => '长期陷入内乱与外部势力干涉的地区。', 'children' => [
                // 玻利瓦尔卷：两个外来势力扶植的政权，长期并立
                ['name' => '辛嘉斯王朝', 'kind' => 'polity', 'color' => '#fda4af',
                    'description' => '莱塔尼亚施彤领选帝侯扶植的傀儡王朝，统治玻利瓦尔两百余年，国号「玻利瓦尔王国」。'],
                ['name' => '玻利瓦尔联合政府', 'kind' => 'polity', 'color' => '#fecdd3',
                    'description' => '由财阀议会领导的政权；作者专造「寡头议会制」一词来形容它的十五人最高议会。'],
                ['name' => '真正玻利瓦尔人解放运动', 'kind' => 'military', 'color' => '#be123c',
                    'description' => '由玻利瓦尔拯救阵线、解放玻利瓦尔斗争联盟、玻利瓦尔青年党等于 1050 年合流而成的巨型军政府，名义上以此自称。'],
                // 第 6.9「组织名录」：多索雷斯的咖啡企业，兼做武器供应
                ['name' => '咖啡平原', 'kind' => 'enterprise', 'color' => '#fb7185',
                    'description' => '多索雷斯著名的咖啡种植加工企业，以「叙拉古风情」等风味产品远销泰拉各地，'
                        .'同时也是多索雷斯一家有本地知名度的武器供应商。'],
            ]],
            ['name' => '雷姆必拓', 'kind' => 'polity', 'color' => '#a3a3a3', 'description' => '以矿业与资源贸易立身的企业化政体。', 'children' => [
                ['name' => '雷姆必拓矿业联合体', 'kind' => 'society', 'color' => '#d4d4d4',
                    'description' => '卡特斯在结晶时代组建的矿业联合体，继承了屯堡社区的社会组织作用与文化地位。'],
            ]],
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
            ['name' => '极东', 'kind' => 'polity', 'color' => '#fda4af', 'description' => '东国在阵营表里的写法：夹在乌萨斯与炎国两大地缘实体之间。', 'children' => [
                // 东国卷：武家崛起之后的幕府，与南北朝时期分治极东的八大家族
                ['name' => '御神川幕府', 'kind' => 'polity', 'color' => '#fecdd3',
                    'description' => '东国武家崛起时代的幕府政权。'],
                ['name' => '八大家', 'kind' => 'polity', 'color' => '#fecdd3',
                    'description' => '包括南北两院东皇的皇统在内、自百年国战的余灰中崛起的东国八大家族；'
                        .'他们在旧公卿武家的基础上建立了庞大的政经复合体，分治极东。'],
            ]],

            /*
             * ---- 只出现在 PRTS 势力表里的实体 ----
             *
             * 导入干员名单时才第一次有据可依（此前本仓库没有任何条目引用它们）。
             * **一律不写 description**：出处里没有给出这些组织的说明，
             * 而页面已经会如实显示「出处里没有给出这一组织的说明」——
             * 给一个组织编一句简介，比留白更糟。
             */
            ['name' => '汐斯塔', 'kind' => 'polity', 'color' => '#5eead4',
                'description' => '在大静谧中免遭摧残的海滨城邦，因此拥有与泰拉绝大部分海岸截然不同的风光，是全泰拉数一数二的度假胜地；'
                    .'它在哥伦比亚与维多利亚之间找到了自己的定位，新一代是移动城市化的「新汐斯塔」。'
                    .'市政由市议会与市长领导的市政厅共同组成，重大问题上由全体常住居民表决；'
                    .'近海的火山生态系统还吸引了不少科研机构设站。'],
            ['name' => '格拉斯哥帮', 'kind' => 'society', 'color' => '#94a3b8'],
            ['name' => '红松骑士团', 'kind' => 'society', 'color' => '#fb7185'],
            ['name' => '使徒', 'kind' => 'society', 'color' => '#e2e8f0'],
            ['name' => '贾维团伙', 'kind' => 'society', 'color' => '#f59e0b'],

            /*
             * 联动单位。
             *
             * 它们的成员是**别的作品里的人物**（《彩虹六号》《BanG Dream!》《女神异闻录 3》
             * 《迷宫饭》），本仓库照名单收录它们在《明日方舟》里的身份，
             * 但不对其原作设定做任何展开 —— 那不是这份年表该管的事。
             */
            ['name' => '彩虹小队', 'kind' => 'society', 'color' => '#60a5fa'],
            ['name' => 'Ave Mujica', 'kind' => 'society', 'color' => '#a78bfa'],
            ['name' => 'S.E.E.S.', 'kind' => 'society', 'color' => '#38bdf8'],
            ['name' => '莱欧斯小队', 'kind' => 'society', 'color' => '#fbbf24'],

            /*
             * ---- 《大地巡旅》「组织」卷登记、但无归属政体的组织 ----
             *
             * 锈锤与太阳谷都不属于任何现存国家的下属机构，因此保持为顶层：
             * 锈锤是跨越国界的松散团体，太阳谷的厂区社区自成一体
             * （书中提到它出现在雷姆必拓一带，但并未称其为该政体的下属机构，不臆断）。
             */
            ['name' => '锈锤', 'kind' => 'society', 'color' => '#a8a29e',
                'description' => '活动于荒野的松散团体，主张对「文明」本身复仇，被多国列为重大治安问题。它的名字首次为公众所知是在 1040 年：一群幸存拓荒者被要求把这个名字传扬出去。袭击对象几乎没有普通人 —— 携带国旗、有官方背景的拓荒队、公务车队、联邦军队与大企业人员才是目标；行动前会布置多个伏击点、针对不同国家的车队设计战术，抢走物资、焚毁旗帜与认证标识，直到确认恐惧已经留下才离开。成员自称「荒地的征服者」与「文明的复仇者」，多地独立调查者都警告移动城市可能成为目标；可它的组织松散得惊人，各地团体行事风格迥异，只在名号、劫掠方式与主张上保持一致。1092 年在三国相关的二百五十余起案件里，半数以上被鉴定为冒用名号 —— 一把锈蚀的铁锤，承载的象征含义越多，它离初衷也就越远。'],
            ['name' => '太阳谷机械工业', 'kind' => 'enterprise', 'full_name' => '太阳谷机械工业 · Sun Valley Industries', 'color' => '#d6d3d1',
                'description' => '厂区即生活社区的工业集团，口号是「工作，是为了更好的生活」。总部位于雷姆必拓的太阳谷（谷中矿坑夜间微亮，自古传说地下也有一个太阳）：主营矿山设备、冶炼设备与农业机械的制造、销售与检修，兼做玩具与微缩模型，近年还办起了基础设施服务。它的产品定位不高，却极擅长在简陋环境下提供整套机械体系、定制低成本装备 —— 因此在需要大量简易设施的玻利瓦尔与萨尔贡广受欢迎；在以维多利亚或哥伦比亚设备为标准的用户看来，这些产品的外观与结构常显得匪夷所思，却能正常运行。前身是太阳谷的卡特斯家族作坊，小家族争斗多年后由一位大家长统合为矿业联盟，再兼并扩张成今天的样子。'],

            // 第 6.9「组织名录」：业务遍布大半国家与地区，书里没有给它任何政体归属，因此保持顶层
            ['name' => '塞壬唱片', 'kind' => 'enterprise', 'full_name' => '塞壬唱片 · MSR', 'color' => '#f0abfc',
                'description' => '泰拉十一世纪规模最大的音乐发行商之一，业务范围遍及泰拉大半国家和地区；'
                    .'旗下有大帝、D.D.D.、AUS 等传奇艺人与乐队，以及数个全建制职业交响乐团、数十个偶像团体与小型乐队。'
                    .'创作与表演自由是许多知名艺人选择它的重要原因。'],

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
                    /*
                     * 以下三条出自终末地 Wiki 的《中枢档案》与《环带纪略》。
                     * 它们都是「终末地工业内部的部门」，因此挂在公司名下 ——
                     * 与上面把机构挂到母政体下是同一个道理：归属本身就是可检索的信息。
                     */
                    [
                        'name' => 'S.O.W.',
                        'kind' => 'agency',
                        'color' => '#6fc3d4',
                        'description' => '终末地的关键敏感情报体系，订有《终末地工业 S.O.W. 关键敏感情报安全协议》；管理员的各类调查报告均由 S.O.W. 数据库撰写。',
                    ],
                    [
                        'name' => '情报办公室',
                        'kind' => 'agency',
                        'color' => '#6fc3d4',
                        'description' => '终末地对四号谷地天使活动的情报档案进行确认并出具结论的部门。',
                    ],
                    [
                        'name' => '终末地危机处理小组',
                        'kind' => 'agency',
                        'color' => '#8fd6e0',
                        'description' => '管理员苏醒后前往处理四号谷地事件、武陵甚大裂隙事件的行动单位。',
                    ],
                ],
            ],
            ['name' => '联盟工团', 'kind' => 'society', 'color' => '#8a9aa8', 'description' => '塔卫二上的生产与开拓组织，四号谷地最初由其选定为生产开拓区域。', 'children' => [
                ['name' => '联盟工团安全局', 'kind' => 'agency', 'color' => '#a3aeb8',
                    'description' => '负责联盟工团的对内安全、对外情报、特殊环境调查与环带条约执行情况监察等工作；成立于耻辱战争结束后的一个午后。'],
            ]],
            ['name' => '天使', 'kind' => 'military', 'color' => '#e2e8f0', 'description' => '塔卫二的主要敌对势力，第一次与第二次天使战争均与其进犯有关。'],
            ['name' => '裂地者', 'kind' => 'society', 'color' => '#f87171', 'description' => '雅各布·迈森手下的匪帮，后被文明环带摧毁。社区资料中亦写作「掠地者」，两种写法并存。', 'children' => [
                // 《中枢档案 · 裂地者》：三大氏族都继承同一个军阀的遗产，彼此不是上下级
                ['name' => '碾骨氏族', 'kind' => 'society', 'color' => '#ef4444',
                    'description' => '裂地者的三大氏族之一，继承军阀雅各布·迈森的遗产；以「雾火」为核心建立起远比其他氏族强大的组织，头领为聂菲斯。'],
                ['name' => '沙盗', 'kind' => 'society', 'color' => '#ef4444',
                    'description' => '与碾骨、狼群并列的裂地者三大氏族之一，同样继承军阀雅各布的遗产。'],
            ]],

            /*
             * 以下九个势力来自 `docs/fz-干员一览.json`（终末地 Wiki 的干员名单，2026-09-24 抓取）。
             * 那份名单只回答「谁属于哪个势力」，不含任何关于这些势力本身的说明，
             * 因此这里**只登记名字与归属，说明一栏留空** —— 这不是偷懒：
             * 这条数据的全部出处就是名单里的一个名字，多写一个字都是编。
             *
             * kind 分两档：
             *  - 名字自己说出来的（「科学院」是机构、「铁誓军」是武装）照名字归类；
             *  - 名字没说的（塞什卡、众生长地）按**最保守的「团体」**登记 ——
             *    名单能证明的只有「它是有成员的团体」，至于是不是企业 / 武装 / 政体，不猜。
             *    留在「未归类」不是更诚实的选项：那是一个待办状态，本项目不许它长期存在。
             */
            ['name' => '宏山科学院', 'kind' => 'agency', 'color' => '#94a3b8', 'children' => [
                // 《中枢档案 · 宏山科学院》等：这几个都是宏科院自己的下设机构
                ['name' => '宏山选剑局', 'kind' => 'agency', 'color' => '#b6c2cf',
                    'description' => '宏山科学院直接管理的研发生产机构，负责满足宏科院乃至整个文明环带的优质轻、重型武器装备需求：'
                        .'审核分发生产蓝图，并对装备做最终质量验证。'],
                ['name' => '天师府学院', 'kind' => 'agency', 'color' => '#b6c2cf',
                    'description' => '大炎天师府设在宏山科学院的附属机构，教研一体：进行科研课题研究，并为宏科院乃至文明环带培养「天师」。'],
                ['name' => '武陵材料研究所', 'kind' => 'agency', 'color' => '#b6c2cf',
                    'description' => '武陵科研系统的材料研究机构，曾审查「打潮鞭」项目的实验报告，并给出「建议全面停止实验」的结论。'],
                ['name' => '武陵地区综合治理项目办公室', 'kind' => 'agency', 'color' => '#b6c2cf',
                    'description' => '宏山科学院下设的武陵地区综合治理项目机构。'],
            ]],
            ['name' => '环塔商会', 'kind' => 'society'],
            ['name' => '狼群氏族', 'kind' => 'society'],
            ['name' => '塞什卡', 'kind' => 'society'],
            ['name' => '众生长地', 'kind' => 'society'],
            ['name' => '铁誓军', 'kind' => 'military', 'color' => '#cbd5e1', 'children' => [
                // 《中枢档案 · 铁誓军》：编制以「旗队」为单位，战旗本身是传承的载体
                ['name' => '旗队', 'kind' => 'military', 'color' => '#e2e8f0',
                    'description' => '来自文明环带以及在要塞中出生的军人结成的编制，以浴血的战旗传承战斗意志。'],
                ['name' => '陷阵旗队', 'kind' => 'military', 'color' => '#e2e8f0',
                    'description' => '干员余烬所在的铁誓军旗队，因损伤惨重被移除编制。'],
            ]],
            ['name' => '寂语修会', 'kind' => 'society', 'color' => '#a1a1aa', 'children' => [
                // 《中枢档案 · 寂语修会》：修会内部分成三个教派，主张各不相同
                ['name' => '会话派', 'kind' => 'society', 'color' => '#c4c4cc',
                    'description' => '寂语修会三大教派之首，重拾泰拉的源石微电子技术，帮助各方重建现代水准的通信网络。'],
                ['name' => '重现派', 'kind' => 'society', 'color' => '#c4c4cc',
                    'description' => '常被外界视为修会的军事部门：充斥着手持重型铳械、身着重型甲胄的武装修士，目标是「重现圣城」。'],
                ['name' => '阐释派', 'kind' => 'society', 'color' => '#c4c4cc',
                    'description' => '将信仰放在首位的一派，致力于治愈星门崩溃给文明环带造成的长久精神创伤。'],
            ]],
            ['name' => '萨米维格', 'kind' => 'society', 'color' => '#bae6fd',
                'description' => '萨米部落群在塔卫二的团体名，以这个名字登记在《环带公约》中；'
                    .'以「雪祀」为术师首领结成部落，也是铁誓军最重要而稳定的兵源。', 'children' => [
                    ['name' => '雪祀大会', 'kind' => 'agency', 'color' => '#e0f2fe',
                        'description' => '由雪祀代表萨米诸部落整体的意志，参与文明环带的事务。'],
                ]],
            ['name' => '泰拉联合科考团', 'kind' => 'society', 'color' => '#7dd3fc',
                'description' => '首批抵达塔卫二的科考团体；基石对其发动的全面袭击，引发了第一次天使战争。'],
            ['name' => '沧贼', 'kind' => 'society', 'color' => '#94a3b8',
                'description' => '清波寨一带的主要敌人。'],
            ['name' => '大文献馆', 'kind' => 'agency', 'color' => '#cbd5e1',
                'description' => '对未登记的天使形成模式进行甄别归类的机构：铁誓军战役中新出现的模式，「大部分已送交大文献馆」。'],
            ['name' => '清波寨', 'kind' => 'society'],
            ['name' => '应龙特勤队', 'kind' => 'military'],

            /*
             * 文明环带是**地域**而不是组织：它是塔卫二上的人类聚居带（四号谷地位于其边缘地区），
             * 只是历史原因一直登记在阵营表里。归入 polity / territory 之后，
             * 它不会再出现在资料集的「组织」页 —— 那页要回答的是「谁在做」，
             * 而聚居带回答的是「在哪里」。
             */
            ['name' => '文明环带', 'kind' => 'territory', 'color' => '#7dd3fc', 'description' => '塔卫二上的人类聚居带，四号谷地位于其边缘地区。'],
        ];

        foreach ($tree as $order => $faction) {
            $this->upsertFactionTree($faction, $order, null);
        }
    }

    /**
     * 递归落库一棵阵营树。
     *
     * 层级可以任意深：PRTS 的势力取值里，龙门既是炎国的城市、自己又下辖近卫局与几家公司
     * （炎国 → 龙门 → 企鹅物流）。原先只下降一层，把「龙门」挂到炎国名下就会**静默丢掉**
     * 它的三个子级 —— 而丢掉的东西在页面上看不出来。
     */
    private function upsertFactionTree(array $data, int $order, ?int $parentId): Faction
    {
        $faction = $this->upsertFaction($data, $order, $parentId);

        foreach ($data['children'] ?? [] as $childOrder => $child) {
            $this->upsertFactionTree($child, $childOrder, $faction->id);
        }

        return $faction;
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
            // 老鲤此前被记在历史人物栏 —— 他是可操作干员（代号 Lee，见 PRTS 名单），导入时更正归档
            ['老鲤', 'Lee', '鲤氏侦探事务所', '龙',
                '出身炎国腹地商贾世家，早年来到龙门并逐渐成为「龙门的活百科全书」；其关系网小到街头商贩、大到龙门管理者魏彦吾。'],
            ['玛莉娅·临光', 'Maria Nearl', '卡西米尔', '库兰塔'],
            ['玛恩纳', 'Młynar', '卡西米尔', '库兰塔'],
            ['耀骑士临光', 'Nearl', '卡西米尔', '库兰塔'],
            ['塞雷娅', 'Saria', '莱茵生命', '瓦伊凡'],
            ['缪尔赛思', 'Muelsyse', '莱茵生命', '精灵'],
            ['伊芙利特', 'Ifrit', '莱茵生命', '萨弗拉'],
            ['赫默', 'Silence', '莱茵生命', '黎博利'],
            ['棘刺', 'Thorns', '伊比利亚', '阿戈尔'],
            // 银灰的本名是「恩希欧迪斯·希瓦艾什」—— 原先他在历史人物栏里另占一行，
            // 同一个人被拆成两处。导入 PRTS 名单时并回这一行（他的经历属于这个人，不属于哪个「身份」）
            ['银灰', 'SilverAsh', '谢拉格', '菲林',
                '谢拉格贵族出身，曾留学维多利亚、后投奔开斯特公爵；1082 年十四岁继承家业，1090 年创立喀兰贸易，并在 1097 年末完成公司改组。本名为恩希欧迪斯·希瓦艾什。'],
            ['初雪', 'Pramanix', '谢拉格', '菲林'],
            // 阵营此前写成「谢拉格」，PRTS 干员页作「罗德岛」—— 按 PRTS 更正（2026-09-24）
            ['尤里卡', 'U-Official', '罗德岛', '札拉克'],
            ['拉普兰德', 'Lappland', '叙拉古', '鲁珀'],
            ['德克萨斯', 'Texas', '叙拉古', '鲁珀'],
            ['能天使', 'Exusiai', '拉特兰', '萨科塔'],
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
            // 代号与种族一律留空：写库时没有可靠出处，按本文件既有原则
            // 「拿不准的字段宁可缺失，也不要写错」处理。
            // 英文名随后由名单的 nameEn **只补不覆盖**（fillMissingFacts）——
            // 那是来源（fz.wiki）给的写法，照抄而不是自己转写；
            // 阿伯莉不在干员名单里（她不是可玩干员），因此保持空。
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
            // 「岁」不是干员，而是炎国传说里的远古实体（它自己的条目就叫「「岁」的诞生」）。
            // 原先它被记在干员名单里，导入 PRTS 名单时归到这里：它是**古代**的存在，
            // 而 PRTS 的干员「年」（Nian）另有其人 —— 两者此前被混成了一个
            ['岁', '炎国', '炎国传说中的远古实体', null, null,
                '炎国传说里的远古实体；其「诞生」与泰拉各地的远古实体叙事存在结构相似性，但缺乏可定位的纪年记录。'],
        ];

        /*
         * 剧情人物：**现代但不是干员**的人。
         *
         * 他们既没有干员页，也不是「几百年前的人」—— 在这一档补出来之前，
         * 只能挤在「历史人物」里，名不副实却无处可去。
         */
        $npc = [
            ['克里夫', '黑钢国际', '黑钢国际创始人 ·「桥夹」', null, null,
                '萨科塔雇佣兵出身，以 1016 年哥伦比亚独立战争为起点建立黑钢；他说自己选择的「解药」不是和平，而是一种规范、可控且具有主动选择权的战争方式。'],
            ['大帝', '企鹅物流', '企鹅物流创始人', null, null,
                '哥伦比亚知名说唱歌手与制作人、黑胶唱片收藏家，1093 年在龙门创办企鹅物流；公司架构近乎没有，招聘由他亲自把关。'],
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

            $character = Character::updateOrCreate(
                ['slug' => 'chr-'.md5($name)],
                [
                    'name' => $name,
                    'codename' => $codename,
                    'world' => $world,
                    // 「未公开」这类非种族值不建字典条目，落空即为「未知」
                    'race_id' => $race === null ? null : ($raceIds[$raceAliases[$race] ?? $race] ?? null),
                    'kind' => CharacterKind::Operator->value,
                    'title' => null,
                    'reign_start_index' => null,
                    'reign_end_index' => null,
                    'description' => $profile,
                    'wiki_slug' => $wikiSlug,
                    'sort_order' => $order++,
                ],
            );

            // 归属走枢轴。这里只写人工核过的那一条，层序 0；
            // 名单导入会把它缺的那些**补上**而不是替换（见 seedOperatorRoster）
            $character->factions()->sync(
                isset($factionSlugs[$faction]) ? [$factionSlugs[$faction] => ['sort_order' => 0]] : [],
            );
        }

        // 历史人物与剧情人物共用同一套字段（头衔 / 在位期 / 简介），只有归档不同
        foreach ([[CharacterKind::Historical, $historical], [CharacterKind::Npc, $npc]] as [$kind, $rows]) {
            foreach ($rows as [$name, $faction, $title, $fromYear, $toYear, $profile]) {
                $character = Character::updateOrCreate(
                    ['slug' => 'chr-'.md5($name)],
                    [
                        'name' => $name,
                        'codename' => null,
                        'world' => World::Terra->value,
                        'race_id' => null,
                        'kind' => $kind->value,
                        'title' => $title,
                        'reign_start_index' => $fromYear === null ? null : TerraDate::toIndex($fromYear),
                        'reign_end_index' => $toYear === null ? null : TerraDate::toIndex($toYear, 12, 31),
                        'description' => $profile,
                        'wiki_slug' => null,
                        'sort_order' => $order++,
                    ],
                );

                $character->factions()->sync(
                    isset($factionSlugs[$faction]) ? [$factionSlugs[$faction] => ['sort_order' => 0]] : [],
                );
            }
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
     * 外部势力取值 → 本仓库阵营名。
     *
     * 只收「同一个实体的另一种写法」：多造一个阵营会让同一个人在筛选里被拆成两处。
     * 判据是**本仓库自己的命名**（地名树与阵营树用哪个名字，这里就跟哪个）。
     */
    private const FACTION_ALIASES = [
        '东' => '极东',
        '乌萨斯' => '乌萨斯帝国',
        '炎' => '炎国',
        '炎-龙门' => '龙门',
        '罗德岛-精英干员' => '精英干员',
    ];

    /**
     * 出身地的写法 → 本仓库地名。
     *
     * 与 `places.aliases` **分开**：别名记的是**本书里**并用的写法（乌萨斯帝国 / 乌萨斯），
     * 这里记的是**外部资料**对同一处的另一种叫法（它写「炎」，本书写「炎国」）。
     * 两者混在一起，地名匹配规则就会被外部的取名方式带着走 ——
     * 而那条规则服务的是照原文抄下来的 `location`。
     */
    private const BIRTH_PLACE_ALIASES = [
        '炎' => '炎国',
        '阿戈尔地区' => '阿戈尔',
    ];

    /**
     * 终末地名单里的人物写法 → 本仓库的人物名。
     *
     * 名单把管理员按性别拆成两行（管理员·男 / 管理员·女，共用英文代号 Endministrator），
     * 而本仓库的「管理员」是同一个人 —— 三行并排出现只会让读者困惑。
     * 这里把两个变体都指回那一条。按英文代号去重挡住的是「变体之间互相重复」，
     * 这张表挡住的是「变体与本仓库已有的条目重复」。
     */
    private const FZ_NAME_ALIASES = [
        '管理员·男' => '管理员',
        '管理员·女' => '管理员',
    ];

    /**
     * 干员名单：`docs/prts-干员一览.json`（泰拉 ← PRTS）与
     * `docs/fz-干员一览.json`（塔卫二 ← 终末地 Wiki fz.wiki）。
     *
     * 这是**核对与补全**，不是搬运：只取结构化事实 —— 中文名 / 英文代号 / 种族 / 势力
     * （PRTS 另有出身地）。简介正文、星级、职业、武器、元素与数值一律不入库：
     * 完整档案在对方站点，而人物的 `description` 永远由本仓库撰写，这里一律留空
     * （页面显示为「待补」）。
     *
     * 数据源是**签入仓库的导出**而不是运行时抓取：运行环境没有外网，
     * 而且种子必须可复现 —— 一次网络抖动不该让今天灌出来的库与昨天的不一样。
     * 代价是它们是快照：要更新，重新导出那份文件即可（导出的日期见文件本身）。
     *
     * **只新增、不覆盖**：已有人员的字段是人工核过的（其中一批正是对着 PRTS 逐条核的），
     * 让一份批量导出把它们冲掉是本末倒置。
     */
    private function seedOperatorRoster(): void
    {
        $factions = Faction::pluck('id', 'name');
        $races = Race::pluck('id', 'name');
        $places = Place::get();
        $known = Character::get()->keyBy('name');

        // 接在人工维护的那批之后：读者仍然先看到写过简介的人
        $order = (int) Character::max('sort_order') + 1;
        $added = 0;
        $enriched = 0;
        $skipped = [];

        foreach ($this->rosterEntries($factions, $skipped) as $entry) {
            /** @var Character|null $character */
            $character = $known->get($entry['name']);

            if ($character !== null) {
                $enriched++;
                $this->fillMissingFacts($character, $entry, $races, $places);

                continue;
            }

            $character = Character::create([
                'slug' => 'chr-'.md5($entry['name']),
                'name' => $entry['name'],
                'codename' => $entry['codename'],
                'world' => $entry['world'],
                'birth_place' => $entry['birth_place'],
                'birth_place_id' => $entry['birth_place'] === null
                    ? null
                    : $this->resolveBirthPlace($entry['birth_place'], $places),
                /*
                 * 种族只在它是**规范种族名**时落库 —— 名单里混着「未公开」「未知」「不明」
                 * 「未录入」「矮人（自称）」「未知（疑似黎博利）」「因经纪公司要求不公开」
                 * 这类**不是种族**的写法，fz 的「■■」（Wiki 对未公开信息的遮盖）同理。
                 * 它们一个都不进字典，正确表达是留空（字典里查不到即为 null）。
                 * 「卡特斯/奇美拉」这类复合写法由人工判定后写进具体的那一个。
                 */
                'race_id' => $races[$entry['race'] ?? ''] ?? null,
                'kind' => CharacterKind::Operator->value,
                'title' => null,
                'reign_start_index' => null,
                'reign_end_index' => null,
                'description' => null,
                'wiki_slug' => null,
                'sort_order' => $order++,
            ]);

            $character->factions()->syncWithoutDetaching($entry['affiliations']);
            $known->put($entry['name'], $character);
            $added++;
        }

        foreach ($skipped as $note) {
            $this->command?->warn('干员名单：跳过 —— '.$note);
        }

        $this->command?->info('干员名单：新增 '.$added.' 位，补全 '.$enriched.' 位，跳过 '.count($skipped).' 条。');
    }

    /**
     * 头像接入。
     *
     * 接在名单之后：先有人物，才谈得上把头像挂到人身上。清单与图片都是
     * 本地资产（与名单同一套规矩，缺失照常跑完），但少了什么必须说出来 ——
     * 尤其「名单之外」的名字：那说明清单与名单开始脱节了，该重新抓一份。
     */
    private function seedCharacterAvatars(): void
    {
        $stats = CharacterAvatars::associate(base_path('docs/manifest.json'), public_path('assets/avatars'));

        if ($stats === null) {
            $this->command?->warn('未找到或无法解析 docs/manifest.json，跳过头像接入。');

            return;
        }

        foreach ($stats['unknown'] as $name) {
            $this->command?->warn('头像清单：名单之外的人物 —— '.$name);
        }

        $this->command?->info(sprintf(
            '头像接入：关联 %d 人，文件缺失 %d 张，名单之外 %d 个。',
            $stats['linked'],
            $stats['missing_file'],
            count($stats['unknown']),
        ));
    }

    /**
     * 立绘接入。
     *
     * 与头像同一步骤的两条理由：先有人物才谈得上挂图；清单与图片都是本地资产，
     * 缺了照常跑完，但少了什么必须说出来。
     *
     * 与头像的两处差别也照实说在输出里：一个人可能挂**多张**（精英一 / 精英二），
     * 所以统计的是「多少张图」而不只是「多少人」；塔卫二在来源侧根本没有立绘
     * （fz.wiki 的干员条目只有文字与图标），因此那一侧 0 张是正常结果，
     * 不在这里报成缺失。
     */
    private function seedCharacterSplashes(): void
    {
        $stats = CharacterSplashes::associate(base_path('docs/splashes.json'), public_path());

        if ($stats === null) {
            $this->command?->warn('未找到或无法解析 docs/splashes.json，跳过立绘接入。');

            return;
        }

        foreach ($stats['unknown'] as $name) {
            $this->command?->warn('立绘清单：名单之外的人物 —— '.$name);
        }

        $this->command?->info(sprintf(
            '立绘接入：关联 %d 人 / %d 张，文件缺失 %d 张，名单之外 %d 个。',
            $stats['characters'],
            $stats['images'],
            $stats['missing_file'],
            count($stats['unknown']),
        ));
    }

    /**
     * 徽记接入。
     *
     * 接在地名树与阵营之后（前两者分别见 `seedPlaces` / `seedFactions`）：
     * 徽记是按名字打到这两张表上的，表还没建就一条都落不下去。
     *
     * 清单（`docs/emblems.json`）随仓库入库，所以这一步在任何机器上都跑得通；
     * 真缺了也只是少几枚徽记，页面退回纯文字，不该让 seed 失败。
     * 「清单里有、库里没有」的名字必须逐个点名 —— 那是清单与字典脱节的信号。
     */
    private function seedEntityEmblems(): void
    {
        $stats = EntityEmblems::associate(base_path('docs/emblems.json'), public_path());

        if ($stats === null) {
            $this->command?->warn('未找到或无法解析 docs/emblems.json，跳过徽记接入。');

            return;
        }

        foreach ($stats['unknown'] as $name) {
            $this->command?->warn('徽记清单：字典之外的名字 —— '.$name);
        }

        $this->command?->info(sprintf(
            '徽记接入：地名 %d 条、阵营 %d 条，文件缺失 %d 张，字典之外 %d 个。',
            $stats['places'],
            $stats['factions'],
            $stats['missing_file'],
            count($stats['unknown']),
        ));
    }

    /**
     * 两份名单归一后的流。
     *
     * 两份导出的字段名不同（PRTS：`zh`/`en`/`race`/`group|team|nation`/`birth_place`；
     * fz：`name`/`nameEn`/`race`/`faction`），归一成同一形状之后，
     * 「只新增不覆盖」这条规则只写一遍。
     *
     * @param  Collection<string, int>  $factions
     * @param  list<string>  $skipped  out：跳过原因，原样打印给跑种子的人看
     * @return \Generator<int, array{
     *     world: string, name: string, codename: string|null, race: string|null,
     *     affiliations: array<int, array{sort_order: int}>, birth_place: string|null
     * }>
     */
    private function rosterEntries(Collection $factions, array &$skipped): Generator
    {
        // 「管理员·男 / 管理员·女」共用英文代号 Endministrator（见 fzRosterEntry）
        $seenCodenames = [];

        foreach ([
            ['docs/prts-干员一览.json', 'prts'],
            ['docs/fz-干员一览.json', 'fz'],
        ] as [$path, $source]) {
            foreach ($this->loadRosterRows($path, $source) as $row) {
                $entry = $source === 'fz'
                    ? $this->fzRosterEntry($row, $factions, $seenCodenames, $skipped)
                    : $this->prtsRosterEntry($row, $factions);

                if ($entry !== null) {
                    yield $entry;
                }
            }
        }
    }

    /**
     * 读一份名单快照，取出条目数组。
     *
     * PRTS 的导出顶层就是数组；fz 的导出带 source / fetched 等元信息，条目在 `entries` 里。
     * 文件缺失不是错误（名单是可选的），但必须**说出来**：静默跳过会让
     * 「少了 31 位塔卫二人员」看起来像「本来就没有」。
     *
     * @return list<array<string, mixed>>
     */
    private function loadRosterRows(string $path, string $source): array
    {
        if (! is_file(base_path($path))) {
            $this->command?->warn('未找到 '.$path.'，跳过这份名单。');

            return [];
        }

        $data = json_decode((string) file_get_contents(base_path($path)), true);

        if (! is_array($data)) {
            $this->command?->warn($path.' 不是合法 JSON，跳过这份名单。');

            return [];
        }

        return $source === 'fz' ? ($data['entries'] ?? []) : $data;
    }

    /**
     * PRTS 名单的一条 → 统一形状。
     *
     * @param  Collection<string, int>  $factions
     * @return array{world: string, name: string, codename: string|null, race: string|null,
     *     affiliations: array<int, array{sort_order: int}>, birth_place: string|null}|null
     */
    private function prtsRosterEntry(array $row, Collection $factions): ?array
    {
        $name = trim((string) ($row['zh'] ?? ''));

        if ($name === '') {
            return null;
        }

        $birthPlace = trim((string) ($row['birth_place'] ?? ''));

        return [
            'world' => World::Terra->value,
            'name' => $name,
            'codename' => trim((string) ($row['en'] ?? '')) ?: null,
            'race' => trim((string) ($row['race'] ?? '')) ?: null,
            'affiliations' => $this->rosterAffiliations($row, $factions),
            'birth_place' => $birthPlace ?: null,
        ];
    }

    /**
     * 终末地名单的一条 → 统一形状。
     *
     * 「管理员·男 / 管理员·女」共用英文代号 Endministrator：那是**同一个人**的两个性别版本，
     * 不是两个人 —— 已有的「管理员」就是它。按英文代号去重，而不是把两个变体都建出来：
     * 三个管理员并排出现，读者只会困惑。
     *
     * @param  Collection<string, int>  $factions
     * @param  array<string, string>  $seenCodenames
     * @param  list<string>  $skipped
     * @return array{world: string, name: string, codename: string|null, race: string|null,
     *     affiliations: array<int, array{sort_order: int}>, birth_place: string|null}|null
     */
    private function fzRosterEntry(array $row, Collection $factions, array &$seenCodenames, array &$skipped): ?array
    {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        // 同一个人在两边叫法不同时，以本仓库的名字落库（见 FZ_NAME_ALIASES）
        $name = self::FZ_NAME_ALIASES[$name] ?? $name;

        $codename = trim((string) ($row['nameEn'] ?? ''));

        if ($codename !== '' && isset($seenCodenames[$codename])) {
            // 提示里用来源的原名 ——「管理员·女（与 管理员 共用…）」才读得出说的是哪一行
            $skipped[] = trim((string) ($row['name'] ?? '')).'（与 '.$seenCodenames[$codename]
                .' 共用英文代号 '.$codename.'，是同一个人的另一个版本）';

            return null;
        }

        if ($codename !== '') {
            $seenCodenames[$codename] = $name;
        }

        // 势力照抄来源写法：「■■」是 Wiki 对未公开信息的遮盖，不是势力名 ——
        // 来源自己说「不告诉你」，这与「我们漏登了一个势力」是两回事：前者留空，后者才要报警
        $faction = trim((string) ($row['faction'] ?? ''));
        $factionId = $factions[$faction] ?? null;

        if ($faction !== '' && $faction !== '■■' && $factionId === null) {
            // 阵营表理应接得住名单里的每一个势力；接不住时说出来，而不是静默丢掉归属
            $skipped[] = $name.'（势力「'.$faction.'」不在阵营表里，归属没有挂上）';
        }

        return [
            'world' => World::Talos->value,
            'name' => $name,
            'codename' => $codename ?: null,
            'race' => trim((string) ($row['race'] ?? '')) ?: null,
            'affiliations' => $factionId === null ? [] : [$factionId => ['sort_order' => 0]],
            // 终末地的名单没有出身地一栏
            'birth_place' => null,
        ];
    }

    /**
     * 已有条目：**只补不覆盖**。
     *
     * 人工核过的东西（简介、代号、以及一切非空的字段）一律不碰；这里补的是
     * 名单里有、而此前结构上记不下的东西 —— 多出来的那些归属、出身地，与空着的种族。
     * 覆盖会让一份批量导出把逐条核对过的字段冲掉，那是本末倒置。
     *
     * @param  Collection<string, int>  $races
     * @param  Collection<int, Place>  $places
     */
    private function fillMissingFacts(Character $character, array $entry, Collection $races, Collection $places): void
    {
        $character->factions()->syncWithoutDetaching($entry['affiliations']);

        $fill = [];

        // 代号空着 ≠ 人工核过：名单给的英文名（PRTS 的 en / fz 的 nameEn）是**来源写法**，
        // 照抄而不是自己转写 —— 手工批次当初对「管理员」这类社区转写存疑而留空，
        // 名单接入后有了可靠出处，这里只补空、绝不覆盖已填写的代号。
        // 不在名单里的人物（历史人物、剧情人物，以及塔卫二的阿伯莉）没有来源，保持空。
        if (blank($character->codename) && $entry['codename'] !== null) {
            $fill['codename'] = $entry['codename'];
        }

        if (blank($character->race_id) && $entry['race'] !== null && isset($races[$entry['race']])) {
            $fill['race_id'] = $races[$entry['race']];
        }

        if (blank($character->birth_place) && $entry['birth_place'] !== null) {
            $fill['birth_place'] = $entry['birth_place'];
            $fill['birth_place_id'] = $this->resolveBirthPlace($entry['birth_place'], $places);
        }

        if ($fill !== []) {
            $character->forceFill($fill)->save();
        }
    }

    /**
     * 名单里的势力取值 → `[阵营 ID => ['sort_order' => 层序]]`。
     *
     * 三层（`group` / `team` / `nation`）**全部保留**：PRTS 允许一个干员同时属于
     * 「深海猎人」与「阿戈尔」，而这两条都是事实 —— 改造前只装得下一条，
     * 于是另一半被静默丢掉。层序照抄来源（小队 → 团体 → 国别），
     * 因此越具体越靠前，界面上第一条就是这个人最常被认作的身份。
     *
     * @return array<int, array{sort_order: int}>
     */
    private function rosterAffiliations(array $row, Collection $factions): array
    {
        $affiliations = [];

        foreach (['group', 'team', 'nation'] as $layer => $key) {
            $value = trim((string) ($row[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            $id = $factions[self::FACTION_ALIASES[$value] ?? $value] ?? null;

            // 同一个阵营出现在两层时取更具体的那一层（层序越小越具体）
            if ($id !== null && ! array_key_exists($id, $affiliations)) {
                $affiliations[$id] = ['sort_order' => $layer];
            }
        }

        return $affiliations;
    }

    /**
     * 出身地写法 → 地名 ID。
     *
     * 与 `location` 的匹配规则**不同**：出身地是一个短值（「乌萨斯」「炎」「未公开」），
     * 不是句子，因此只认「整值等于地名、别名或写法对照」，不做子串匹配 ——
     * 拿子串去认，「瓦伊凡」这类同名的种族与地区就会张冠李戴。
     */
    private function resolveBirthPlace(string $value, Collection $places): ?int
    {
        // 去掉「（独立城邦）」「（自称）」这类限定语后再试一次
        $bare = (string) preg_replace('/（[^）]*）\s*$/u', '', $value);

        foreach (array_unique(array_filter([$value, $bare])) as $candidate) {
            $name = self::BIRTH_PLACE_ALIASES[$candidate] ?? $candidate;

            foreach ($places as $place) {
                if ($place->name === $name || in_array($name, (array) $place->aliases, true)) {
                    return $place->id;
                }
            }
        }

        return null;
    }

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
    /**
     * 种族字典（《大地巡旅》第四章「泰拉种族」）。
     *
     * 书里立目的种族一律给出概要；只在别处带过一句的（见 $mentionedOnly）、
     * 或全书都没有可用材料的（见 $blank），如实少写或不写 —— 宁可缺失，也不要靠印象写。
     *
     * 2026-09-26 订正：此前这里只给了 16 个种族概要，另有 20 个「只登记名字」——
     * 理由写的是「书中第四章未单独立目」，但那是个检索失误：后半章的条目标题丢了 `#`
     * 前缀（导出时还夹着页码噪声），数一下才发现其中 16 个其实都立了目。现已补齐。
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
            ['依特拉', 'Itra', '多居寒冷的高原山地或冰原附近，常见于炎国、谢拉格、乌萨斯与萨米的部分地区。外观与卡普里尼、埃拉菲亚、库兰塔相似，但并不长角，身体素质略逊于库兰塔。高寒地区的危险生活让他们变得多疑而警惕，许多族群仍保留着排外的传统。谢拉格的依特拉可能是最早崇拜耶拉冈德的种族之一，不少经文与典籍正是由他们撰写、编纂的。'],
            ['匹特拉姆', 'Petram', '极其少见，许多人从未听说过。避世、居于水源丰富之地，生理构造独特，没有尾巴、角或鳞片等显著外观特征。学界认知极不充分，少数研究者甚至认为该种族并不存在。聚落多掌握高明的熔炼与生产技术（书里有出色的工匠与材料学家），拒绝向外人分享。还有一个至今没有解释的现象：炎国、维多利亚与伊比利亚沿海的匹特拉姆与上岸的阿戈尔走得很近，有些还互称「同胞」——这在其他种族中从未被记录过。'],
            ['阿纳缇', 'Anaty', '常与山脉绑定的常见种族，长期生活在远离平原的山区，对客人热情相待。善奔走，崎岖山地如履平地，因此多从事行商与信使；随着移动城市发展，业务也转到城市上。偏远族群保留「成年礼」传统：把将成年者放逐到荒山独立生存一段时间。'],
            ['皮洛萨', 'Pilosa', '极易被误认、极少出现在人群中，连作者也未曾亲见，历史上有过记录但传闻不多。已知的反应迟缓特征见于矿场与漫画的记录：一位皮洛萨矿工因「反应迟缓」被困在铁丝网顶端，而东国漫画则描写过一位把迟缓化为剑理、名震一方的皮洛萨剑豪。'],
            ['德拉克', 'Draco', '在泰拉历史舞台上扮演重要角色的神民种族，人数稀少、零散分布，各地区族群有鲜明的地域特征。常以英雄或统治者的形象出现在神话中，近百年已有可信数据证明其在源石技艺适应性与某些身体素质上确有优势。「德拉克」这一统称直到近千年才在核心圈形成。'],
            ['龙', 'Lung', '炎国独有的神民种族，缔造了古老文明并统治着繁荣开明的帝国。炎国皇室祖先「真龙」以「炎」为名，皇帝继承其名号，皇室自称「真龙一族」；除皇族外炎国还生活着许多以龙自居、同为炎氏赐姓百氏后代的龙。炎国拥有庞大的文书体系，其起源历史比其他种族的传说更翔实可信。'],
            ['麒麟', 'Kylin', '炎国大地上人数稀少但地位崇高的种族，历来是朝堂与府衙的常客。外貌与埃拉菲亚相似，但作为神民种族更为入世。早在百氏之乱前就在炎氏部族中担任侍卫与术师；传说中一位麒麟术师在大湖施展术法使雷霆自湖中生出，那片大湖被称为「雷池」。其招引雷电的技艺传承至今，称为「雷法」。'],
            ['阿戈尔', 'AEgir', '分布从泰拉沿海到内陆水体附近，拥有许多分支，体质差异显著：大多亲近水体但并非都能水下呼吸；对水的依赖超越其他种族，大量失水的后果也更严重，因此常备保湿霜剂与蒸馏装备。迁徙图景多为由泰拉外沿向内陆散布，起源学学者推断其来自海洋。'],
            ['萨科塔', 'Sankta', '头顶光环、身后光翼，是荣受律法的象征，千年以来在拉特兰城过着秩序井然的生活。最神秘之处是律法赋予的共感能力——对他们是像观察表情一样不自觉的行为。随身携带的「铳」被视为来自律法的赐予与信仰的象征，称为「守护」，拉特兰城内每个适龄萨科塔都拥有一把甚至多把。'],
            ['萨卡兹', 'Sarkaz', '较为少见、长相各异的种族，在人类历史中始终遭受其他种族敌视。有以王庭为核心的十支重要氏族，也有歌利亚、阿纳萨等少量非王庭氏族，绝大多数萨卡兹是各氏族的混血。长期流动的流浪生活使许多带萨卡兹烙印的传统悄无声息地消失，现代人熟悉的萨卡兹雇佣兵亦受此影响。极易感染矿石病。'],
            ['乌萨斯', 'Ursus', '主要居住在泰拉北方的乌萨斯帝国境内，人口众多，在卡西米尔、谢拉格、萨尔贡北部与炎国等地也多有分布。身高与力量高于泰拉平均，北方漫长的冬天又塑造了他们强韧的耐力；分支多以毛发颜色区分，炎国境内还有一支黑白相间的。书中特别区分了「乌萨斯种族」与「乌萨斯民族」：后者是帝国语境下的国民认同，帝国也是全泰拉少数在认知领域构建出「国民」概念的国家之一。'],
            ['菲林', 'Feline', '感官敏锐、大多生有尖耳与长尾，与佩洛、黎博利并列泰拉人数最多、分布最广的种族之一。耳朵与尾巴既是器官，也是他们表达情绪的工具；一部分个体拥有并不显著但颇为独特的遗传特征——瞳孔会随光线发生形态上的微妙变化。书中订正了一处常见印象：维多利亚的菲林大多是阿斯兰远征的追随者与德拉克统治时期当地居民通婚的后裔，并非「自古以来就在那里」的一支。'],
            ['阿斯兰', 'Aslan', '主要居住在萨尔贡与维多利亚，人口稀少。生物学上他们完全是菲林——差异甚至小于菲林内部先民种与神民种之别——作为独立种族是社会认知与行政分类的结果，源自「阿斯兰征服」：他们用武力迫使其他种族承认了这一分类。阿斯兰没有分支，但平均力量极为强大；多为身居高位的贵族，同时是萨尔贡与维多利亚的重要贵族血脉。'],
            ['佩洛', 'Perro', '分布最广、人数最多的种族之一——玻利瓦尔人口最多的种族就是佩洛。他们与鲁珀、沃尔珀、瑞柏巴仅凭外貌极难分辨：远古时代四者曾被统称为「佩洛」，后三者因认知革新与阿斯兰的影响才陆续分离出去。佩洛人口基数庞大，含奎尔莎、刻俄柏洛斯、乌瑞狄姆等神民分支；传统上极重人际关系、友善待人，一般认为这与佩洛庞大的家族结构有关。'],
            ['鲁珀', 'Lupo', '叙拉古的主体种族，乌萨斯、维多利亚等地也有少量分布。生理特征与佩洛差距甚小，却最早从「佩洛」中分离、也最不容易被误认。叙拉古的鲁珀家族观念极强（同族会把尾巴染成同一颜色、或在耳尖打同样的记号），但不同于佩洛的友善，这种观念带来的是高度排外、家族间常怀敌意——书中把它与叙拉古活跃的「狼群」兽亲、以及「狼之主」之争联系起来。他们少用耳尾动作传情，说话时几乎总在打手势。'],
            ['沃尔珀', 'Vulpo', '因为生理差异极细微，至今在许多地区仍被误认作佩洛，他们的分布统计也因此并不可信，但整体占比确实偏低。体型中等，反应速度与身体柔韧性名列前茅，善于在复杂环境中侦察并感知危险，古代就被记载为「佩洛中较为灵敏的一支」，是各部族挑选斥候与信使时的首选。神民分支中最著名的是东国的九尾沃尔珀。尾部蓬松的毛发是最大特征，叙拉古由此发展出「尾巴美容」的狂热传统，并催生了向各族开放的「尾巴护理大赛」。'],
            ['瑞柏巴', 'Reproba', '人口较为稀少，主要生活在萨尔贡。部落时代他们的祖先保留着「亲近萨卡兹」的古老传统，因此在当时的佩洛中是饱受非议的一支；「瑞柏巴」这一分类直到阿斯兰带来的新种族边界认知之后才从佩洛中分离出来。身体素质中等，多为带绒毛的耳尾、部分个体身上有斑点或条状花纹，女性力量显著强于男性。他们有着由女性主导的大家族（可近百人，推举一位名义上的女「大家长」）与浓厚的尚武氛围，长期为萨尔贡提供可编成精锐部队的士兵。'],
            ['黎博利', 'Liberi', '对体表生有羽毛的神民与先民的统称，是泰拉最庞大的族群之一：哥伦比亚多元种族的主体之一，伊比利亚与拉特兰也有大量黎博利人口。头部与部分躯干的毛发被羽毛替代，中等身高、体重较轻，身体素质普通。分支众多，最著名的神民分支是骏鹰——黎博利式的羽毛配库兰塔式的尾巴，建立了骏鹰王国。人数与分布都太广，反倒很难总结出他们有什么共同的文化传统。'],
            ['卡特斯', 'Cautus', '主要集中在雷姆必拓的自治州及周边，长耳与毛绒短尾是最令人印象深刻的特征，发达的听觉与灵活的反应弥补了平均体型偏小的不足。为弥补个体身体素质，他们很早便选择群聚群居：部落时代形成富有活力的屯堡聚落（共同劳作、种植根茎类作物、开凿半地洞式的「兔子洞」住房），联合家庭构成「卡特斯大家庭」文化——重视团结友爱，但也鼓励外出冒险、在他乡组织新的家庭，以把屯堡人口控制在合适范围。结晶时代组建起矿业联合体，并在围栏事件中寸步不让。'],
            ['库兰塔', 'Kuranta', '祖先定居于卡西米尔平原，强健善奔；一千多年前有不少库兰塔随梦魔可汗离开草原，因此如今泰拉各地都能见到他们。体格比泰拉平均更为健壮，虽不如乌萨斯与丰蹄强壮，体能却更胜一筹。游牧生活要求随时响应突袭，由此形成了全民习武的军事传统，也就是后来骑士团文化的雏形；「家族」在库兰塔文化中极为重要，骑士家族由「长骑」统领，现代卡西米尔的骑士协会仍保留着长骑制度。神民分支中最出名的是天马与梦魔。'],
            ['丰蹄', 'Forte', '米诺斯的主体种族，也广泛分布在炎国、萨尔贡等多平原的国家。外界对丰蹄的印象常停留在「强壮」上（能量饮料式的刻板印象），但在许多以丰蹄为主体的地区，智慧才是更受重视的美德：米诺斯的丰蹄聚落自古沉浸于文艺创作与思想探讨，几位备受尊敬的古代思想家正是丰蹄之身，最著名的米诺斯丰蹄费那拉底常与一位萨尔贡菲林女性论道。生活在炎国的丰蹄很可能是泰拉最早从事农耕生产的群体之一，还发明了适应崎岖地形的运输车，在数片耕地之间迁徙以规避天灾。'],
            ['卡普里尼', 'Caprinae', '较为常见，主要居住在莱塔尼亚及其周边。以对源石技艺的优秀掌握能力著称——就平均而言适应性确实优于其他种族，但这同样是被人憧憬放大的刻板印象，个体中也存在完全无法使用源石技艺的人。他们十分爱护头上的角并有一套独特的审美标准，对角的热爱还延伸到丰蹄、埃拉菲亚、瓦伊凡、塞拉托乃至萨卡兹的角上。莱塔尼亚是人口最集中之处，当地热爱艺术文化的风尚普遍被认为与这个种族密切相关。'],
            ['埃拉菲亚', 'Elafia', '泰拉极北的萨米以埃拉菲亚为主体种族；乌萨斯、卡西米尔、莱塔尼亚等多林地国家的埃拉菲亚大都源自萨米，如今多数已随城市扩张遗忘了自然，只有边远地区仍有以他们为主体、在林中过游猎生活的聚落。他们生有状若树枝的分叉角，曾被通俗寓言浪漫化为神秘的「森林之子」。与卡普里尼、丰蹄不同，埃拉菲亚的角在成长的某一阶段会被皮肤组织覆盖并有神经分布——因此以角相碰对他们意味着深入的精神与情感交流，可能是友好，也可能代表深刻的分歧；萨米的一些部族更把角视作感知自然的媒介。'],
            ['瓦伊凡', 'Vouivre', '主要居住在萨尔贡、维多利亚与（有争议的）瓦伊凡联盟控制区内。外表与德拉克多有相似之处，最大的区别在源石技艺的掌控能力上：德拉克普遍远强于瓦伊凡。阿斯兰征服时期瓦伊凡战败，但实力得到认可，获赐萨尔贡北部的大片领土并获准成为阿斯兰的护卫；维多利亚的瓦伊凡则源自一支远征的阿斯兰皇室分支。传统上年轻一代要接受严格的战斗与护卫训练，资质最好者被送进萨尔贡宫廷担任阿斯兰皇族的贴身侍卫，落选者多凭本领成为雇佣兵——这就是瓦伊凡佣兵传统的来源。'],
            ['曼提柯', 'Manticore', '几乎全部居住在萨尔贡境内，非常少见。头部两侧生有成对的膜状翅，躯干延伸出节肢状的甲壳尾部，末节带一根刺针（部分个体的尾针可能带毒）；平均身高与泰拉平均持平，身体素质较为优秀。他们极为避世、素来崇尚低调与隐蔽，移动城市出现后仍选择不进城市，留在偏僻地区维持以往的生活方式。流传的「食人曼提柯」与「曼提柯的咒」之类故事，是外人对未知的恐惧造成的谣言。'],
            ['斐迪亚', 'Phidia', '较为少见，但居所遍布大地——雪原上、森林中、黄土里与楼宇间都找得到他们的踪迹。仅生有一对尖耳与带鳞片的长尾，身高普遍高于泰拉平均而体重又往往低于平均，由此得来「瘦弱无力」的刻板印象（并无依据，整体素质位于平均范围之内）。文学形象常把他们写得感官灵敏、神经纤细，别名为「蛇」，是常被以别名称呼的种族之一，而那个别名多半带着负面含义。事实上绝大多数斐迪亚并没有毒、不会发出「嘶嘶」声，也用双脚走路；作者本人的观察是，他们真正的特点是擅长长期专注于一件事。'],
        ];

        /*
         * 书上只带过一句、没有立目的种族：如实写明它出现在哪一句里。
         *
         * 2026-09-26 订正：这里原本列了 20 个名字、统称「书中第四章未单独立目」，
         * 其中 16 个其实立了目（后半章的标题丢了 `#` 前缀，检索时漏掉），已移入正表；
         * 剩下这三个是真没有立目，全书的全部信息就是各自的这一句。
         */
        $mentionedOnly = [
            ['温迪戈', null, '萨卡兹的王庭氏族之一。《大地巡旅》记叙卡兹戴尔建造第一座移动城市时，温迪戈与食腐者一同封锁平原，连羽兽也无法走漏消息。'],
            ['鬼', null, '萨卡兹的非王庭氏族之一（书里与歌利亚、阿纳萨并举）——全书仅此一处提及。'],
            ['阿纳萨', null, '萨卡兹的非王庭氏族之一（书里与歌利亚、鬼并举）——全书仅此一处提及。'],
        ];

        /*
         * 只在人物数据里出现过、全书都没有可用材料的写法：只登记名字，描述留空 ——
         * 宁可缺失，也不要靠印象写。
         *
         * 「精灵」在书里只撞见一处无法解读的乱码（源石相关段落，句子本身不成立），因此不写。
         * 另外「未公开」「未知」「不明」「未录入」「矮人（自称）」「未知（疑似黎博利）」
         * 这类**不是种族**的写法一律不入字典：它们的正确表达是**留空**。
         */
        $blank = ['精灵'];

        $order = 0;

        /*
         * 落库一律**先按名字找已有行**，再更新。
         *
         * 原因：这批种族里有一半此前是用 `race-<md5>` 这个占位 slug 建的，而人物表的
         * `characters.race_id` 指着它们的主键 —— 若按新 slug 去 updateOrCreate，
         * 会另起一行，把描述仍为空的旧行留在原地，而人物还挂在旧行上。
         */
        foreach ([...$documented, ...$mentionedOnly] as [$name, $english, $description]) {
            $race = Race::firstWhere('name', $name) ?? new Race;

            $race->fill([
                'name' => $name,
                'english' => $english,
                // 有英文名就用它做 slug（锚点 `#race-feline` 比哈希可读）；没有则退回占位
                'slug' => Str::slug((string) $english) ?: 'race-'.md5($name),
                'description' => $description,
                'sort_order' => $order++,
            ])->save();
        }

        foreach ($blank as $name) {
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
            ['维多利亚', 'nation', null, '维多利亚', '由维多利亚王国、塔拉王国、下高卢王国三个法理王国组成的帝国。领土广袤，本土与多个核心圈国家相邻，另有若干开拓区与飞地；皇位空置后，八名公爵各自实际控制一方领土（威灵顿、开斯特、温德米尔三人的权势最盛），帝国因此长期处在多头并立的局面。南部是边界随移动城市巡弋路线而变的塔拉地区。'],
            ['维多利亚王国', 'kingdom', '维多利亚', '维多利亚', '三个法理王国之一，皇室直属领地与各级贵族领地的核心；名字来自建国时的政治宣言 —— 王政派宣称德拉克本就是谷地之王，于是把德拉克统治的地区命名为维多利亚王国、德拉克即维多利亚王室。帝国的爵位体系以它为骨架：公爵领下辖伯爵领，伯爵领再下辖男爵领；子爵这一衔是吞并下高卢后承袭的高卢制度，只在下高卢地区存在。'],
            ['塔拉王国', 'kingdom', '维多利亚', '维多利亚', '维多利亚南方地区。文明史始于萨尔贡的扩张，约泰拉历前 100 年起由来自维多利亚、讲廷盖语的德拉克游牧部族统治，到哈兰杜汗驰骋大地的时代已建立独立国家。为抵挡梦魔可汗，塔拉与维多利亚两个王国选择合并 —— 这就是后世维多利亚帝国的雏形：塔拉国王把「国王」（里·图雅哈）头衔让渡给维多利亚国王，换回「盖尔王」（里·纳盖尔）的称号（「盖尔是王本人的姓名」一说为讹传）。盖尔王一脉绝嗣后，塔拉事务由各大公爵协理至现代，四国战争后这片地区成为威灵顿公爵的领地至今。书中另有一份塔拉视角的记述，与官方版本出入较大。'],
            ['下高卢王国', 'kingdom', '维多利亚', '维多利亚', '维多利亚在四国战争后接管的、原属高卢的富庶地区：帝国由此取得高卢的资源、技术与政治遗产，奠定了新时代的霸权；也因声称拥有它的完整主权，在归属问题上与莱塔尼亚、乌萨斯摩擦不断。它还是移动城市史的考古重镇 —— 战后维多利亚考古学家对下高卢的大规模发掘中，找到了署名「查理王」的移动城堡设计图手稿，开启了一场追溯移动城市历史的大讨论。'],
            ['伦蒂尼姆', 'city', '维多利亚王国', '维多利亚', '维多利亚帝国的首都与政治、金融、文化中心，也是帝国最先进、最繁荣、最有代表性的城市。它的规模远超一般设计：每个地块都大过一座中小型移动城市，地块之间产业分工明确，从工业生产到农业种植一应俱全；维多利亚人对外宣称它是「泰拉的中心」，而事实也确实如此。然而数年前起它与外界断绝了联系 —— 任何技术手段的沟通都失败了，直接前往的勇敢者则被外围驻军客客气气地请了回来。不论它阻隔的是谁，这座城市的城墙或许都将永远屹立不倒。'],
            ['莱塔尼亚', 'nation', null, '莱塔尼亚', '由九个选帝侯区组成的邦联帝国，位于泰拉中央至东部、卡兹戴尔以西，没有固定的首都。立国文书是九大部落与叙拉古共同缔结的《金律乐章》—— 以九区为核心区域、叙拉古为自治领；967 年叙拉古脱离之后，帝国便只余这九个区。'],
            ['海登施威尔大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['恩瓦德大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一；「学士」路德维格曾任该区统治者。'],
            ['厄登赫尔大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['鲁珀坎大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['施彤领大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['瓦瑟领大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一；此地选帝侯曾下令铸造杜卡特金币。'],
            ['福特冈大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['奥施登海姆大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['凯普拉尼亚大区', 'region', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚九大区之一。'],
            ['乌萨斯帝国', 'nation', null, '乌萨斯帝国', '以军事力量为国家组织核心的北方帝国。这片土地原本是黎博利神民骏鹰的骏鹰王国，被征作苦役的乌萨斯先民在其下积下数百年的怒火；泰拉历 31 年伊戈尔起义成功、建立帝国，这是史上第一次先民有组织反抗神民而成功的样例。除省份外另有不受省长管辖的集团军属地；首都圣骏堡于 1033 年迁到巨大的移动平台之上，此后成为帝国最重要的工业中心。', ['乌萨斯']],
            ['圣骏堡', 'city', '乌萨斯帝国', '乌萨斯帝国', '乌萨斯首都，1033 年被迁到巨大的移动平台之上，此后成为帝国最重要的工业中心。它的前身是骏鹰王国的都城：泰拉历 31 年伊戈尔起义军攻下这里，并在城中加冕为皇帝，乌萨斯帝国由此诞生 —— 这座城市的每一次易主，都踩在这个国家历史的转折点上。梦魔可汗的大军离开之后，骏鹰贵族曾强征约二十万人在这座城里进行奢侈的重建，想用一座新生的圣骏堡来彰显王国的重生。'],
            ['格里高利省', 'province', '乌萨斯帝国', '乌萨斯帝国', '临近圣骏堡；1074 年的酒馆冲突与省议会被按军法处决，成为「大叛乱」的直接起因。'],
            ['切尔诺伯格', 'city', '乌萨斯帝国', '乌萨斯帝国', '乌萨斯的重要移动城市、昔日的工业重镇；1096 年 12 月 23 日整合运动在此发动事变（切尔诺伯格－龙门事件），是主线的时间原点。近三年后，人们预期中的大战并未爆发，这座遭劫的城市顽强地存活了下来。事件结束半年后，乌萨斯与炎国达成善后协议 —— 乌萨斯补偿部分经济损失，炎国则减免其中一部分、返还用于重建切尔诺伯格，据说协议是在皇帝费奥多尔的极力推动下才在帝国议会通过的。它在与集团军的抗争中成长，在壮大后成为泄压的代价，最终又成为打破帝国对感染者一贯态度的那轻轻的第一步。'],
            ['哥伦比亚', 'nation', null, '哥伦比亚', '原为维多利亚的开拓区，1016—1019 年独立战争后成为联邦。位于已知大地的西北方、玻利瓦尔的近邻：990 年探险队才在这里找到埋在大平原下的源石矿脉（那封信以「如果一片土地能够放得下一只靴子，它就应当属于维多利亚」开头），此后移动采矿平台拖挂着城镇拔地而起。深层储量不如雷姆必拓，但地表源石环境更稳定、天灾频率相对更低；巴伦矿场等旧源石矿场位于其南部荒地。'],
            ['汐斯塔', 'city', '哥伦比亚', '哥伦比亚', '哥伦比亚境外的自由邦 —— 具有完全自治权的独立城邦，理论上仍属哥伦比亚；附近有高强度火山活动。'],
            ['炎国', 'nation', null, '炎国', '东方大国，三亿人口，下设十九个行政区划；以「真龙」为皇帝，地方最高一级为宣政司，其下设府、属州与县。行政区之间有清晰的界线 —— 这与「贵族封地、地方议会彼此交错」的权力形态不同；朝廷设有专门收集、编纂与保存历史文献的机构，著名的《封龙纪》《百姓考》《百家通历》等即藏于礼部指定的档案馆。'],
            ['尚蜀', 'region', '炎国', '炎国', '炎国西南；本地人以四季如春为傲，城市地块分布在山峦之间 —— 在炎国腹地的尚蜀，人们爱往城市地块上垒山丘、架设索道，把移动城市活成了「山城」。'],
            ['龙门', 'city', '炎国', '龙门', '炎国西北边陲的商贸重镇，进入炎国的必经中转站。'],
            ['谢拉格', 'nation', null, '谢拉格', '以耶拉冈德信仰为中心的北方山国，喀兰圣山在其境内。先祖是被天灾逼上雪山的难民，靠雪山神的庇佑建立最初的聚居地；近年国际学术团队的地质钻孔研究证实，至少千年来这片雪山地区确实没有发生过具有显著影响的天灾 —— 这在泰拉是难解的谜题。古代谢拉格人把这一现象归于神力，因此这里最先诞生的权力组织是代表神权的宗教机构，国家由信仰维系。'],
            ['拉特兰', 'nation', null, '拉特兰', '律法庇护之国；拉特兰城在被改建为移动城市时，重工制品进口自伊比利亚。城中的「启示石塔」老钟楼可能已有数千年历史，而拉特兰的繁荣延续数千年、古物从未入土 —— 它们不是被齐齐整整列在大教堂的陈列室，就是仍在人们的生活中忠实地发挥作用，因此考古学在这里从未存在过；直到 1042 年一件被认为是古代拉特兰圣徒像的文物在城西荒地出土，才轰动了整个考古学界。'],
            ['伊比利亚', 'nation', null, '伊比利亚', '大静谧前坐拥南方丰饶半岛、掌控连通内海与开阔海域的峡道；灾后国土沉没、由数个狭长三角洲组成。黄金时代的伊比利亚以奇观闻名 —— 旗舰「斯图提斐拉」、大灯塔「伊比利亚之眼」，以及被改造为两栖移动城市的港都雷亚－伊比利亚，都出自首席船舶设计师布雷奥甘之手。泰拉历 1038 年大静谧自海洋降临，灯塔失去光芒、宫殿沉入汪洋；此后的伊比利亚在审判庭的封锁中沉默，连伊比利亚人也说不清它是旧日辉煌的延续还是终结。'],
            ['萨尔贡', 'nation', null, '萨尔贡', '南方大国；泰拉纪年即以其「过去与未来之王」发现圣物之年为元年。官修五十卷《萨尔贡史》记载，一万五千年前一群流民自阿卡胡拉丛林走出、在沙漠边缘定居 —— 撇开神话色彩，关键事实是萨尔贡人从别处迁来，且自认是泰拉人类文明的正统。国土以沙漠与绿洲为主，东南的雨林（阿卡胡拉等地）另有王酋领地（1099 年起的阿卡胡拉王酋领地即在此）。'],
            ['卡兹戴尔', 'nation', null, '卡兹戴尔', '萨卡兹的家园所在，游走在核心圈列强之间的狭缝中。两百年前，特蕾西娅殿下与众人一起建起了卡兹戴尔第一与唯一一座移动城市 —— 如今它航行在卡兹戴尔唯一的城市航道上：锈迹斑斑的钢铁躯壳、被金属窗栅包裹的层叠居住区，盛满源石与金属矿物的炉昼夜嘶叫，在身后留下一场又一场间歇性源石雷暴。'],
            ['卡西米尔', 'nation', null, '卡西米尔', '以泰拉中北部的卡西米尔平原为核心的国家：强健善奔的库兰塔世代在此生活，天马一族曾在此立国（天马王国）并靠骑士团不断扩张；梦魔可汗的远征中天马王国彻底溃败，王座从此让给骑士贵族。现代的卡西米尔由移动城市与联合城构成，骑士竞技成了支柱产业，大部分居民已脱离传统家庭进入城市生活，但骑士协会仍保留着骑士家族的长骑制度。'],

            /*
             * 以下为第 5 章其余各卷（5.4 高卢 / 5.7 阿戈尔 / 5.11 玻利瓦尔 / 5.12 叙拉古 /
             * 5.14 米诺斯 / 5.15 萨米 / 5.16 雷姆必拓 / 5.17 炎国 / 5.18 东国）。
             *
             * 至此第 5 章十九卷的政区全部入树。此前只收了 10 卷，代价是条目的 `place_id`
             * 挂不上去（「叙拉古」「玻利瓦尔」这些 location 是照原文抄的短名），
             * 而挂不上不是留空，是**静默缺失**。
             */
            ['高卢', 'nation', null, '高卢', '1031 年四国战争的战败方：首都林贡斯被联军夷平、国土被瓜分，帝国从地缘政治版图上消失；如今只以「高卢区」与三千万说高卢语者的文化认同延续。'],
            ['阿戈尔', 'nation', null, '阿戈尔', '海中的知识与技术文明。其先民从各路水道汇入大海，后代即伊比利亚所称的「岛民」；如今面临海嗣的威胁。陆上的阿戈尔分布从沿海到内陆水体附近，分支众多、体质差异显著：大多亲近水体，却并非都能在水下呼吸；对水的依赖超越其他种族，大量失水的后果也更严重，因此常备保湿霜剂与蒸馏装备。炎国、维多利亚与伊比利亚沿海的一些匹特拉姆村庄与上岸的阿戈尔走得很近，有些还会互称「同胞」—— 这在其他种族之间从未被记录过。'],
            ['赫库兰尼姆', 'city', '阿戈尔', '阿戈尔', '阿戈尔的城市；以地热能为主要能源，开掘了三百余条能源井道，隔热穹顶可承受四级以下强度的火山喷发。'],
            ['玻利瓦尔', 'nation', null, '玻利瓦尔', '泰拉西部。三百多年间从富裕的伊比利亚玻利瓦尔总督区沦为「玻利瓦尔地区」；莱塔尼亚与哥伦比亚先后试图控制它，都没能成功。'],
            ['叙拉古', 'nation', null, '叙拉古', '位于莱塔尼亚东方的城邦地区，967 年脱离莱塔尼亚；此后长达七十年的内乱，直到西西里夫人以灰厅与城邦联合议事会重新立起秩序。荒野上由鲁珀兽亲组成的「狼群」密度高到超乎常理（生物质能塔图近乎沙漏形），给当地原始部族带来持续的生存压力与食物竞争 —— 家族传统与排外意识正由此生长出来。'],
            ['西西里', 'city', '叙拉古', '叙拉古', '灰厅所在地。建城之初由数个小家族共治，一场政变后为西西里家族独占，该家族随着灰厅成立而走到尽头。'],
            ['米诺斯', 'nation', null, '米诺斯', '曾被萨尔贡占领的诸城邦；英雄崇拜与雕塑艺术的故乡，萨尔贡统治时期宏伟的英雄巨像尽数被拆毁。主体种族是丰蹄：境内自古分布着许多丰蹄聚落，相传他们终日沉浸在文艺创作与思想探讨之中 —— 与近旁高度尚武的瑞柏巴部族形成鲜明对比；几位备受尊敬的古代思想家正是丰蹄之身，最著名的米诺斯丰蹄费那拉底便常与一位萨尔贡菲林女性论道。'],
            ['萨米', 'nation', null, '萨米', '大地的止境，森林与冻原的领土；体制最为独特的「国家」，以雪祀大会与独特的法术闻名。主体种族是埃拉菲亚，许多部族至今在林中过游猎生活，萨米南方边界一带留有古代壁画。萨米人的信仰中有「萨米的意志」一说 —— 它与谢拉格的耶拉冈德一样，究竟是不是「巨兽」，至今无法确证；一支名为「独眼巨人」的萨卡兹也在萨米过着独特的独居生活。'],
            ['察帕特', 'settlement', '萨米', '萨米', '萨米南方的度假小镇，邻近楚阿维亚利湖。'],
            ['雷姆必拓', 'nation', null, '雷姆必拓', '泰拉东南方，塔尔干主矿脉所在地；以矿业厂区为单位构成的松散联合体，没有强力中央，只有各矿区自己的联防队。塔尔干分布带的深层源石储量占全泰拉已探明的六成，仅雷姆必拓一国境内就占三成。主体种族是卡特斯，结晶时代以来他们组建起矿业联合体 —— 正是它在围栏事件中寸步不让，抵住了一个强盛帝国的压力。'],
            ['塔尔干主矿脉', 'landmark', '雷姆必拓', '雷姆必拓', '已探明的三大源石主矿脉之一，储量最大、环境最特殊：地表源石矿体最密集，天灾也最频繁剧烈。'],
            ['尤立卡自治州', 'region', '雷姆必拓', '雷姆必拓', '1090 年围栏事件后成立的自治州。'],
            ['东国', 'nation', null, '极东', '最东端的国家。以《皇敷记》为国书，经历御神神话、在与炎国的文化交流中形成律政国制，以及武家崛起的御神川幕府时代。'],

            /*
             * ---- 补录：各卷正文里被点名、并给了实质描述的下级地名 ----
             *
             * 上面那批是**政区骨架**（十九卷的国与一级政区），下面这批是正文里
             * 真正被写到的城市、聚落与地理实体 —— 少了它们，条目里的 `location`
             * （「多索雷斯」「特里蒙」这些照原文抄的短名）就挂不上链接，
             * 而挂不上不是留空，是**静默缺失**。
             *
             * 收录标准与上面一致：书里写了什么就记什么，层级拿不准的不猜；
             * 「所属政体」为 null 的几处，是书里写明它早于现存政体（骏鹰王国、天马王国），
             * 或本身跨越国界（科罗萨主矿脉、无尽冰原），**不硬塞一个归属**。
             *
             * 数组顺序仍是「父先于子」：乌提卡领在恩瓦德大区之内、夕照区在维谢海姆市之内、
             * 喀兰圣山在谢拉格盆地之内 —— 上级若尚未插入，parent_id 会静默落成 null。
             */
            ['龙临谷地', 'landmark', null, '维多利亚', '泰拉大地中央的丰饶谷地，水系发达、土地丰饶；德拉克氏族在此定居，是维多利亚文明与维多利亚王国的发源地。'],
            ['高多汀地区', 'region', '维多利亚', '维多利亚', '高多汀公爵势力范围内的地区；开拓军曾把这一带的土豆带去哥伦比亚。'],
            ['温德米尔', 'region', '维多利亚', '维多利亚', '温德米尔公爵的领地。八大公爵各据一方，它是其中最有权势的三家之一。'],
            ['梅卡托郡', 'province', '维多利亚', '维多利亚', '维多利亚的一个郡；梅卡托男爵的世袭地产在这一带。'],
            ['曼努敦', 'settlement', '维多利亚', '维多利亚', '维多利亚的小镇，《大地巡旅》作者埃里克森博士的出生地。'],
            ['弗拉维亚', 'settlement', '维多利亚', '维多利亚', '维多利亚—萨尔贡商路上最重要的城镇，毁于「被裁决日」天灾后就地择址重建。'],

            ['乌提卡领', 'region', '恩瓦德大区', '莱塔尼亚', '恩瓦德大区内的领地；「学士」路德维格的墓地在此，迄今仍有纪念活动。'],
            ['维谢海姆市', 'city', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚城市，境内有感染者聚居区「夕照区」。'],
            ['夕照区', 'settlement', '维谢海姆市', '莱塔尼亚', '维谢海姆市的感染者聚居区，已开始采用独特的源石技艺缓解矿石病的部分症状。'],
            ['崔林特尔梅', 'city', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚城市，剧作与演艺活动的重镇。'],
            ['谷腾堡', 'city', '莱塔尼亚', '莱塔尼亚', '莱塔尼亚的学术中心，威廉－赫尔佐格－谷腾堡大学等「谷腾堡三校」所在地。'],
            ['双塔', 'landmark', '莱塔尼亚', '莱塔尼亚', '双子女皇的居所与中枢；「女皇之声」之外的来客常被拦在双塔之外。'],

            ['骏鹰王国', 'kingdom', null, null, '乌萨斯建国之前由黎博利神民「骏鹰」统治的北方王国。这批神民原本迫于萨卡兹的威胁迁居北方，在山地丘陵间建立起最早的国家，并以自己的名字命名脚下的土地；骏鹰贵族居住在山间的巢堡之上，睥睨山下大地的众生，把乌萨斯等先民种族征作仆役与武装 —— 乌萨斯比他们更早适应北方的严酷环境，这反而招来了更苦的活计。泰拉历 31 年，伊戈尔起义军攻下它的首都圣骏堡并在城中加冕，王国就此被乌萨斯帝国取代。'],
            ['无尽冰原', 'landmark', null, null, '大陆极北的冰原；萨米诸部落曾在乌萨斯的威逼下退至此地方才得以存续。'],

            ['林贡斯', 'city', '高卢', '高卢', '高卢首都、「世界之都」，833 年起由军人出身的奥瑟马恩伯爵主持、历时二十年改建为泰拉首座移动城市 —— 高卢由此成为这片大地上第一个把古老城市搬上移动平台的国家。林贡斯有自己的傲气：在城里人看来，世界只有两种人 —— 林贡斯人和其他人。1031 年四国战争中它被联军攻陷并彻底夷平，如今只余宫殿的尖顶与拱廊留在旧影像里；高卢裔学者谈起混乱时，仍会拿它的陷落作比。'],
            ['上高卢王国', 'kingdom', '高卢', '高卢', '与下高卢相对的高卢法理王国，其领地由多个国家分别控制 —— 正因如此，握有完整下高卢的维多利亚，比这些各据一隅的国家更有「联统整个高卢」的正当性，高卢故地的归属由此成为帝国与邻国之间长期摩擦的引信。'],
            ['中央河谷', 'landmark', '下高卢王国', '维多利亚', '下高卢地区水土丰沃的河谷；四皇会战在此打响。'],

            ['拉特兰城', 'city', '拉特兰', '拉特兰', '拉特兰的圣城与律法中心，绝大多数拉特兰公民居住于此；被改建为移动城市时，重工制品进口自伊比利亚。城里的「启示石塔」老钟楼可能已有数千年历史，石砖或许由初代圣徒亲手砌上，但居民每天路过它就如同路过一座新建的普通民居 —— 历史的厚度在这座城市里被自然地压缩了。他国人看它往往经过三段认知：先以为这是死板肃穆的国度，再被萨科塔的天马行空吓一跳，最后才明白所有自由与享乐都只是秩序的副产物。'],
            ['启示石塔', 'landmark', '拉特兰城', '拉特兰', '相传由初代圣徒亲手搭建的老钟楼；每百年拉特兰人聚集其下迎接新世纪。'],
            ['拉特兰大教堂', 'landmark', '拉特兰城', '拉特兰', '拉特兰城的大教堂，设陈列室与会客室。'],
            ['司提望区', 'settlement', '拉特兰城', '拉特兰', '拉特兰城内的城区，设中心医院与中学。'],

            ['雷亚－伊比利亚', 'city', '伊比利亚', '伊比利亚', '伊比利亚的王城与「黄金时代」最重要的港都，后被改造为两栖移动城市 —— 陆上与海上皆可航行，改造工程出自首席船舶设计师布雷奥甘之手；同一双手还留下了旗舰「斯图提斐拉」与大灯塔「伊比利亚之眼」。泰拉历 1038 年大静谧自海洋降临之后，伊比利亚的国土沉没、只余数个狭长三角洲。'],
            ['颂圣棱堡', 'landmark', '伊比利亚', '伊比利亚', '审判庭控制的棱堡；阿戈尔岛民的研究成果被封存于其档案柜中。'],
            ['伊比利亚之眼', 'landmark', '伊比利亚', '伊比利亚', '黄金时代的大灯塔及其网络；大静谧中接二连三熄灭。'],
            ['玻利瓦尔总督区', 'province', '伊比利亚', '伊比利亚', '伊比利亚在泰拉西北设立的总督区，首任总督为德莱昂。'],

            ['赫库兰尼姆火山', 'landmark', '赫库兰尼姆', '阿戈尔', '海中规模最大的能源仓储之一，以三百余条能源井道供能。'],
            ['铸阳巨械', 'landmark', '赫库兰尼姆', '阿戈尔', '赫库兰尼姆城内的设施，火山喷发期间停转并暂停对外开放。'],
            ['隔热穹顶', 'landmark', '赫库兰尼姆', '阿戈尔', '赫库兰尼姆的穹顶，可承受四级或以下强度的火山喷发。'],

            ['谢拉格盆地', 'region', '谢拉格', '谢拉格', '高原上面积最大的盆地；三大家族、蔓珠院与喀兰圣山都在其中。', ['中央盆地']],
            ['图里卡姆', 'city', '谢拉格', '谢拉格', '谢拉格聚居地中最大的城市。'],
            ['喀兰圣山', 'landmark', '谢拉格盆地', '谢拉格', '谢拉格人朝觐的圣山，与雪山神耶拉冈德同名。'],

            ['卡西米尔平原', 'landmark', '卡西米尔', '卡西米尔', '天马最初征服并被分封的广袤平原，卡西米尔的地理主体。'],
            ['天马王国', 'kingdom', null, null, '天马在骑士团的基础上建立的王国。骑士团超员失控之后，天马们改组拆分旧骑士团、另组一系列新骑士团，并首次明确规定了骑士、扈从与平民各自的权利和义务（其他种族也获准加入骑士行列）；在失去对骑士头衔的垄断后，他们宣布建国，宣称天马的黄金血脉足以证明其居于王位的正当性。它与东方的骏鹰王国摩擦不断，最终等来的是远征而来的另一支库兰塔神民 —— 梦魔；王政就此终结，王座让给了骑士贵族。'],
            ['大骑士领', 'region', '卡西米尔', '卡西米尔', '卡西米尔的骑士核心领区。'],
            ['卡瓦莱利亚基', 'city', '卡西米尔', '卡西米尔', '卡西米尔城市，「四城联合时期」的战略要地。'],

            ['特里蒙', 'city', '哥伦比亚', '哥伦比亚', '「科技之城」，特里蒙大学与特里蒙理工学院所在地；哥伦比亚一元纸币印有它的街景。'],
            ['麦克斯哥伦比亚特区', 'region', '哥伦比亚', '哥伦比亚', '联邦政府总部所在的特区。'],
            ['南方大湖区', 'landmark', '哥伦比亚', '哥伦比亚', '曾横贯南北的广大湖泊水域，后因地形剧变干涸为骸骨荒原。'],
            ['骸骨荒原', 'landmark', '哥伦比亚', '哥伦比亚', '哥伦比亚国土最南端的低地荒漠，以风蚀地貌与巨大骸骨得名。'],
            ['蓝卡坞', 'city', '哥伦比亚', '哥伦比亚', '哥伦比亚的影视产业城市，「流水线」式文化生产的代名词。'],
            ['新汐斯塔', 'city', '汐斯塔', '哥伦比亚', '汐斯塔整体搬迁后建成的移动城市，延续原址的海滨风情与温泉。'],
            ['汐斯塔火山', 'landmark', '汐斯塔', '哥伦比亚', '位于汐斯塔市原址东部的火山，黑曜石产地，后禁止开采。'],
            ['南方小径', 'landmark', null, null, '由萨尔贡军道演变而来、连接哥伦比亚与伊比利亚的陆路；汐斯塔因它而兴起。'],
            ['科罗萨主矿脉', 'landmark', null, null, '已探明的三大源石主矿脉之一，自西南向东北横贯哥伦比亚平原，并延伸至玻利瓦尔与萨米。'],

            ['玻利瓦尔平原', 'landmark', '玻利瓦尔', '玻利瓦尔', '玻利瓦尔的地理中心与人口核心，九成以上人口居于此。'],
            ['玻利瓦尔边境高原', 'landmark', '玻利瓦尔', '玻利瓦尔', '玻利瓦尔北部靠近诺勒特海的高原，占全国面积约两成，资源稀少。'],
            ['诺勒特海', 'landmark', null, null, '玻利瓦尔北方的海；多索雷斯每年引其海水更换城内人工海里的水。'],
            ['多索雷斯', 'city', '玻利瓦尔', '玻利瓦尔', '玻利瓦尔境内唯一的中立移动城市，「娱乐之都」。'],
            ['索雷城', 'city', '玻利瓦尔', '玻利瓦尔', '多索雷斯的前身：1007 年建立的普通移动城市，1086 年更名为多索雷斯。'],
            ['拉乌尼达', 'city', '玻利瓦尔', '玻利瓦尔', '财阀议会所在地，玻利瓦尔联合政府在此成立。'],
            ['大辛德拉卡托', 'city', '玻利瓦尔', '玻利瓦尔', '玻利瓦尔王国（辛嘉斯王朝）的王都。'],

            ['蒙特卢佩', 'city', '叙拉古', '叙拉古', '叙拉古的首都，被改造为移动城市。'],
            ['沃尔西尼', 'city', '叙拉古', '叙拉古', '叙拉古城市；其新城区后来分离为独立的移动城市。'],
            ['新沃尔西尼', 'city', '叙拉古', '叙拉古', '由沃尔西尼新城区分离而成的移动城市，号称「没有家族的城市」。'],

            ['沙尔－阿加德', 'city', '萨尔贡', '萨尔贡', '萨尔贡都城「黄金之城」；帕夏赴任前须至此受封。'],
            ['阿卡胡拉丛林', 'landmark', '萨尔贡', '萨尔贡', '萨尔贡立国神话中流民走出的大丛林，今为部落民聚居的雨林。'],
            ['阿卡胡拉王酋领地', 'region', '萨尔贡', '萨尔贡', '1099 年建立的王酋领地，依娜姆商会即以其尊名冠名。'],
            ['焚风热土', 'landmark', '萨尔贡', '萨尔贡', '泰拉历 15 年大爆炸造就的生命禁区，热风自此终年北吹。'],
            ['塔尔萨萨王酋领', 'region', '萨尔贡', '萨尔贡', '因发现塔尔萨萨古镇而得名的王酋领地。'],
            ['塔尔萨萨古镇', 'settlement', '塔尔萨萨王酋领', '萨尔贡', '已知最早的源石制品工坊所在的古代城镇遗址，年代可上溯至一千五百年前。'],

            ['米诺斯山脉', 'landmark', null, '米诺斯', '泰拉中央主山系最南端的地理区域，米诺斯以此得名。'],
            ['赫里亚山', 'landmark', '米诺斯山脉', '米诺斯', '米诺斯三座主峰中最高的一座，阿涅斯河发源于此。'],
            ['荷谟伊山', 'landmark', '米诺斯山脉', '米诺斯', '米诺斯第二高峰，终年积雪与冰川；雅赛努斯建在其山脚，特尔斐河发源于此。'],
            ['特尔斐河', 'landmark', '米诺斯', '米诺斯', '发源于荷谟伊山，流经雅赛努斯与科林尼亚故城的河流。'],
            ['阿涅斯河', 'landmark', '米诺斯', '米诺斯', '将米诺斯地区与萨尔贡分隔开来的河流。'],
            ['阿加门山口', 'landmark', '米诺斯', '米诺斯', '通往米诺斯核心区的最后一道关隘。'],
            ['雅赛努斯', 'city', '米诺斯', '米诺斯', '米诺斯最古老的城邦，位于荷谟伊山山脚。'],
            ['科林尼亚', 'city', '米诺斯', '米诺斯', '米诺斯的商业与交通之都。'],
            ['拉刻代蒙', 'city', '米诺斯', '米诺斯', '米诺斯的战士与武者之城，神殿最多。'],
            ['米诺斯帕夏行省', 'province', '萨尔贡', '萨尔贡', '萨尔贡征服米诺斯后设立的帕夏行省。'],

            ['楚阿维亚利湖', 'landmark', '萨米', '萨米', '萨米湖泊，其名的意思是「角兽」；察帕特度假小镇邻湖而建。'],
            ['冬牙群山', 'landmark', '萨米', '萨米', '萨米的群山。'],
            ['黑森林', 'landmark', '萨米', '萨米', '萨米地貌四级结构中的森林带。'],
            ['因非冰原', 'landmark', null, null, '极北的冰原；迭日涅夫一行可能是第一批深入后成功返回的泰拉人。'],

            ['终极大铁屯', 'city', '雷姆必拓', '雷姆必拓', '雷姆必拓最大的移动城市。'],

            ['玉门', 'city', '炎国', '炎国', '大漠孤烟中的城塞，炎国的边防重镇，退役军士多居于此。'],
            ['常稷', 'city', '炎国', '炎国', '炎国城市，稷府学宫坐落于此。'],

            ['古苇原国', 'kingdom', '东国', '极东', '东国的前身，在山岳东皇时正式改称「东国」。'],
            ['御神神禾京', 'city', '东国', '极东', '古苇原国建立的权力中心，神社与幕府侍内所所在地。'],
            ['御神川', 'landmark', '御神神禾京', '极东', '御神神禾京郊外的河川，幕府侍内所建于此。'],
            ['御机', 'city', '东国', '极东', '东国南北朝时期南院光元政权的首府，全称「南院行在御机大社」，后成为危机四伏的混乱都市。'],
            ['锁川', 'city', '东国', '极东', '东国南北朝时期北院光严政权的首府，全称「北院镇守锁川城」。'],

            ['卡兹戴尔城', 'city', '卡兹戴尔', '卡兹戴尔', '特蕾西娅主持兴建、由旧城废墟重组的萨卡兹第一座移动城市。'],
            ['银色山脉', 'landmark', null, '卡兹戴尔', '沉入平原下方的山脉，提卡兹先祖曾在其间攀援伏行。'],
            ['哀愁之地', 'region', '卡兹戴尔', '卡兹戴尔', '今卡兹戴尔地区的古称，传闻中居住于此者必染晶石诅咒。'],
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
                'faction_id' => $faction === null ? null : ($factions[$faction] ?? null),
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

            /*
             * ---- 补录：终末地 Wiki 记载的塔卫二地点 ----
             *
             * 出处分两档，可靠性不同，收录时一视同仁地在说明里保持克制：
             *
             *  1. **《中枢档案》**（`www.fz.wiki/wiki/PRTS记录/中枢档案/<名称>`）——
             *     游戏内的档案原文，属官方设定文本，是本批地名的主要依据；
             *  2. **《环带纪略》**（`www.fz.wiki/wiki/环带纪略/*`）—— 该 Wiki 用户共建的
             *     考据项目，仅用于补第一档没写到的地点（如帝江号、城市母亲）。
             *
             * 与泰拉那棵树同样的纪律：**只收出处里写明了的层级**，
             * 上级在出处里没写就留空（帝江号是天上的作业船、塞什卡建在空中、
             * 几处要塞与库房分属各组织），宁可挂空也不硬塞一个父级。
             */
            ['武陵地区', 'region', '文明环带', '宏山科学院开展侵蚀治理工程的重点地区，境内有武陵甚大裂隙贯穿地下。'],
            ['武陵科学发展区', 'region', '武陵地区', '武陵地区下设的科学发展区。'],
            ['武陵城', 'city', '武陵地区', '武陵地区唯一的大型定居点，宏山科学院的重要科研基地；前身为塔罗斯历 132 年建立的武陵科考站。'],
            ['武陵科考站', 'landmark', '武陵地区', '为推进武陵甚大裂隙综合治理项目而建立，最初仅驻天师与职工 113 人；灾难后其旧址成为北部禁区。'],
            ['北部禁区', 'region', '武陵地区', '位于武陵科考站旧址；塔罗斯历 142 年的灾难后被宏山科学院彻底封锁，由应龙特勤队长期驻守。'],
            ['应龙关', 'landmark', '武陵地区', '宏山科学院为应对武陵甚大裂隙爆发而建的大规模封锁设施群，位于天堑之上，初名「武陵关」。'],
            ['首墩', 'landmark', '武陵地区', '宏山科学院正式投入运行的第一根天师桩，也是武陵城巨兽心脏所在之处。'],
            ['藏剑谷', 'landmark', '武陵地区', '清波寨东南面的山谷；谷内秘库埋藏第一次天使战争前遗留的泰拉诸国人文典藏，曾被侵蚀掩埋。'],
            ['悬谷台', 'landmark', '藏剑谷', '藏剑谷内的平台，设由天师林瑚设计、天师府学院支持的虚拟仿真战斗平台「演武平台」。'],
            ['迷踪林', 'landmark', '武陵城', '武陵城周边由单一竹种构成的生态群落，实为竹林虫与竹子共生的、会整体移动的生态景观。'],
            ['方兴衢', 'landmark', '武陵城', '武陵城内的商业街。'],
            ['市民广场', 'landmark', '武陵城', '武陵城内的广场。'],
            ['景玉谷', 'region', '武陵地区', '武陵的生态实验区域，包含溪流与谷地，内部极少出现生硬的人造建筑。'],
            ['雪松林', 'region', '武陵地区', '以针叶林、积雪与绵延山体为主的高寒雪山区域，伴有极光。'],
            ['试验园区', 'region', '武陵地区', '武陵的实验区域，设有由陆令香修复的大型净水设备「净水节点」。'],
            ['宏山环形山', 'landmark', null, '被发现后赠予天师府，地理条件适宜建设多种大型研究设施及农业水培设施；宏山科学院由此得名并立足。'],

            ['萨米维格', 'region', '文明环带', '萨米部落群在塔卫二的主要居住地，全名意为「萨米之路」；也是这颗卫星上最北端的大规模人类定居地，与铁誓军要塞比邻。'],
            ['塞什卡', 'landmark', null, '萨卡兹语意为「歇脚地」：萨卡兹在塔卫二建立的家园与代表机构，以陨落巨兽牙齿转化成的腾空之锚为基础建于空中。'],
            ['塔卫二北极', 'region', null, '塔卫二已知唯一一座星门所在处；第一次天使战争最初 24 小时内北方的活动使人丧失了该地区，如今被极光笼罩。'],
            ['帝江号', 'landmark', null, '位于塔卫二静止轨道上的源石动力空天作业船，是终末地工业的总部，也是环带公约不可动摇立场的象征。'],
            ['大修道院', 'landmark', null, '寂语修会作为总部的一座移动大修道院。'],
            ['城市母亲', 'landmark', null, '联盟工团引以为豪的工业母舰；崭新的前进基地从其巨大船坞中驶出，第 58 届全联盟工团工人代表大会亦在其上举行。'],
            ['熔炉要塞', 'landmark', null, '铁誓军封锁北极圈的连绵堡垒后方，吞吐钢铁与重炮的兵工厂。'],
            ['遗产库', 'landmark', null, '铁誓军保存战争遗产的库房，其中可追溯至泰拉结晶时代之前。'],
            ['祖泉', 'landmark', '清波寨', '清波寨寨民对寨中一处天然水源的称呼，传闻能净化侵蚀；实为泉底一座炎国天师百余年前建造的水利装置。'],

            ['枢纽区', 'region', '四号谷地', '四号谷地内最开阔的区域，是公认观测气态巨行星塔罗斯的绝佳地点。'],
            ['谷地通道', 'region', '四号谷地', '连接四号谷地各区域的枢纽，沿途多狭长崖道。'],
            ['源石研究园', 'region', '四号谷地', '四号谷地内的区域。'],
            ['矿脉源区', 'region', '四号谷地', '四号谷地内的区域，分布着源石树废墟等复杂地形，深处有遗迹「世代摇篮」。'],
            ['阿伯莉采石场', 'landmark', '四号谷地', '塔罗斯历 75 年天使集群袭击四号谷地后，联盟工团为纪念牺牲的教师阿伯莉而命名的采石场；后因挖掘到神秘物质被下令停工。'],
            ['世代摇篮', 'landmark', '矿脉源区', '矿脉源区深处的源石奇观遗迹，即被引爆毁灭「星体」后的源石大树遗骸；工人们以此名感念它养育了四号谷地数代人。'],
            ['四号谷地缆车转运站', 'landmark', '枢纽区', '位于枢纽区北侧，连接枢纽区与供能高地，设仓储区、装卸平台、综合车场与架空索道。'],
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
            ['移动城市', 'concept', '为躲避周期性天灾而发展出的可整体迁移的城市形态；城市本身因此成为最重要的战略资产，「开走一座城」在泰拉是现实的政治手段。它基本都归相关国家所有（即便是汐斯塔这样的自由邦，理论上也仍属哥伦比亚），运作与维护远超个人或一般组织能调配的极限；翻山越河对它仍是艰难挑战，因此迁移遵循固定的循环路径、沿途预设停留场，以周期性运动规避天灾。位于核心地块的指挥塔既是全城的中枢控制系统，也是监测周边源石浓度的主要中心，迁移规划由它制订。各地形态差异极大：伊比利亚的城市底部装水栖装置、尚蜀往地块上垒山架索道、卡西米尔几座城市靠在一起成联合城、萨尔贡王酋用驮兽拖木头房车，哥伦比亚人则把移动采矿平台扩建成了小城 —— 虽然他们多数不愿承认。', '第二章 · 工业科技'],
            ['天灾', 'concept', '异常到人力无法反抗的自然灾害。按学界的现代定义，暴风、雪灾、强降雨、极端高温乃至陨石都算天灾，汐斯塔附近的高强度火山活动、矿脉附近反复出现的强烈地质活动这类由源石引发的次生自然活动也算。最新的研究表明其诞生原理与源石有直接联系，学界按成因与表现分为三类：源石自然灾害（最常见）、源石尘降（最早获得认定）、以及源于人为源石材料堆积的第三类。当然，对普通人来说，毁灭了自己家园的自然力量都是天灾。', '第一章 · 天灾'],
            ['矿石病', 'term', '又称「多发性源石感染综合征」，目前的观点认为这是由活性源石感染造成的不治之症 —— 这片大地上尚未发现过治愈并被确认的先例。主流解释是「活性源石论」：活性源石与正常细胞结合，一步步侵蚀并取代健康组织，造成不可逆的系统性病变（威尔逊教授的驮兽实验有力支持了这一理论，也由此推演出沿用至今的诊疗标准）。感染的关键在于源石必须处于活性化状态；途径包括天灾、矿物开采、工业污染、意外接触与感染者崩解 —— 单次小型天灾释放的活性源石总量就超过伦蒂尼姆一家小型源石工厂一年的排放。病程分早中晚三期：中期体表出现成片结晶，也是抑制手段介入的最后窗口；晚期感染者死亡时会立刻引发崩解，在数分钟到半小时内把大量源石微粒散布到周围。各国对感染者的政策差异极大：乌萨斯的境遇最为悲惨，维多利亚多实行隔离或收押，莱塔尼亚相对宽容但在居住上仍做分隔。', '第一章 · 矿石病'],
            ['源石技艺', 'term', '诞生于结晶时代之后维多利亚的通用术语：人类以一定方式把源石中的能量引导为特定形式、用于特定目的的方法。普通人更习惯叫它「法术」或「炼金术」，施术者称「术师」；近代统合各地施法传统之后，这些传统被一并称作源石技艺。在莱塔尼亚，它被系统化为一门以「声音」为核心载体的艺术与文化传统；在乌萨斯，则被纳入军用法术的编制。', '第一章 / 第二章'],
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

            /*
             * ---- 补录：第 1、2、3 章成体系解释过的机制、器物与生物 ----
             *
             * 这三章此前只被采了一小部分（结晶时代、移动城市、天灾、矿石病、源石技艺），
             * 而它们恰恰是全书写「泰拉怎么运转」最集中的地方：源石的四种形态与三种制品、
             * 源石技艺的七大分类、六类动力与武器、七种被驯化的生物。
             *
             * 释义仍是本仓库据相应章节**转写的概括**，不是原文摘录 —— 与上面那批同一纪律。
             */
            ['活性化', 'term', '能量密度极高的源石在特定条件下释放内部储存的大量能量、并逐渐分解为细小源石颗粒的过程；可观察为四个阶段：未活性化 → 开始活性化 → 逐渐分解 → 活性化结束。活性化中的源石相当危险：人体在缺乏防护的情况下与之近距离接触，有极大可能立即感染矿石病。', '第一章 · 源石'],
            ['源石的适应性', 'term', '源石中储存的能量能以不同形式释放（直接燃烧取热之外，还可转化为电能、化学能、辐射能等），这种能受人为影响而释放能量的性质称为适应性。', '第一章 · 源石'],
            ['自然增长', 'term', '天然环境中的源石有时会进行某种程度的自我复制，使自然界的源石总量缓慢增长。天灾过后，受灾区域的地表总会生长出源石晶簇，当地原有的矿脉也必然出现延伸的迹象。', '第一章 · 源石'],
            ['晶体外壳', 'object', '源石矿石外层结晶化的部分。因矿石杂质与地层环境不同而可能呈现许多色彩；它基本不能作为能源，但可加工为密质源石晶体，是重要的工业材料——越是复杂精密的机械，越需要高质量的晶体外壳。而只有在地下经历足够久年月的原矿才能形成密度大、强度高的外壳，因此这类原矿通常埋藏很深、开采难度极高。', '第一章 · 源石'],
            ['矿核 / 核矿', 'object', '剥除晶体外壳之后剩下的、散发着淡黄色光芒的高纯度矿物；矿核本身也可被视为矿物，称为「核矿」。矿核接触空气后会缓慢形成新的晶体外壳。源石工业成熟之前人们直接使用矿核；现代则送到精炼厂，其中一部分提取出来精炼后作为基础能源。', '第一章 · 源石'],
            ['源石碎片', 'object', '重度污染地区地表以晶簇形式生长的碎片状源石；易活性化、易收集，被哥伦比亚疾病防控中心毒性数据库列为重点危险物质，也是矿石病的主要感染源之一 —— 在荒野上被源石碎片划伤是极常见的感染原因。但它仍是应用广泛的工业原料，因此人们依旧大量开采。', '第一章 · 源石'],
            ['源石原矿', 'object', '自然界中大量存在的结构典型的源石矿物；埋藏越深，密度越高、价值也越高：地表与浅层矿脉出产的原矿外壳很薄且易碎，开采容易；深层矿脉的原矿密度高，晶体外壳能占矿石结构整体的四成以上，但必须依靠大型平台机械作业。', '第一章 · 源石'],
            ['固化源石结晶', 'object', '与源石原矿共生的矿物，呈纯黑光滑的不规则多面体；强度极高、结构极为稳定，非常难以加工，也几乎无法活性化 —— 因此它基本不会使人感染矿石病，真正的隐患在加工环节：面对这种过于坚硬的材料，铣刀与锯片崩断时有发生。', '第一章 · 源石'],
            ['科罗萨分布带', 'proper', '三条东西走向的源石主矿脉之一：自萨尔贡西北经玻利瓦尔、哥伦比亚北方大湖区与萨米南方，延伸至乌萨斯西北，以丰富的浅层储量为特点。', '第一章 · 源石'],
            ['塔尔干分布带', 'proper', '源石主矿脉之一：自维多利亚东部河湖上游的山地横穿雷姆必拓全境，其深层源石储量占全泰拉已探明总储量的百分之六十（仅雷姆必拓一国境内就占三成）。隐患也在于此 —— 许多矿体要么无法确定位置，要么埋藏在会阻碍开采的特殊地质结构中，贸然大规模开采势必造成严重的地质灾害与源石灾难。', '第一章 · 源石'],
            ['璟屿分布带', 'proper', '大地东方一片新月状的独立源石分布区，自炎国腹地两河流域的中央向西北延伸；它在东方诸国漫长的文明史中起到了举足轻重的作用。', '第一章 · 源石'],
            ['源石锭', 'object', '将源石矿核初步精炼后、再以压缩过的晶体外壳封装而成的半成品，可按需求以不同的密度标准制作；便于储存、运输与进一步加工，是最主要的流通用源石制品。', '第一章 · 源石'],
            ['至纯源石', 'object', '源石原矿经过提取、精炼与结晶化等多重工序后制得的精加工产物；既是重要的能源，也是优秀的法术材料，工业生产中用量极大。', '第一章 · 源石'],
            ['合成玉', 'object', '结晶化源石与其他矿物混合制得的制品；性质稳定，是优秀的工业材料，常用于制作电子元件，有时也被直接当作货币结算。', '第一章 · 源石'],
            ['源石技艺适应性', 'term', '人类个体与源石蕴藏能量之间的亲和度，有时简称「适应性」（与源石本身的适应性是两回事）。源石技艺人人都能用，但适应性决定了一个人所能触及的深度与广度、以及学习的快慢；它先天决定、无法靠后天努力改变，且个体差异非常随机 —— 萨尔贡有些地区的语言里，「天赋」与「源石技艺适应性」干脆是同一个词。适应性带有显著的种族差距（卡普里尼的平均水平明显高于其他种族），被许多社会学家视作社会不平等的根源之一。各地都习惯把适应性分档，基本可概括为高级、中级、低级：多数人为中级，低级者基本只能借助设备、甚至完全不能，高级天赋者极为少见。感染者是特例 —— 适应性会随体细胞与源石的融合率提高，还能把体内的源石当作施法材料，代价是健康状况恶化，但很多感染者为在恶劣环境中生存仍频繁施术。罗德岛内部另有一套五级划分：卓越、优良、标准、普通、缺陷。', '第一章 · 源石技艺'],
            ['源石技艺独特性', 'term', '源石技艺的另一项明显特征：每个人擅长使用何种源石技艺、擅长到何种程度，同样是先天确定的素质。各地不同派系的研究者都试图用自己的学说解释它，但迄今没有令人信服的结论；有传言说巫王对源石技艺的理解与理论阐释都达到了无人可比的程度，完美解释了与之相关的一切现象 —— 但他的成果从未公开发表，如今人已陨命，或许再没有机会得知。', '第一章 · 源石技艺'],
            ['莱塔尼亚古典法术理论', 'term', '由莱塔尼亚一代代高塔术师依长期积累的经验与理论建立起来的分类体系，把几乎所有源石技艺大致归入六大派系：咒法化形系、塑能转换系、塑形重构系、生理变化系、恢复疗愈系、传心感知系。它是许多术师入门阶段就学过的经典课程。顺带一提，「莱塔尼亚人只靠音乐施法」的印象并不准确 —— 他们对各种源石技艺体系都有深入研究。', '第一章 · 源石技艺'],
            ['咒法化形系', 'term', '莱塔尼亚古典法术理论的六大派系之一：用源石中蕴藏的能量引发物理现象，通常以某种物体为媒介 —— 对物体施力使其移动或改变状态、在空气中引发冲击波，都属于这一系。', '第一章 · 源石技艺'],
            ['塑能转换系', 'term', '古典法术理论的六大派系之一：将源石中的能量转换为其他形式后释放 —— 掌握这一系的术师能操纵电与火，或发射出破坏性巨大的高能光束。', '第一章 · 源石技艺'],
            ['塑形重构系', 'term', '古典法术理论的六大派系之一，被称作「重构现实的艺术」：让物体产生变化。最著名的一种即传说中的炼金术 —— 其原理是对矿物施法使其结构改变，最终分离出矿物中含有的金成分。', '第一章 · 源石技艺'],
            ['生理变化系', 'term', '古典法术理论的门类之一：专注于影响生物体的身体机能，可强化或弱化身体素质，也可强化人脑的运作。', '第一章 · 源石技艺'],
            ['恢复疗愈系', 'term', '古典法术理论的六大派系之一：让生物恢复健康。施放的主要困难在于，实际效力不仅取决于施术者的法术实力，还取决于他对受术者生理结构的理解程度 —— 这也是法术治疗目前基本只能处理外伤的原因。', '第一章 · 源石技艺'],
            ['传心感知系', 'term', '古典法术理论中最晦涩难懂的一系：接触、干扰并改变其他生物的思考与认知，最常见的是操纵野兽的法术；这门学科的边界至今无人能够触及。', '第一章 · 源石技艺'],
            ['萨卡兹巫术', 'term', '分支繁多、操作复杂的古代法术大类；其核心是某些特定萨卡兹由血脉遗传而获得的特殊源石技艺 —— 这与一般源石技艺「私人化、随机化」的特点截然不同，遗传原理至今不明。现代研究者看来，被研究过的萨卡兹巫术基本只是人们见得比较少的源石技艺；而人们对萨卡兹的偏见也波及了他们的技艺，长期以来的负面观感甚至妨碍了研究（有些地区的研究设施不允许萨卡兹进入），「巫术」这个带有歧视色彩的称呼也就一直沿用至今。', '第一章 · 源石技艺'],
            ['源石技艺统合理论', 'term', '几乎与「源石技艺」这个概念同时诞生的学说，在实用主义传统影响下成为最有影响力的理论。它提出的核心问题是：既然所有法术依赖的都是同一种资源，为什么不直接研究这种资源本身？理论强调源石技艺的一致性，反对门类与学科的细分，更关注利用源石能源的各种理论在不同场合下的整体可行性。它把原本限定于特殊体质人群的源石技艺普及到了几乎每一个人，更指向了对源石本身的研究 —— 预示了之后源石工业时代的到来。', '第一章 · 源石技艺'],
            ['现代工业法术', 'concept', '通过晶体单元与机械结构存储或传导固定的源石技艺，使用者无需亲自施术，即可实现能量的释放与转化。工业化生产重构了封装其中的源石、源石技艺与使用者之间的关系：原本作为生产手段的源石技艺，反而变成了生产的结果 —— 它不再需要紧密依附于施术者，成为能够被独立认识、独立运用的存在。', '第一章 · 源石技艺'],
            ['哥伦比亚的源石应用理论学派', 'concept', '主张在统合理论的基础上，用现代技术对源石技艺作更加系统化的分析与阐释；其理论处在高速自我迭代的状态中。用技术手段弥补人与人之间源石技艺适应性的巨大差异，是该学派在应用领域的终极目标之一。', '第一章 · 源石技艺'],
            ['源石自然灾害', 'term', '最常见的一类天灾：基本表现仍是自然活动（萨尔贡荒漠里破坏机械设备的雷沙暴、萨米冰原上裹挟巨型冰的极寒潮），源石只是加重了灾害的烈度；单次天灾中，暴风、雪灾、强降雨、极端高温等特征现象可能同时出现，也可能只出现几种，视当地环境而定。', '第一章 · 天灾'],
            ['源石尘降', 'term', '源石粒子在大气中累积过多、凝聚为大片活性源石云后的大规模坠落；坠落源石击中地面释放能量，凝聚出直径巨大的源石聚合体，直接造成毁灭性后果。它并非最常见的天灾形式，却构成了人们对天灾最普遍的印象，也是最早获得认定的一类。', '第一章 · 天灾'],
            ['天灾信使', 'term', '对天灾进行观察、总结与预警的古老职业：用双脚丈量大地，捕捉与源石相关的环境指标波动。学界现代意义上的天灾特征，正是依据历代天灾信使不辞劳苦的长期报告总结出来的。', '第一章 · 天灾信使'],
            ['源石技术', 'concept', '通过晶体单元与机械结构利用源石的方式。它与「源石技艺」的含义时有重合、时有分离，在已知的大部分人类历史里并不是一个明晰的概念。现代工业法术普及之后，适应性中等或较低的人也能参与工业生产，零部件因此实现标准化与可互换量产，流水线又抬高了生产规模的上限。局限仍在：一台设备的源石利用方式相当固定、单一 —— 一枚源石被制成特定的施术回路，用途就固定为传导某一种法术；特殊加工与设备充能维护仍要仰赖专业工程术师。', '第二章 · 源石技术'],
            ['源石外燃机', 'object', '最初的源石动力装置：不直接输出动能，而是用源石产生的热能在锅炉中制造高压水蒸气、推动活塞连杆往复运动。它的问世「鸣响了结晶时代的晨钟」—— 从起吊机、投料站到货运车、粉碎机，喷吐蒸汽的机械接管了工业生产的方方面面，蒸汽骑士也随之带来军事领域的变革。缺陷同样明显：笨重庞大、严重依赖施术者，巨量热能常常无法被充分转化、反而导致锅炉频繁过热，因此几乎没能在民用领域立足。', '第二章 · 源石技术'],
            ['源石内燃机', 'object', '对施术者不再有硬性要求、热能转化利用效率更高的动力装置，在百花齐放式的动力技术探索中脱颖而出，「鸣响了第二次变革的钟声」：七城联邦设计的第一座移动城市正是由数台大型内燃机提供动力；随着体积精简，民用载具也得到一定普及。此后以电动机为代表，更高效的源石能源转化模式相继问世。', '第二章 · 源石技术'],
            ['同质精炼技术', 'object', '以已精炼的高纯源石充当「酵母」、在特定催化条件下引发待精炼源石的晶体结构趋同纯化的精炼技术；产物纯度与充当「酵母」的源石一致，并同样能作为「酵母」用于后续流程。它是「手工精炼 → 规模效率」这一转向最具标志性的成果 —— 在此之前，手工精炼主要服务于精良施术单元与奢侈工艺品，远不能满足工业时代的需求。', '第二章 · 源石技术'],
            ['D32钢', 'object', '具有突破性意义的人造金属材料：既保留了 RMA70 矿物的源石技艺传导性，材料强度又超群。书中把它与异铁并列为现代冶金工业成熟后军工领域最常用的复杂合金之一。', '第二章 · 源石技术'],
            ['双极纳米片', 'object', '对源石活性高度敏感的元件：能把活性化的微小变化转为电信号，让人无需施术即可精准调用源石能量，已成为现代源石设备中不可或缺的重要元件。书中还记下一句工程术师的抱怨：「定期校准双极纳米片往往是源石设备充能站的工程术师最厌烦的工作，没有之一。」', '第二章 · 源石技术'],
            ['炽合金', 'object', '导热与导电性极佳、在低温与高温环境下都有良好强度的金属材料，是电子技术领域值得瞩目的材料新秀。雷神工业推出过一款号称在萨尔贡沙漠与萨米冰原都能正常开机的移动终端，从电池电容器到主板上的玻封二极管处处用到它 —— 不过书里补了一句：即使这样的终端，在沙漠和冰原里也收不到信号。', '第二章 · 源石技术'],
            ['地块', 'term', '移动城市的基本组成单元：地块互相拼接形成更大规模的移动城市。每座城市都有一个核心地块 —— 它的上层为指挥塔，下层设有主要源石动力炉。地块之间大多是机械结构的硬驳接（液压挂钩与锚定管线维持稳定并保持能源链接，另设通道以备万一）；每个地块在动力层底部都有接触地面的移动结构，最泛用也最常见的是巨型履带。因为很难保证每次都能按固定方位匹配，地块边缘设有通用的连接区域；在平地稀少的地方，甚至会出现边缘不完全对齐、彻底分离而仅靠索道连接的情况。', '第二章 · 工业科技'],
            ['生活层 / 支持层 / 动力层', 'term', '移动城市一般的上中下三层结构：上层为生活层，中层为支持层，下层为动力层。生活层与地面有相当距离，人员物资进出主要靠安装在地块边缘的升降电梯；其地表以下部分是为地表设施服务的支持系统（电缆与水管隧道，寒带城市还有暖气管线），因改造痕迹不会被抹净，常形成「建筑结构黏合」的地下迷宫。支持层宛如巨大的生产车间，能源内循环从这里起始，靠穿透生活层的管线把源石锅炉、电力与水循环供给上层。动力层是以履带连接机械传动装置、源石动力炉、源石贮藏与传送装置构成的机械王国，每个地块都有巨型源石动力炉；移动阶段不允许任何人居住。', '第二章 · 工业科技'],
            ['七城联邦', 'proper', '一些独立城市与地区在名义上组成的松散联合体，其中的「七」只是虚指；747—797 年间，它造出了第一座现代移动城市。', '第二章 · 第一座现代移动城市'],
            ['施术单元', 'object', '普通人施放源石技艺的必需品，在许多场合也会被视作武器。传统型最常见，主要提供施术所用的源石能源，并对特定类型的源石技艺有一定增幅；样式从双手法杖到特制手套均可，有的干脆把源石与金属熔铸在一起，成品既能施术也能当兵刃。现代工业法术带来了新的一类：内部储存固定形式的源石技艺，具备一定知识且受过训练的人都能激发，形式与威力相对有限但使用便利，因此各种武装力量经常装备这类新式快速施术单元。', '第二章 · 武器与装备'],
            ['铳械', 'object', '由拉特兰的萨科塔最先发现的、极为精密复杂的集成施术单元系统，用以发射蚀刻弹药 —— 源石回路同时存在于铳械与弹药上，击发时需要以源石技艺同时控制两者、并做一系列细微操作。如此高的使用难度换来极高的准确度、很远的射程与持续的火力，这些优点完全掩盖了它比传统弓弩威力稍小的缺点。许多势力都试图解析它，但近几十年研究基本停滞：制作门槛远超预期，不过过程中得到的成果大多用于其他武器的技术升级，还启发了新型施术单元的研究。黑钢国际在其创始人之一、拉特兰佣兵「桥夹」的帮助下仿制并简化了铳械（性能大幅缩水），并把它作为「少数合法拥有铳械的组织」这一优势来宣传。', '第二章 · 武器与装备'],
            ['蚀刻弹药', 'object', '铳械弹药的总称，由四部分组成：弹头（拉特兰生产的多数由源石晶体外壳打磨制成）、活性源石发射药（经特殊处理的源石蚀刻结晶圆柱体，为击发提供能量）、微型源石回路（又称「击发回路」，与铳械内部的施术单元部件组成完整的施术回路，是核心元件）、弹壳（普遍为制造工艺复杂的异铁合金）。依据铳械口径与种类的不同，弹药的种类也五花八门。', '第二章 · 武器与装备'],
            ['手炮', 'object', '伊比利亚利用对拉特兰铳械的解析成果开发：外表与操作方式都模仿铳械，但不发射蚀刻弹药，而是激发源石发射药产生冲击波杀伤敌人。它运用的源石技艺原理也与铳械不同 —— 实质上是一种形似铳械的新型特种施术单元。', '第二章 · 武器与装备'],
            ['能量武器', 'term', '目前最先进的武器种类：不借助源石技艺，而是利用某种能量直接毁伤，通常以电力驱动、以高能量密度产生杀伤力。最常见的是能够放电的近战兵刃，据说也有能发射能量束的远程武器存在；许多能量武器还处在实验阶段，但威力已不可小觑。', '第二章 · 武器与装备'],
            ['自动武器', 'term', '对能够利用某种自动机构完成搭箭、上弦与发射等步骤的弓类弹射武器的统称，通常由压缩气体或电力驱动。其中能自动完成整个发射过程的称为「全自动武器」，只能自动完成其中部分步骤的称为「半自动武器」。', '第二章 · 武器与装备'],
            ['源石火药 / 源石炸药', 'object', '以源石为基础材料制作的可燃烧、可爆炸的药剂，通常为粉末状，也有液体状或晶体状的成品；配方与制作方法各式各样，成品性能也因此各不相同。专门强化爆炸性能的一类称为「源石炸药」，人们基于它制作炸弹、地雷等不同种类的爆炸物，并设计了多种起爆方法。', '第二章 · 武器与装备'],
            ['闪光粉', 'object', '源石火药发明之前，人们用工业酮和糖制作的一种粉末：可以燃烧与爆炸，但能量密度很低、用途有限，在现代通常用作烟花爆竹与信号弹这类物件的原料。', '第二章 · 武器与装备'],
            ['源石虫', 'term', '对一类与源石形成稳定共生关系的中小型动物的统称：源石与其分泌物结合，构成介壳、棘刺、附肢等器官。刚孵化的幼体与成虫形象大相径庭 —— 光滑的身体上没有任何由源石构成的器官，它们靠生长过程中不断从环境摄取源石才逐步成形；体内源石含量相对其生态位异乎寻常地高，因此凡富含源石之处都能见到它们摄食：地表晶簇、地下矿洞、精炼工厂、源石动力炉，甚至废料堆里打滚。在 D32 钢这类高性能合成材料问世前，源石虫外壳是少有的兼具柔韧性与传导性的材料（哥伦比亚曾用它制作警用便携施术单元，民众一度戏称警察为「虫佬」，但因质地不均、输出功率不稳、误杀事件而退出历史）。「源石虫不会感染矿石病」的说法并不完全准确：高温、高压与高浓度活性源石环境下，它们身上的源石同样会活性化，甚至畸变。', '第三章 · 泰拉生物'],
            ['兽亲', 'concept', '兽主从本体分离而出的碎片，会令泰拉人下意识地产生亲切感；每个兽亲族群似乎都与一个陆上种族的概念相绑定。如今的统一命名源于历史而非基因 —— 帝高卢曾用堪称粗暴的翻译策略，把萨尔贡语里长达数十个字母的词直接译成「卡普里尼兽亲」。不同语言里这个词的感情色彩也不一样：高卢语强调与人类的亲近，维多利亚语强调它与「兽主」的亲近，萨卡兹语里则完全负面。泰拉人对兽亲并不总是亲切：叙拉古牧人会驱赶鲁珀兽亲，玻利瓦尔有佩洛兽亲被用于军事行动的「猎犬」传言，萨卡兹更不介意把兽亲送上烤架。布雷奥甘留下了一个费解的问题：黎博利兽亲的羽毛与黎博利本身相似得可疑，兽亲是否是一种特殊的生命形态？', '第三章 · 泰拉生物'],
            ['兽主', 'concept', '被迫离开动物身体、变成超域精魄的存在：几乎永生，能伪装，也能派兽亲与泰拉人打交道。叙拉古地区的诸多兽主被称作「狼之主」，它们不仅彼此争斗，有时还令当地人参与其中 —— 传说中的「獠牙」就是被兽主选出、代其参与争斗的傀儡。海中的情形不同：水生动物兽主或仍在蛰伏，或已堕入混沌，因此兽亲在海里不像在陆上那样常见。', '第三章 · 泰拉生物'],
            ['驮兽', 'term', '或许是最早被人类驯化的动物，泛指被驯化以提供畜力的动物；在移动城市拔地而起之前曾是文明的象征。它最初被驯化并不是为了运输，而是为了躲避天灾：许多动物能敏锐感知源石浓度变化、从而「预知」天灾，原始部族会在野生动物大规模迁徙时紧随其后，为了不掉队便骑上了驮兽 —— 那时人们并不需要控制方向，驮兽显然比人更清楚该往哪跑。被驯化的驮兽长着一双神似人类的眼晴，如今「驮兽的眼睛」成了生物保护事业的代表性形象。它们的形态随环境差异极大：北方身披长毛，热带雨林皮肤光滑易排汗，沙漠体表覆隔热鳞片，草原是能储泥浆水分的粗糙皮肤。鼻前的分叉扁角用来从土中刨出植物根茎，厚实的口腔皮肤使仙人掌也能入口；粪便在野外用于划领地，在人类社会则是极佳的肥料。「驮兽」也是个极宽泛的名称 —— 迅驮兽、恐驮兽、乃至雷姆必拓的高脚羽兽算不算驮兽，都是长久的辩题。', '第三章 · 泰拉生物'],
            ['瘤兽', 'term', '被驯化后提供乳与肉制品的动物，最突出的特征是身上一大一小两个瘤状器官：前胸的「瘤袋」与后背的「瘤峰」。二者由脂肪组织与腺体构成，既储存养分也产出乳汁 —— 野生的瘤兽能数月不进食而存活，靠的正是它们；哺乳期则把储存的养分转化为乳汁。产乳功能与性别无关、只与年龄有关：两性的瘤兽都需要储粮，因此都生有瘤袋瘤峰、也都能承担哺乳之责；步入老年后消化衰退，奶的产量与质量逐年下滑。其乳汁称为「瘤奶」，是食品工业最重要的原料之一（「没有瘤奶，就没有料理」），老年瘤兽带酸涩味的乳汁近年反成了乳品市场的噱头。硬胶质的尾巴是能痛击小型捕食者的尾锤，后颈金或银色的鬃毛可作装饰品，也颇受画笔制造商欢迎。', '第三章 · 泰拉生物'],
            ['沙地兽', 'term', '荒漠生物，背部隆起的是兼具散热与呼吸功能的「背扇」：它褶皱密布、皮肤薄而透光、内部毛细血管丰富，因此沙地兽把自己埋进沙里只露背扇时，仍能有效进行气体交换 —— 潜藏七八个小时也不会窒息，同时也借此躲避天敌。背扇形态随地带而变：戈壁地带棱角分明如砾石堆，沙漠地带呈弧形带波痕褶皱、看上去与沙丘无异，植被较多处则形似土堆，还会蹭上草叶。宽厚的蹄是掘地的绝佳工具，洞穴四通八达如地下迷宫。它们生性胆小却是优秀的捕食者，下颚的尖牙咬住猎物便再无逃脱余地；被感染的变异个体（身长两米、驮着瘤状源石赘生物、反常侵略）曾让萨尔贡王酋宣布所有沙地兽为害兽并悬赏剿杀，结果沙地兽几乎绝迹、失去天敌的小型植食动物反而泛滥成灾。养殖它多以破产告终：铺沙土会钻地逃跑，铺砖石则肉质极差。', '第三章 · 泰拉生物'],
            ['裂兽', 'term', '凶猛的肉食动物，在许多人心目中代表着恐惧。爪牙长而锋利、内侧生有细小倒钩，能在猎物身上撕出流血不止的伤口；颈腹毛发中藏的硬质「裂毛」边缘极其锋锐，能轻易划开大多数动物的口腔 —— 哪怕它被掀翻、露出柔软的颈腹，对手也无从下口。裂兽群居，一个完整的群体由数十只组成、以一头雌性为头领（「族母」），族母之位靠武力决出，食物不足时权力斗争尤为频繁。它还表现出顺序性的雌雄同体：出生时全是雌性，活过十岁以上才变为雄性、获得交配机会，交配后很快死去 —— 因此雄性难得一见，许多人误以为裂兽只有一种性别。极北生活着体型数倍于普通裂兽的亚种「大型裂兽」，曾搭载弩炮台作为乌萨斯阵线的核心、对抗萨米人的岩角兽，如今这一职能已被装甲部队全面取代。', '第三章 · 泰拉生物'],
            ['钳兽', 'term', '生活在河流、湖泊与沼泽等淡水环境中的甲壳动物，以巨大的螯得名：即使小体型个体的螯也有人的小臂长，成年钳兽能轻易夹碎驮兽等中大型动物的骨骼。代价是螯的活动范围相当有限，不能像磐蟹那样高高举起 —— 应对大型猎物时它靠强有力的后足一跃而起、用锋利的足尖把自己固定在猎物身上，再施展巨螯；那足尖能刺破的不只是皮肤，还有质地较脆的岩石，洞窟中的钳兽就悬在洞壁上伺机而动。卡西米尔的人工饲养钳兽常出现在骑士竞技场上，被主办方术师操控着袭击该淘汰的骑士、又在该晋级的骑士面前木讷呆滞，成了屠杀表演的牺牲品。腹部最柔软也最有食用价值，甲壳保护着它；沼泽钳兽的甲壳更薄更软，但会裹满泥浆、板结出可靠的外壳，若泥里裹了种子，植物还会在它背上生根发芽。', '第三章 · 泰拉生物'],
            ['云兽', 'term', '常见的小型城市共生动物，食性极杂 —— 从动物、植物到城市里最常见的厨余废料无所不食（不过专门培育的宠物品种消化系统有缺陷，稍不注意就腹泻，一旦被遗弃很难存活）。它柔软的长耳赋予极佳听力：小巷里那串渐渐远去的清脆蹄声，正是云兽先听见了来人的脚步；尾巴短小不能大幅摇摆，因此主要靠呼扇长耳表达情绪，许多养它的人说抚摸云兽耳朵是「大地上最幸福的事情」。云兽群居并有固定地盘，只有势力最大的群体才能占据商业区这样食物充沛的地盘，敌对的家族常在街头以顶角决出胜负，近年甚至出现了为不同家族大打出手的城市粉丝。炎国人依其毛色赋予雅称 —— 乌云兽（纯黑）、雪云兽（纯白）、乌云盖雪（黑背白腹）、墨玉垂珠（黑身白尾）……还专门辑有《相云经》。', '第三章 · 泰拉生物'],
            ['循兽', 'term', '被驯化的小型动物，嗅觉与胡须极佳，用于搜救、排爆与追踪等特种任务，一直是各国军警力量的重要组成部分。它的野生先祖是穴居生物，因此被驯化的循兽眼睛小、视力差，行动时高度依赖胡须感知环境、避开障碍；同样保留下来的是适于刨土的长爪 —— 在人类社会里它不再需要刨土，但爪仍不断生长，饲养时抓板必不可少。没有紧急任务时，警用循兽的主要职责是坚守城市关口、辨别试图走私的含源石致幻药物；因体型矮小、抬起身也够不到旅客背包，一些城市的关口专门设了供它站立的「循兽台」。它的鼻子与胡须灵敏而脆弱，各国警方因此常采取轮班制（服役三个月、休假三个月），让它以最佳状态上岗。', '第三章 · 泰拉生物'],
            ['鼷兽', 'term', '原产于萨尔贡的小型穴居动物，通常藏在货车夹层里随通商队伍散布到各国，很快适应城市生活，成为令人头痛的入侵物种。它以坚果与植物块茎为食，进了城最爱土豆 —— 常成群潜入快餐店把薯条洗劫一空，曾有店家把薯条统统做成麻辣与芥末口味，结果它们照单全收；磨牙的习性更让人困扰：小到充电线、大到运输管道无所不咬，涂苦味涂料也没能奏效。它在荒漠中的天敌是沙地兽，但沙地兽难以驯化也不适应城市生活，用它来治鼷兽灾害的想法很快落空。头顶与前肢生有形似羽毛的结构：头顶的那束像循兽的胡须，能感知空气的细微振动；前肢的可张开，跳跃时产生微小浮力、短暂滑翔 —— 配合它在洞口挖出的滑道，冲出洞穴时能飞出相当远的距离，以此逃过捕食者。', '第三章 · 泰拉生物'],
            ['《相云经》', 'proper', '炎国人依云兽毛色之别整理出的雅称著作 —— 乌云兽（纯黑）、雪云兽（纯白）、乌云盖雪（黑背白腹）、墨玉垂珠（黑身白尾）等皆出于此。在炎国说唱曲艺中，云兽雅称是经典的贯口段子：一气呵成地念出所有雅称，是曲艺演员的基本功。', '第三章 · 泰拉生物'],
            ['阿斯兰征服', 'concept', '阿斯兰部落持续数百年的征战。它彻底更新了当时人的自我认知，并催生了现代的种族概念。', '第四章 · 泰拉种族'],
            ['狼之主 / 獠牙', 'concept', '叙拉古地区活跃的兽主被称作「狼之主」；它选出「獠牙」，作为代其参与争斗的傀儡。', '第四章 · 泰拉种族'],
            ['雷法', 'concept', '炎国一支术师学派专精的招引雷电的源石技艺，源出麒麟术师，后经天师府广为传授。', '第一章 · 源石技艺 / 第四章'],
            ['大静谧', 'concept', '泰拉历 1038 年自海洋降临伊比利亚的灾祸：令灯塔失去光芒、宫殿沉入汪洋，也改写了整个伊比利亚的秩序。', '伊比利亚卷'],
            ['雪祀', 'term', '萨米部族的核心宗教领袖，也是最强大的萨满与术师；掌管驱动族树的咒语，最重要的职责是占卜。', '萨米卷'],
            ['主树 / 族树', 'object', '萨米部落落脚地所围绕的巨大树木，其中最大、最重要的称为「主树」；它可在雪祀的操控下行走、扎根并搬运重物。', '萨米卷'],
            ['百氏之乱', 'concept', '炎氏离世之后、诸侯重新开始争夺土地的百年乱世；炎国历史学家赋予这个时代的称呼。', '炎国卷'],
            ['天师', 'term', '炎国兼具源石技艺专家与学术研究者身份、备受尊敬者。「天」意味着遵奉天命，「师」则意为导师与引领者。', '炎国卷'],
            ['灾巡', 'term', '东国依《平祸令》规定、只能由东皇指定的神社培养的天灾预警者。', '极东来信'],
            ['乌卡纷争', 'concept', '绵延近百年的乌萨斯—卡西米尔纷争，终于 1062 年的第十次乌卡战争。', '附录 · 泰拉纪年'],
            ['石棺', 'object', '切尔诺伯格内用于封存「博士」的装置：1094 年「博士」被放入，1096 年第二次苏醒。', '附录 · 泰拉纪年'],
            ['过去与未来之王', 'proper', '萨尔贡皇帝路加萨尔古斯的自封称号：他发现「掌控时间」的圣物后自封此名，泰拉纪年即以该年为元年。', '附录 · 历法与纪年'],

            /*
             * ---- 补录：两个世界共有的概念 ----
             *
             * 世界写 **null**（不是 'terra'）：源石在两个世界都成立，只写一边会让另一边的
             * 读者以为那里没有这回事。`seedTerms()` 用 array_key_exists 判断第 5 位，
             * 因此这里的 null 会被如实写成「通用」，而不是回落到泰拉。
             */
            ['源石', 'concept', '泰拉与塔卫二共有的一种蕴含能量的结晶矿物，有数种不同形态，是两地最重要的天然能源与工业原料。'
                .'泰拉共有三枚「最初的源石」，其中一枚被带到塔卫二 —— 这颗卫星上的所有源石都来源于它。', '第一章 · 源石 / 中枢档案 · 源石', null],

            /*
             * ---- 补录：塔卫二（《明日方舟：终末地》）----
             *
             * 出处分两档，可靠性不同，这里如实写进 `origin` 让读者可回查：
             *
             *  1. **《中枢档案》**（`www.fz.wiki/wiki/PRTS记录/中枢档案/<名称>`）——
             *     游戏内的档案原文，属官方设定文本，是本批词条的主要依据；
             *  2. **《环带纪略》**（`www.fz.wiki/wiki/环带纪略/*`）—— 该 Wiki 用户共建的考据项目，
             *     只用于补第一档没写到的条目（塔罗斯历、帝江号、管理员）。
             *
             * 释义仍是转写的概括而非原文摘录 —— 与泰拉那批同一纪律。
             */
            ['塔卫二', 'proper', '气态巨行星「塔罗斯」的天然卫星之一，也是这对行星系统中唯一宜居的星球；塔罗斯轨道内外还分布着数量未知的其他小卫星。', '中枢档案 · 塔卫二', 'talos'],
            ['塔罗斯', 'proper', '塔卫二所属行星系统的主星，一颗气态巨行星；枢纽区是四号谷地内公认观测它的绝佳地点。', '中枢档案 · 塔卫二', 'talos'],
            ['塔罗斯历', 'term', '塔卫二通用的纪年体系：元年为星门重启、泰拉人抵达塔卫二之年。', '环带纪略 · 塔卫二年表', 'talos'],
            ['《环带公约》', 'concept', '塔罗斯历 17 年签署的公约，文明环带随之成立。因公约的限制，它不接受自我宣称为「国家」的实体加入。', '中枢档案 · 文明环带', 'talos'],
            ['环带居民', 'term', '具有文明环带所认可的身份、居住在环带各阵营与团体组织管治处所中的人类，占塔卫二总人口的九成以上。', '中枢档案 · 文明环带', 'talos'],
            ['U3', 'term', '「《环带公约》中享有不可动摇的立场的成员」的简称，指联盟工团、环塔商会与宏山科学院三者，亦称「三大阵营」「三巨头」。', '中枢档案 · 文明环带', 'talos'],
            ['超域（Æther）', 'concept', '对塔卫二星球内部、表面、大气及外层空间中所有深度读数为 1 的时空的统称。现实空间与超域存在重叠，物质与能量在二者之间的流动已被证实为可能。', '中枢档案 · 超域', 'talos'],
            ['裂隙', 'concept', '重叠的现实空间与超域之间的阻隔发生破溃后产生的空间通道（罕见情况下时间尺度也会被打乱）；其周围深度读数通常接近 0.5，因此成为动态侵蚀的高发地点。', '中枢档案 · 超域', 'talos'],
            ['深度读数', 'term', '衡量某处时空与超域重叠程度的数值，用于判定侵蚀的发生概率：动态侵蚀发生于深度读数为正值的时空，概率随读数由 0 趋近 1 构成钟形曲线。', '中枢档案 · 侵蚀', 'talos'],
            ['亚空间', 'concept', '隔开超域与现实空间的「隔膜」；星门内部曾通向某种「亚空间」，现已确定它并非超域。', '中枢档案 · 超域', 'talos'],
            ['侵蚀', 'concept', '对塔卫二星球内部、表面、大气及外层空间中存在的一系列成型有害现象的统称，大体分为动态侵蚀与静态侵蚀两类。', '中枢档案 · 侵蚀', 'talos'],
            ['动态侵蚀', 'concept', '呈现诡异色彩外表的侵蚀：能以完全不定的速率，对接触或吞没的所有物体造成解离性破坏与／或反演性破坏 —— 即从时空中剥夺物质与／或时间。', '中枢档案 · 侵蚀', 'talos'],
            ['静态侵蚀', 'concept', '一般被视为动态侵蚀消退后的遗迹，常表现为附着在破坏残余物表面的沥青状流质。', '中枢档案 · 侵蚀', 'talos'],
            ['液态侵蚀', 'concept', '地下水体被裂隙转化而成的侵蚀形态；武陵地区的侵蚀以液态为主。', '中枢档案 · 武陵甚大裂隙', 'talos'],
            ['侵蚀潮', 'concept', '武陵甚大裂隙影响下，被转化为液态侵蚀的地下水体涌上地表所造成的灾害。', '中枢档案 · 武陵甚大裂隙', 'talos'],
            ['天然稳定裂隙', 'term', '较长时间内未观察到扩张变化、不易引发侵蚀的裂隙。四号谷地的供能高地拥有一条，超域试验场因此搭建在其附近。', '中枢档案 · 超域试验场', 'talos'],
            ['源石树', 'object', '四号谷地开拓初期由管理员在矿脉源区种下的源石技艺产物；人们截取大树的枝叶，建成了第一座发电站与第一座采石场。', '中枢档案 · 世代摇篮', 'talos'],
            ['协议源石', 'object', '终末地工业的核心技术成果之一：借助由协议源石搭建的传送网络，可将人员与物资在物理距离相隔甚远的两点之间瞬时送达。', '中枢档案 · 协议源石', 'talos'],
            ['协议传送', 'term', '基于协议源石传送网络的瞬时人员物资投送能力；单次可承载的人员与物资量高度受限，且需调用高度危险的超域能量。', '中枢档案 · 协议源石', 'talos'],
            ['集成工业系统', 'concept', '终末地工业掌握的关键核心技术之一（Automated Industry Complex）：实现生产设备的小型化与模块化，现场部署少量人力即可在极短时间内搭建功能完整的自动化生产线。', '中枢档案 · 集成工业系统', 'talos'],
            ['息壤', 'object', '用于遏制侵蚀的新材料，宏山科学院在武陵科学发展区的主要科研成果之一；活性化的息壤能消耗自身，把液态侵蚀逐步中和为自然水体。', '中枢档案 · 息壤', 'talos'],
            ['天师桩', 'object', '用于遏制侵蚀潮的立柱式息壤装置：阵列持续向大气与水体散布息壤粒子，侵蚀潮袭来时粒子活性化，形成削弱侵蚀势能的边界。', '中枢档案 · 天师桩', 'talos'],
            ['枢壤仪', 'object', '宏山科学院为应对武陵甚大裂隙而研制的实验型源石设备：借瞬间释放的息壤能量与超域能量相互作用，达到闭合裂隙的效果。', '中枢档案 · 枢壤仪', 'talos'],
            ['天有洪炉', 'object', '武陵工业计划中由终末地工业与武陵合作研发、可生产息壤的设备。', '地区 · 武陵', 'talos'],
            ['锚点', 'object', '催化形成天使的装置；「能形成哪几种模式的天使」可视为某个锚点的固有属性。', '中枢档案 · 天使', 'talos'],
            ['形成模式', 'term', '一个天使个体的外表、移动方法与攻击手段的总和；同一锚点催化形成的多个个体中，往往只存在几类形成模式。', '中枢档案 · 天使', 'talos'],
            ['《天使分类编码标准》', 'proper', '自第一次天使战争以来，人们不断观测到新的天使形成模式；为便于快速查询弱点与应对方法，这份标准随之诞生。', '中枢档案 · 天使', 'talos'],
            ['基石', 'proper', '掌握了先史文明遗产的特殊个体，其存在本身就是遗产的一部分；它是锚点与天使的创造者，其全面袭击引发了第一次天使战争。', '中枢档案 · 基石', 'talos'],
            ['第一次天使战争', 'concept', '塔罗斯历 5 年至 15 年间，人类与以天使为主的敌对势力之间的战争；塔罗斯历 142 年之前的文献称其为「天使战争」。', '中枢档案 · 第一次天使战争', 'talos'],
            ['塔卫二的极光', 'concept', '第一次天使战争最初一小时里在北方出现的「极光」，由类似「星荚」阻隔层的底部结构与延伸至外层空间的超域能量浪潮两部分组成，被称为「高悬在这颗卫星头顶的末日时钟」。', '中枢档案 · 塔卫二的极光', 'talos'],
            ['阻隔层（星荚）', 'object', '塔卫二极光底部的结构，能量特征与泰拉人熟知的「星荚」类似；所幸这道阻隔层并未包裹整颗星球，只覆盖以北极点为中心的地域及其他小块区域。', '中枢档案 · 塔卫二的极光', 'talos'],
            ['星门', 'object', '人类对一种运作原理尚未被完全理解的前文明交通设施的称呼；在泰拉主要大陆的南北两端各有一座，塔卫二已知的唯一一座位于星球北极。', '中枢档案 · 星门', 'talos'],
            ['蚀影', 'concept', '文明环带超域研究领域中被视为极为罕见的超域活动现象：表现为具备自主活动能力、存在强烈侵略性的「超域能量复现实体」。', '中枢档案 · 蚀影', 'talos'],
            ['再旅者', 'proper', '终末地工业人事档案中一批特殊干员罕为人知的身份：由被污染的「最初的源石」内化宇宙中析出的信息碎片形成，能认识到自己是独立的人。', '中枢档案 · 再旅者', 'talos'],
            ['零号委托', 'proper', '使管理员苏醒的那道委托。档案正文只有：「进度确认……权限确认……苏醒。接纳。」', '中枢档案 · 零号委托', 'talos'],
            ['管理员', 'proper', '终末地工业的领导者；帝江号指挥塔设有「管理员相关功能」。四号谷地事件期间，各方依《环带公约》恢复其优先通行权与现场处置权。', '环带纪略 · 帝江号考略', 'talos'],
            ['巨兽心脏 / 壤心玉', 'object', '宏科院在巨兽学应用技术领域的最高技术成果之一，技术渊源可上溯至泰拉时期，后又深度融合了协议源石技术；壤心玉是巨兽心脏的核心能源，将两块壤心玉精确结合便可启动。', '中枢档案 · 岁兽 / 首墩', 'talos'],
            ['巢雕', 'object', '碾骨氏族的标志性战地设施：能吸收侵蚀物质并以雾状散播，范围内一切基于源石技术的设备都会受到明显干扰。', '中枢档案 · 巢雕', 'talos'],
            ['雾火', 'object', '一种被称作「雾火」的火焰，似乎与侵蚀有关：除致幻性与神经毒性外，还能在一定时间内大大强化摄入者的身体机能；是碾骨氏族崛起的关键。', '调查报告 · 有关碾骨氏族', 'talos'],
            ['烙铁指令', 'object', '联盟工团在极端战况下用以调集军事资源的特殊机制：由发信器与信标装置组成，需两枚权限钥匙卡一同使用，收信方默认发信源已遭受毁灭性军事打击。', '中枢档案 · 烙铁指令', 'talos'],
            ['源石的可控分布技术', 'term', '使人们得以在一定程度上控制源石晶簇生长的技术：把它们「培植」在特定区域，从而限制天灾在塔卫二爆发的可能性。', '中枢档案 · 源石在塔卫二', 'talos'],
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
