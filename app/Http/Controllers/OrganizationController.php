<?php

namespace App\Http\Controllers;

use App\Enums\FactionKind;
use App\Enums\World;
use App\Models\Faction;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * 组织：**有成员、有归属、但没有边界**的实体。
 *
 * 收录判据只有一处：`FactionKind::isOrganization()`（排除政体与地域）。
 * 政体（维多利亚）与地域（文明环带）有疆域与层级，属于「在哪里」，在地名页 ——
 * 若把它们也收进来，这一页会变成一张什么都往里塞的表。
 *
 * **按世界分列**：组织回答的是「谁在做」，而泰拉与塔卫二各有一批自己的组织
 * （莱茵生命在泰拉、终末地工业在塔卫二），混在一页里读者无从判断某个名字属于哪边的历史。
 *
 * 但归属是**推导**的，不是新加一列：阵营刻意没有 world ——
 * 同一个组织可以同时出现在两个世界的历史里，按世界切分只会把它拆成两份。
 * 判据见 `Faction::belongsToWorld()`：自身在本世界有条目或人物即属于本世界，
 * 自身毫无链接的（内部部门、委员会）随最近的、有链接的上级。
 */
class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $world = World::fromRequest($request->string('world')->value());
        $organizations = $this->organizations($world);

        return view('organizations.index', [
            'world' => $world,
            'worlds' => World::switcherOptions(),
            'groups' => $this->groups($organizations),
            'total' => $organizations->count(),
        ]);
    }

    /**
     * 本世界里出现过的组织。
     *
     * @return Collection<int, Faction>
     */
    private function organizations(World $world): Collection
    {
        return Faction::query()
            ->organizations()
            // children 供「下属 …」显示；
            // parent 的计数同样要预载 —— 归属推导要沿上级链走，
            // 上级身上没有计数就会各自回查一次，而「内部部门」正好都要走这一步
            ->with(['children', 'parent' => fn ($query) => $query->withCount($this->worldCounts($world))])
            ->withCount($this->worldCounts($world))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (Faction $faction) => $faction->belongsToWorld($world))
            ->values();
    }

    /**
     * 本世界的条目数 / 人物数。
     *
     * 只用**本世界**的计数：泰拉页上的「31 条条目」不该把塔卫二的也算进去 ——
     * 那正是「按世界分列」要消灭的那种含糊。
     *
     * @return array<string, \Closure|string>
     */
    private function worldCounts(World $world): array
    {
        return [
            'events as world_events_count' => fn ($query) => $query->where('world', $world->value),
            'characters as world_characters_count' => fn ($query) => $query->where('world', $world->value),
        ];
    }

    /**
     * 按**类型**分组。
     *
     * 不按 parent 嵌套：组织的上级多半是政体（莱茵生命属哥伦比亚），
     * 套成一棵树只会把政体也拖进这一页 —— 而这正是要避免的事。
     * 因此改为并列成行 + 注明上级归属，类型本身承担分组。
     *
     * 分组顺序取自枚举（`FactionKind::organizationOrder()`），不在这里另写一份 ——
     * 「哪些算组织、按什么次序排」只该有一个出处。
     *
     * @param  Collection<int, Faction>  $organizations
     * @return list<array{kind: FactionKind, items: Collection<int, Faction>}>
     */
    private function groups(Collection $organizations): array
    {
        $byKind = $organizations->groupBy(fn (Faction $faction) => $faction->kind->value);

        $groups = [];

        foreach (FactionKind::organizationOrder() as $kind) {
            $items = $byKind->get($kind->value);

            if ($items !== null && $items->isNotEmpty()) {
                $groups[] = ['kind' => $kind, 'items' => $items];
            }
        }

        return $groups;
    }
}
