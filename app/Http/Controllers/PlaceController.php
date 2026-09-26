<?php

namespace App\Http\Controllers;

use App\Enums\World;
use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * 地名：**有疆域、有上下层级**的实体。
 *
 * 与组织分开成一页，是因为它们回答的是两个不同的问题：
 * 地名回答「在哪里」（有边界、可聚合条目、能一路下钻到聚落），
 * 组织回答「谁在做」（有成员、有归属，但没有边界）。
 * 挤在同一页里，读者没法判断「萨米」与「莱茵生命」是不是同一类东西。
 *
 * 地名**天然分世界**（四号谷地不在泰拉），因此按世界过滤 —— 这是四个页里唯一需要切换器的一页。
 */
class PlaceController extends Controller
{
    public function index(Request $request): View
    {
        $world = World::fromRequest($request->string('world')->value());

        // 筛选与时间线同形地放进侧栏。kind 不在 KINDS 里时按「未筛选」处理：
        // 手改 URL 得到的是完整列表，而不是一张谁也不明白为什么空着的表
        $kindRaw = $request->string('kind')->value();
        $kind = array_key_exists($kindRaw, Place::KINDS) ? $kindRaw : null;
        $keyword = trim((string) $request->string('q')->value());

        $tree = $this->tree($world, $keyword, $kind);

        return view('places.index', [
            'world' => $world,
            'worlds' => World::switcherOptions(),
            'places' => $tree['nodes'],
            // 命中集合只给视图做标注用：命中项高亮、仅为提供上下文的祖先压暗 ——
            // 不分开的话，读者会以为祖先也是搜索结果
            'matched' => $tree['matched'],
            'kinds' => Place::KINDS,
            'filters' => ['q' => $keyword, 'kind' => $kind],
            // 树被过滤过之后，可见行数不再等于真实的下辖数；
            // 视图靠这个标记把「（N 个下辖）」的注记收起来，免得读者对着行数数不齐
            'filtered' => $keyword !== '' || $kind !== null,
        ]);
    }

    /**
     * 按树展开成「父在子前」的扁平列表，每项带深度供界面缩进。
     *
     * 排序在 PHP 里做而不是 SQL：`orderBy('sort_order')` 只能保证同级的先后，
     * 而列表要的是**深度优先**的顺序 —— 让「维多利亚 → 维多利亚王国 → 伦蒂尼姆」
     * 连在一起，而不是把所有「法理王国」堆在一起。
     *
     * 过滤同样在 PHP 层做：SQL 能查到「子孙」却查不到「祖先」——
     * 直接 where 会把命中节点的父链掐断，缩进树就断了上下文。
     *
     * @return array{nodes: list<array{place: Place, depth: int}>, matched: array<int, bool>}
     */
    private function tree(World $world, string $keyword = '', ?string $kind = null): array
    {
        $places = Place::ofWorld($world)
            // children 供列表显示「N 个下辖」，不预载就是每行一次查询
            ->with(['faction', 'children'])
            ->withCount('events')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // groupBy 的键用 0 而不是 null：集合分组的空键会变成空字符串，混用两种键会漏掉一整层
        $byParent = $places->groupBy(fn (Place $place) => $place->parent_id ?? 0);

        // 没有筛选时整棵树可见；有筛选时只留「命中项 + 它们的祖先」
        $visible = null;
        $matched = [];

        if ($keyword !== '' || $kind !== null) {
            [$visible, $matched] = $this->visibleIds($places, $keyword, $kind);
        }

        $ordered = [];

        // 递归下降。上限 8 层：地名的层级是人写的，出现环时不至于把进程拖死
        $walk = function (int $parentId, int $depth) use (&$walk, &$ordered, $byParent, $visible): void {
            if ($depth > 8) {
                return;
            }

            foreach ($byParent[$parentId] ?? [] as $place) {
                if ($visible !== null && ! isset($visible[$place->id])) {
                    continue;
                }

                $ordered[] = ['place' => $place, 'depth' => $depth];
                $walk($place->id, $depth + 1);
            }
        };

        $walk(0, 0);

        return ['nodes' => $ordered, 'matched' => $matched];
    }

    /**
     * 命中筛选条件的节点 id，连同沿 parent_id 回溯出的全部祖先。
     *
     * 祖先必须保留：读者要看的是「它挂在树的哪个位置」，
     * 只给一行孤零零的匹配项，层级信息反而丢了。
     *
     * @param  Collection<int, Place>  $places
     * @return array{0: array<int, bool>, 1: array<int, bool>}  [可见（命中 + 祖先）, 命中]
     */
    private function visibleIds(Collection $places, string $keyword, ?string $kind): array
    {
        $byId = $places->keyBy('id');
        $visible = [];
        $matched = [];

        foreach ($places as $place) {
            if ($kind !== null && $place->kind !== $kind) {
                continue;
            }

            if ($keyword !== '') {
                $haystack = $place->name
                    .implode('', (array) $place->aliases)
                    .$place->description;

                if (mb_stripos($haystack, $keyword) === false) {
                    continue;
                }
            }

            $matched[$place->id] = true;

            $node = $place;

            while ($node !== null) {
                $visible[$node->id] = true;
                $node = $node->parent_id !== null ? $byId->get($node->parent_id) : null;
            }
        }

        return [$visible, $matched];
    }
}
