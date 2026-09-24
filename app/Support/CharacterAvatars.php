<?php

namespace App\Support;

use App\Enums\World;
use App\Models\Character;

/**
 * 人物头像的接入：docs/manifest.json（抓取清单）→ characters.avatar。
 *
 * 清单由本地抓取脚本生成，记录了从两个维基拿到的头像：段名与名单快照的来源
 * 一一对应（prts → 泰拉，fz → 塔卫二），每条含人物名与本地文件路径。
 *
 * 关联规则刻意**只按「世界 + 人物名」精确匹配**：名单入库用的就是这个名字，
 * 两个世界是两套独立名单，同名也只是巧合 —— 再加一层模糊匹配（别名、转写、
 * 去后缀）只会把头像挂到错误的人身上，那比没有头像糟糕得多。
 * 对不上的名字不报错（清单与名单本来就不必一一对应），但必须点名让跑种子的人看见。
 *
 * 落盘目录按**所属世界**命名（`assets/avatars/terra|talos`），不用来源名 ——
 * 来源（从哪个维基抓的）是采集期的事，运行期读者只关心世界；换一次抓取源也不必改目录。
 * 头像本体随仓库分发；清单（内含抓取机的本地绝对路径）仍在仓库外。缺失不是错误，
 * 由调用方说出来 —— 静默跳过会让「少了一半头像」看起来像「本来就没有」。
 */
class CharacterAvatars
{
    /** 清单段名 → 人物所属世界。 */
    private const SECTIONS = [
        'prts' => World::Terra,
        'fz' => World::Talos,
    ];

    /**
     * 清单名 → 库名。只收**种子器已经裁决过的归并**，不做任何模糊推断：
     * 名单把管理员按性别拆成两行（共用英文代号 Endministrator），种子取「管理员·男」
     * 那行入库、「管理员·女」作为同一个人的另一版本跳过 —— 头像跟同一裁决走。
     * 因此「管理员·女」仍会以名单之外上报：在清单里它确实是库外的一行。
     */
    private const ALIASES = [
        '管理员·男' => '管理员',
    ];

    /**
     * 执行关联。
     *
     * @param  string  $manifestPath  manifest.json 的绝对路径；缺失或不是合法 JSON 时返回 null
     * @param  string  $avatarRoot  头像根目录的绝对路径（public/assets/avatars）
     * @return array{linked: int, missing_file: int, unknown: list<string>}|null
     */
    public static function associate(string $manifestPath, string $avatarRoot): ?array
    {
        if (! is_file($manifestPath)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            return null;
        }

        $linked = 0;
        $missingFile = 0;
        $unknown = [];

        // 一次取回全部人物，按「世界|名字」建索引：清单里几百条逐条 first() 就是几百次查询。
        // 世界走模型的 world() 解析而不是裸列值 —— 库里的历史行与未指定世界的行
        // 约定俗成落在泰拉（Character::world() 的既定语义），裸查 world = 'terra' 会漏掉它们
        $characters = Character::query()->get()
            ->keyBy(fn (Character $character) => $character->world()->value.'|'.$character->name);

        foreach (self::SECTIONS as $section => $world) {
            foreach ($manifest[$section]['items'] ?? [] as $item) {
                $name = trim((string) ($item['name'] ?? ''));
                $file = basename((string) ($item['file'] ?? ''));

                if ($name === '' || $file === '') {
                    continue;
                }

                // 名单归并过的名字走别名表（裁决见 ALIASES），仍找不到才算名单之外
                $resolved = self::ALIASES[$name] ?? $name;

                /** @var Character|null $character */
                $character = $characters->get($world->value.'|'.$resolved);

                if ($character === null) {
                    $unknown[] = $section.':'.$name;

                    continue;
                }

                // 文件必须真的存在：清单在、图不在时写入路径只会产出 404 碎图，
                // 不如不写 —— 页面退回首字方块，人物本身仍然完整。
                // 目录用世界名而不是清单段名：来源是采集期的事，运行期只按世界落盘
                $relative = 'assets/avatars/'.$world->value.'/'.$file;

                if (! is_file(rtrim($avatarRoot, '/').'/'.$world->value.'/'.$file)) {
                    $missingFile++;

                    continue;
                }

                // 值没变就不写：seed 可以反复跑，不该每次都产生一堆无谓的 UPDATE
                if ($character->avatar !== $relative) {
                    $character->fill(['avatar' => $relative])->save();
                }

                $linked++;
            }
        }

        return ['linked' => $linked, 'missing_file' => $missingFile, 'unknown' => $unknown];
    }
}
