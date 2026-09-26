<?php

namespace App\Http\Controllers;

use App\Enums\World;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * 词条：书里给出专门解释的术语与专名（金律乐章、帝政主义、圣愚……）。
 *
 * 与地名、组织一样**按世界分列**，但判据不同：地名与世界是绑定的，
 * 组织的世界是推导的，而词条**本身就是带着视角的释义** ——
 * 「金律乐章」是莱塔尼亚的立国宪章，「协议」是塔卫二上的失落之物，
 * 混在一页里读者无从判断某个词属于哪边的历史。
 *
 * 世界归属因此是**存储**的（`terms.world`），且**可空**：空值表示两个世界通用。
 * 「源石」这类概念两边都成立，强行归给某一方反而错；通用词条会在两页都列出。
 *
 * 种族不走这一套：它是一份**共享的分类**，同一种族本来就出现在两个世界的历史里，
 * 按世界切分只会把同一个概念拆成两份。
 *
 * 释义一律是本仓库据书中相应章节转写的**概括**，不是原文摘录，因此不附引文
 * （时间线条目里的引文才是逐字核对过的）。这件事必须写在页面上：
 * 读者有权知道哪一段是引文、哪一段是转写。
 */
class TermController extends Controller
{
    public function index(Request $request): View
    {
        $world = World::fromRequest($request->string('world')->value());

        // 侧栏筛选（与时间线同形）。category 不在字典里时按「未筛选」处理：
        // 手改 URL 得到的是完整列表，而不是一张谁也不明白为什么空着的列表
        $categoryRaw = $request->string('category')->value();
        $category = array_key_exists($categoryRaw, Term::CATEGORIES) ? $categoryRaw : null;
        $keyword = trim((string) $request->string('q')->value());

        $terms = $this->terms($world, $keyword, $category);

        return view('terms.index', [
            'world' => $world,
            'worlds' => World::switcherOptions(),
            'terms' => $terms,
            'visible' => $terms->flatten()->count(),
            'shared' => Term::whereNull('world')->count(),
            'categories' => Term::CATEGORIES,
            'filters' => ['q' => $keyword, 'category' => $category],
        ]);
    }

    /**
     * 本世界看得到的词条：本世界专属的 **加上**通用的。
     *
     * 按分类分组。
     *
     * @return Collection<string, Collection<int, Term>>
     */
    private function terms(World $world, string $keyword = '', ?string $category = null): Collection
    {
        return Term::visibleIn($world)
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->when($keyword !== '', fn ($query) => $query->where(fn ($search) => $search
                ->where('name', 'like', "%{$keyword}%")
                ->orWhere('origin', 'like', "%{$keyword}%")
                ->orWhere('definition', 'like', "%{$keyword}%")))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('category');
    }
}
