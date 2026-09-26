<?php

namespace App\Support;

use App\Models\Faction;
use App\Models\Place;

/**
 * 地名与组织徽记的接入：docs/emblems.json → places.logo / factions.logo。
 *
 * 清单由 `bin/fetch-emblems.py`（工作区脚本）生成，记录 PRTS wiki「泰拉大典」专名空间
 * 里地理 / 组织条目的徽记。与人物头像（`App\Support\CharacterAvatars`）同款做法，
 * 两处**刻意的差别**见类末「与头像的差别」。
 *
 * 关联规则是**按名字精确匹配**，绝不加模糊推断（别名、转写、去后缀）：
 * 「维多利亚」的地名行与政体行说的是同一个维多利亚，两行都该有徽记，按名字各查一次
 * 自然落到两边；而如果允许「差不多」的匹配，只会把徽记挂到错误的实体上 ——
 * 那比没有徽记糟糕得多。匹配不上的名字必须点名上报，不能静默跳过。
 *
 * 匹配是**两步**：先按别名表解析，再拿原名兜底。两步都要，因为同一个名字在两表里
 * 不一定同名 ——「塔拉」在地名表叫「塔拉王国」，在阵营表就叫「塔拉」；反过来
 * 「罗德岛-精英干员」是维基给徽记起的合成名，库里的组织叫「精英干员」。
 *
 * 与头像的差别（都是有意的）
 * -------------------------
 *  1. **清单入库。** 头像清单在仓库外（内含抓取机的本地绝对路径），后果是新克隆的
 *     仓库 seed 时关联不到头像。徽记清单的 `file` 是相对 `public/` 的路径，与本列
 *     存的值同形、不含任何本机路径，因此随仓库入库，任何机器上都能关联。
 *  2. **不按世界分目录。** 头像按 terra / talos 分，因为两个来源各给一套名单。
 *     徽记只有 47 枚、单一来源，且同一枚常同时属于同名的一行地名与一行政体
 *     （维多利亚），分目录反而要把同一张图放两处。落盘一律 `assets/emblems/`。
 */
class EntityEmblems
{
    /**
     * 徽记名 → 库内名。只收**已经逐条裁决过的**转写，不做任何推断。
     *
     * 裁决依据（每条都能在 PRTS 泰拉大典里对回原条目）：
     *  - 炎 / 乌萨斯 / 东：维基用简称，库里的地名与政体用全称；
     *  - 炎-龙门：维基把「炎国 · 龙门」拼成一个势力名，库内是独立的地名「龙门」；
     *  - 塔拉：「塔拉王国」是地名，阵营表里另有简写的「塔拉」，由兜底那一步接住；
     *  - 罗德岛-精英干员：维基的合成名，库内组织就叫「精英干员」。
     */
    private const ALIASES = [
        '炎' => '炎国',
        '乌萨斯' => '乌萨斯帝国',
        '东' => '东国',
        '炎-龙门' => '龙门',
        '塔拉' => '塔拉王国',
        '罗德岛-精英干员' => '精英干员',
    ];

    /**
     * 执行关联。
     *
     * @param  string  $manifestPath  清单绝对路径（docs/emblems.json）；缺失或非法 JSON 时返回 null
     * @param  string  $publicPath    public/ 的绝对路径，用于确认文件真的在
     * @return array{places: int, factions: int, missing_file: int, unknown: list<string>}|null
     */
    public static function associate(string $manifestPath, string $publicPath): ?array
    {
        if (! is_file($manifestPath)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            return null;
        }

        // 一次取回全表并建索引：几十条逐条 first() 就是几十次查询，而这些表都很小
        $places = Place::query()->get()->keyBy('name');
        $factions = Faction::query()->get()->keyBy('name');

        $linkedPlaces = 0;
        $linkedFactions = 0;
        $missingFile = 0;
        $unknown = [];

        foreach ($manifest['prts']['items'] ?? [] as $item) {
            $label = trim((string) ($item['name'] ?? ''));
            $relative = trim((string) ($item['file'] ?? ''));

            if ($label === '' || $relative === '') {
                continue;
            }

            // 先按别名解析，落不中再拿原名兜底（理由见类注释「两步」）
            $resolved = self::ALIASES[$label] ?? $label;

            $place = $places->get($resolved) ?? $places->get($label);
            $faction = $factions->get($resolved) ?? $factions->get($label);

            if ($place === null && $faction === null) {
                // 清单里有、库里没有对应实体：必须点名 —— 静默跳过会让「少了一枚徽记」
                // 看起来像「本来就没有」。当前 47 枚全部落地，所以这个分支平时是空的；
                // 一旦有输出，说明清单与字典开始脱节，该重新对一遍。
                $unknown[] = $label;

                continue;
            }

            // 文件必须真的在：清单在、图不在时写入路径只会产出 404 碎图，
            // 不如不写 —— 页面退回纯文字，实体本身仍然完整。
            if (! is_file(rtrim($publicPath, '/').'/'.$relative)) {
                $missingFile++;

                continue;
            }

            if ($place !== null) {
                // 值没变就不写：seed 可以反复跑，不该每次都产生一堆无谓的 UPDATE
                if ($place->logo !== $relative) {
                    $place->fill(['logo' => $relative])->save();
                }

                $linkedPlaces++;
            }

            if ($faction !== null) {
                if ($faction->logo !== $relative) {
                    $faction->fill(['logo' => $relative])->save();
                }

                $linkedFactions++;
            }
        }

        return [
            'places' => $linkedPlaces,
            'factions' => $linkedFactions,
            'missing_file' => $missingFile,
            'unknown' => $unknown,
        ];
    }
}
