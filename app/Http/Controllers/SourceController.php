<?php

namespace App\Http\Controllers;

use App\Enums\SourceType;
use App\Enums\World;
use App\Models\Era;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 出处管理。
 *
 * 出处不只是「分类标签」，它同时是 **AI 梳理的语料载体**：
 * sources.raw_text 存放剧情原文 / 设定集段落，引用定位（evidence.offset）
 * 就是在这段原文上做字符偏移，所以它是整个溯源链的根。
 */
class SourceController extends Controller
{
    public function index(Request $request): View
    {
        // 侧栏筛选（与时间线同形）。类型不在枚举里时按「未筛选」处理：
        // 手改 URL 得到的是完整列表，而不是一张谁也不明白为什么空着的表
        $type = SourceType::tryFrom((string) $request->string('type')->value()) ?? null;
        $keyword = trim((string) $request->string('q')->value());

        $sources = Source::query()
            ->withCount('events')
            ->when($type !== null, fn ($query) => $query->where('type', $type->value))
            ->when($keyword !== '', fn ($query) => $query->where(fn ($search) => $search
                ->where('name', 'like', "%{$keyword}%")
                ->orWhere('code', 'like', "%{$keyword}%")
                ->orWhere('description', 'like', "%{$keyword}%")))
            ->orderBy('type')
            ->orderBy('release_order')
            ->paginate(50)
            ->withQueryString();

        return view('sources.index', [
            // 语料库列表刻意不按世界过滤：出处是「我们手上有哪些资料」，
            // 两个世界的资料同屏可见才便于盘点（行内会标出各自的世界）
            'sources' => $sources,
            'filters' => ['q' => $keyword, 'type' => $type?->value],
            'types' => SourceType::options(),
            'worlds' => World::options(),
            'eras' => Era::ofWorld(World::fromRequest($request->string('world')->value()))->leaves()->ordered()->get(),
            'canEdit' => $request->user()?->canEditEvents() ?? false,
        ]);
    }

    public function show(Source $source): View
    {
        return view('sources.show', [
            'source' => $source->load(['events' => fn ($q) => $q->timelineOrder()->with('era')]),
            // 单个出处的纪元下拉按该出处自身的世界收敛
            'eras' => Era::ofWorld($source->world)->leaves()->ordered()->get(),
        ]);
    }

    /** 保存原文（AI 梳理的输入）。 */
    public function update(Request $request, Source $source): JsonResponse
    {
        abort_unless($request->user()?->canEditEvents(), 403, '需要编辑权限。');

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:80'],
            'chapter' => ['nullable', 'string', 'max:160'],
            'release_order' => ['nullable', 'integer'],
            'release_date' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
            'raw_text' => ['nullable', 'string', 'max:500000'],
        ]);

        $source->fill($validated)->save();

        return response()->json([
            'source' => $source->fresh()->toApiArray(),
            'message' => '出处已保存。',
        ]);
    }
}
