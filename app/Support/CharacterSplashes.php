<?php

namespace App\Support;

use App\Enums\World;
use App\Models\Character;

/**
 * 干员立绘的接入：docs/splashes.json → characters.splashes。
 *
 * 清单由 `bin/fetch-splashes.py`（工作区脚本）生成，记录 PRTS wiki 上
 * `立绘_<干员>_<变体>.png` 这一族图片经压缩后的本地文件。
 *
 * 关联规则与头像完全一致 —— **只按「世界 + 人物名」精确匹配**，不做任何模糊推断
 * （别名、转写、去后缀）。理由在 `CharacterAvatars` 里已经写过一遍，这里不再重复：
 * 把立绘挂到错误的人身上，比没有立绘糟糕得多。清单里有、库里没有的名字必须点名上报。
 *
 * 与头像的唯一差别：**一个人可以有多张**。同一名干员有精英一与精英二两套立绘，
 * 它们是同一个人的两种状态，不是两个人，因此按变体号一并写进 `splashes` 这一列
 * （JSON），而不是拆成两个人或只留一张。
 *
 * 塔卫二没有对应来源：fz.wiki 的干员条目只有文字与图标，整页唯一的 `<img>`
 * 是站点 logo，零处出现「立绘」二字。因此塔卫二的人员一律留空 ——
 * 这是来源的边界，不是采集的遗漏。
 */
class CharacterSplashes
{
    /** 清单段名 → 人物所属世界。 */
    private const SECTIONS = [
        'prts' => World::Terra,
    ];

    /**
     * 清单名 → 库名。
     *
     * 立绘文件名与库内名同出一源（PRTS 的干员名单），因此预期这张表是空的；
     * 保留它的理由是**给未来的差异一个显式的落点**：一旦 seed 报出「清单之外」，
     * 正确的处理是把裁决写在这里，而不是加一层模糊匹配。
     */
    private const ALIASES = [];

    /**
     * 执行关联。
     *
     * @param  string  $manifestPath  清单绝对路径（docs/splashes.json）；缺失或非法 JSON 时返回 null
     * @param  string  $publicPath    public/ 的绝对路径，用于确认文件真的在
     * @return array{characters: int, images: int, missing_file: int, unknown: list<string>}|null
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

        // 一次取回全部人物，按「世界|名字」建索引：清单近九百条逐条 first() 就是几百次查询。
        // 世界走模型的 world() 解析而不是裸列值 —— 库里的历史行与未指定世界的行
        // 约定俗成落在泰拉（Character::world() 的既定语义），裸查会漏掉它们
        $characters = Character::query()->get()
            ->keyBy(fn (Character $character) => $character->world()->value.'|'.$character->name);

        $accumulated = [];   // 人物 id => [变体号 => 相对路径]
        $holders = [];       // 人物 id => 模型
        $missingFile = 0;
        $unknown = [];       // 键用来去重：一个人有精英一、精英二两张，不该报两遍

        foreach (self::SECTIONS as $section => $world) {
            foreach ($manifest[$section]['items'] ?? [] as $item) {
                $name = trim((string) ($item['name'] ?? ''));
                $variant = trim((string) ($item['variant'] ?? ''));
                $relative = trim((string) ($item['file'] ?? ''));

                if ($name === '' || $variant === '' || $relative === '') {
                    continue;
                }

                // 变体号要认得出来：认不出的键会写进库里、却没有任何展示名可用
                if (! self::isKnownVariant($variant)) {
                    $unknown[$name.'#'.$variant] = $section.':'.$name.'（变体 '.$variant.' 无法识别）';

                    continue;
                }

                $resolved = self::ALIASES[$name] ?? $name;

                /** @var Character|null $character */
                $character = $characters->get($world->value.'|'.$resolved)
                    ?? $characters->get($world->value.'|'.$name);

                if ($character === null) {
                    // 清单里有、库里没有这个人：必须点名 —— 静默跳过会让「少了一张立绘」
                    // 看起来像「本来就没有」。当前 33 个，逐个都能解释：来源名单比本仓库
                    // 的人物名单大（未实装的 F91、卫戍协议模式里的单位、本仓库尚未收录的
                    // 精英干员如 Mechanist / Misery / Pith / Sharp）。一旦这个数字成倍
                    // 增长，说明清单与字典开始脱节，该重新对一遍。
                    $unknown[$name] = $section.':'.$name;

                    continue;
                }

                // 文件必须真的在：清单在、图不在时写入路径只会产出 404 碎图，
                // 不如不写 —— 页面整块不渲染，人物本身仍然完整
                if (! is_file(rtrim($publicPath, '/').'/'.$relative)) {
                    $missingFile++;

                    continue;
                }

                $accumulated[$character->id][$variant] = $relative;
                $holders[$character->id] = $character;
            }
        }

        $linked = 0;
        $images = 0;

        foreach ($accumulated as $id => $map) {
            $character = $holders[$id];
            $map = self::canonical($map);

            // 值没变就不写：seed 可以反复跑，不该每次都产生一堆无谓的 UPDATE
            if (self::canonical($character->splashes ?? []) !== $map) {
                $character->fill(['splashes' => $map])->save();
            }

            $linked++;
            $images += count($map);
        }

        return [
            'characters' => $linked,
            'images' => $images,
            'missing_file' => $missingFile,
            // 排序后再给出去：跑种子的人看到的应当是一份稳定的名单，
            // 而不是随清单顺序变化的输出（否则「这次和上次不一样」就没有意义）
            'unknown' => array_values(array_map(
                fn (array $pair) => $pair[1],
                (function () use ($unknown) {
                    ksort($unknown);

                    return array_map(null, array_keys($unknown), array_values($unknown));
                })(),
            )),
        ];
    }

    /** 变体号是否认得出来（精英一 / 精英二 / 时装N）。 */
    private static function isKnownVariant(string $variant): bool
    {
        return isset(Character::SPLASH_VARIANTS[$variant])
            || preg_match('/^skin\d+$/', $variant) === 1;
    }

    /**
     * 规范化：按键排序，保证同样的内容得到同样的数组。
     *
     * 排序不是为了好看 —— `splashes` 是 JSON 列，键序不同的等价数组在
     * 严格比较下不相等，会让「已是最新就不写」的判断失效、每次 seed 都产生 UPDATE。
     * PHP 数组会把数字字符串键转成整数，这里也一并对齐。
     */
    private static function canonical(array $map): array
    {
        $out = [];

        foreach ($map as $key => $value) {
            $out[(string) $key] = $value;
        }

        uksort($out, fn (string $a, string $b) => Character::splashVariantSortKey($a)
            <=> Character::splashVariantSortKey($b));

        return $out;
    }
}
