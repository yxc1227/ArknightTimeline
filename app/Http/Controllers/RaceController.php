<?php

namespace App\Http\Controllers;

use App\Models\Race;
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
    public function index(): View
    {
        return view('races.index', [
            'races' => $this->races(),
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
    private function races(): Collection
    {
        return Race::query()
            ->withCount('characters')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
