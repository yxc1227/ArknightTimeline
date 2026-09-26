<?php

namespace App\Support;

/**
 * 《大地巡旅》的可引用语料来源。
 *
 * ## 为什么只取节录，而不是把整本书塞进 sources.raw_text
 *
 *  1. **版权边界**：仓库只收录「出处引用与考据讨论」所需的片段（README 已有声明）。
 *     整书入库等于换个地方做资料再分发，与那条声明自相矛盾。
 *  2. **栏目容量**：`raw_text` 是 MySQL TEXT（64 KB），整书 1.16 MB **放不下**。
 *     更糟的是它不会报错，而是静默截断 —— 那时引文偏移全部错位，比不存还危险。
 *  3. **页面与 AI 输入**：出处页会把 raw_text 整段渲染出来，梳理请求也以它为输入，
 *     整书会把两者一起压垮。
 *
 * ## 节录的选取标准：与引文一一对应
 *
 * 当前唯一被**逐字引用**的部分是书末附录「泰拉纪年」——散文卷（世界卷 / 国家与地区卷）
 * 按项目规则不附引文，只把内容写进条目的详述。因此语料只取这一卷，
 * 「L3 出处可定位」这条闸门也就完整覆盖了所有引文。
 *
 * 原文文件刻意**不入库**（`.gitignore` 里排除）：它体积大，且版权归鹰角网络所有。
 * 文件缺失时本类返回 null，seed 流程照常跑完，只是没有语料可定位。
 */
final class TerraTourCorpus
{
    /** 原文文件（相对仓库根目录）。 */
    public const FILE = 'docs/TERRA A JOURNEY.txt';

    /** 起始锚点：附录标题行同时含中文名与英文名，避免撞上正文里的零散提及。 */
    private const START_ANCHORS = ['泰拉纪年', 'TIMELINE OF TERRAN'];

    /** 结束锚点：后记标题行（节录不含它本身）。 */
    private const END_ANCHORS = ['后记', 'POSTFACE'];

    public static function path(): string
    {
        return base_path(self::FILE);
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /**
     * 抽取附录节录。
     *
     * @return array{text: string, from_line: int, to_line: int}|null
     *         文件缺失或锚点找不到时返回 null —— 调用方降级即可，不必让它成为致命错误。
     */
    public static function excerpt(): ?array
    {
        $path = self::path();

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        // 不跳过空行：行下标要与文件的真实行号一一对应，
        // 否则报出来的「第 N 行」拿到原书里根本对不上。
        $lines = file($path);

        if ($lines === false) {
            return null;
        }

        $lines = array_map(static fn (string $line): string => rtrim($line, "\r\n"), $lines);

        $start = null;
        $end = count($lines);

        foreach ($lines as $index => $line) {
            if ($start === null) {
                // 目录里也有同一个标题（形如「420 泰拉纪年 TIMELINE OF TERRAN HISTORY」），
                // 因此要求锚点行不以数字开头 —— 目录条目一律是「页码 + 标题」。
                if (! preg_match('/^\s*\d/', $line) && self::containsAll($line, self::START_ANCHORS)) {
                    $start = $index;
                }

                continue;
            }

            if (self::containsAll($line, self::END_ANCHORS)) {
                $end = $index;
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $slice = array_slice($lines, $start, $end - $start);

        // 去掉首尾空行（行号随之修正）：节录不该以一堆空行开头或结尾
        while ($slice !== [] && trim($slice[0]) === '') {
            array_shift($slice);
            $start++;
        }

        while ($slice !== [] && trim((string) end($slice)) === '') {
            array_pop($slice);
        }

        if ($slice === []) {
            return null;
        }

        return [
            'text' => implode("\n", $slice),
            'from_line' => $start + 1,
            'to_line' => $start + count($slice),
        ];
    }

    /** 节录对应的定位器（找不到文件时为 null）。 */
    public static function locator(): ?CorpusLocator
    {
        $excerpt = self::excerpt();

        return $excerpt === null ? null : CorpusLocator::forText($excerpt['text']);
    }

    /** @param list<string> $needles */
    private static function containsAll(string $line, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (! str_contains($line, $needle)) {
                return false;
            }
        }

        return true;
    }
}
