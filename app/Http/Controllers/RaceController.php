<?php

namespace App\Http\Controllers;

use App\Models\Race;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * 种族：人物挂在它上面（`characters.race_id`）。
 *
 * 种族是**共享维度** —— 同一种族可以出现在两个世界的历史里，
 * 强行按世界切分只会把同一个概念拆成两份，因此这一页没有世界切换器，
 * 人物数是两个世界的合计（页面上标注了这一点）。
 */
class RaceController extends Controller
{
    public function index(Request $request): View
    {
        // 种族不分世界，搜索同样不分世界：一份字典查两边的历史
        $keyword = trim((string) $request->string('q')->value());

        return view('races.index', [
            'races' => $this->races($keyword),
            'filters' => ['q' => $keyword],
        ]);
    }

    /**
     * 按书里的排序给出，附带人物数。
     *
     * 计数用 withCount，而不是逐条 `$race->characters->count()` ——
     * 后者在列表里就是 N 次查询，而列表正是最显眼的那一页。
     *
     * @return Collection<int, Race>
     */
    private function races(string $keyword = ''): Collection
    {
        return Race::query()
            ->withCount('characters')
            ->when($keyword !== '', fn ($query) => $query->where(fn ($search) => $search
                ->where('name', 'like', "%{$keyword}%")
                ->orWhere('english', 'like', "%{$keyword}%")
                ->orWhere('description', 'like', "%{$keyword}%")))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
