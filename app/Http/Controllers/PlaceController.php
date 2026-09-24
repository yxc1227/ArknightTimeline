<?php

namespace App\Http\Controllers;

use App\Enums\World;
use App\Models\Place;
use Illuminate\Http\Request;
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

        return view('places.index', [
            'world' => $world,
            'worlds' => World::switcherOptions(),
            'places' => $this->tree($world),
        ]);
    }

    /**
     * 按树展开成「父在子前」的扁平列表，每项带深度供界面缩进。
     *
     * 排序在 PHP 里做而不是 SQL：`orderBy('sort_order')` 只能保证同级的先后，
     * 而列表要的是**深度优先**的顺序 —— 让「维多利亚 → 维多利亚王国 → 伦蒂尼姆」
     * 连在一起，而不是把所有「法理王国」堆在一起。
     *
     * @return list<array{place: Place, depth: int}>
     */
    private function tree(World $world): array
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

        $ordered = [];

        // 递归下降。上限 8 层：地名的层级是人写的，出现环时不至于把进程拖死
        $walk = function (int $parentId, int $depth) use (&$walk, &$ordered, $byParent): void {
            if ($depth > 8) {
                return;
            }

            foreach ($byParent[$parentId] ?? [] as $place) {
                $ordered[] = ['place' => $place, 'depth' => $depth];
                $walk($place->id, $depth + 1);
            }
        };

        $walk(0, 0);

        return $ordered;
    }
}
