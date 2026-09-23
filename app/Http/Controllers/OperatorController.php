<?php

namespace App\Http\Controllers;

use App\Enums\World;
use App\Models\Character;
use App\Models\Faction;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 干员 / 人员简介。
 *
 * 两个世界各有各的名单，因此这一页是**分世界**的：
 *   · 泰拉 → 干员档案的权威内容在 PRTS 维基；
 *   · 塔卫二 → 在终末地 WIKI（fz.wiki）。
 *
 * 本仓库只维护「与时间线相关的一行简介」，详细资料一律外链。
 * 理由不是省事，而是可维护性：一份抄自别处、又落后于对方更新的人物档案，
 * 恰好是本项目里最无法追溯出处的东西。
 */
class OperatorController extends Controller
{
    /** 每页人数。卡片网格用 24（4 列 × 6 行）在桌面与移动端都不会出现半行。 */
    private const PER_PAGE = 24;

    public function index(Request $request): View
    {
        $world = World::fromRequest($request->string('world')->value());

        $filters = [
            'q' => trim((string) $request->string('q')->value()) ?: null,
            'faction' => $request->integer('faction') ?: null,
        ];

        // withCount 而不是在视图里逐张卡查一次：卡片网格一页 24 张，
        // 那会变成 24 次查询，而且是在最显眼的页面上
        $query = Character::query()
            ->ofWorld($world)
            ->with('faction')
            ->withCount('events')
            ->search($filters['q']);

        if ($filters['faction'] !== null) {
            // 按阵营筛选时带上子阵营：选「罗德岛」应当也能筛出「医疗部」的人
            $ids = Faction::find($filters['faction'])?->selfAndDescendantIds() ?? [$filters['faction']];
            $query->whereIn('faction_id', $ids);
        }

        return view('operators.index', [
            'characters' => $query->orderBy('sort_order')->orderBy('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'world' => $world,
            'worlds' => World::switcherOptions(),
            // 只列出「在这个世界里确实有人物归属」的阵营：
            // 列一个点进去是空列表的选项没有意义，而跨世界的阵营
            // （罗德岛同时出现在两边）会在各自的名单里各自出现
            'factions' => Faction::whereIn(
                'id',
                Character::ofWorld($world)->whereNotNull('faction_id')->distinct()->pluck('faction_id'),
            )->orderBy('sort_order')->get(),
            'counters' => [
                'total' => Character::ofWorld($world)->count(),
                'with_profile' => Character::ofWorld($world)
                    ->whereNotNull('description')->where('description', '!=', '')->count(),
                'other_world' => Character::ofWorld($world === World::Terra ? World::Talos : World::Terra)->count(),
            ],
        ]);
    }

    public function show(Character $character): View
    {
        $character->load('faction');

        /*
         * 关联条目按世界分组。
         *
         * 人物本身只属于一个世界，但**条目与人物的关联可以跨世界**
         * （例如塔卫二的条目里提到罗德岛时期的人）。两套纪年不可比，
         * 因此按世界分开列出，而不是混成一条时间序列。
         */
        $eventsByWorld = collect(World::cases())
            ->mapWithKeys(fn (World $world) => [
                $world->value => $character->events()
                    ->ofWorld($world)
                    ->with('era')
                    ->timelineOrder()
                    ->get(),
            ])
            ->filter(fn ($events) => $events->isNotEmpty());

        return view('operators.show', [
            'character' => $character,
            'eventsByWorld' => $eventsByWorld,
            'eventCount' => $eventsByWorld->flatten(1)->count(),
        ]);
    }
}
