<?php

namespace App\Http\Controllers;

use App\Enums\SourceType;
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
        $sources = Source::query()
            ->withCount('events')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->value()))
            ->orderBy('type')
            ->orderBy('release_order')
            ->paginate(50)
            ->withQueryString();

        return view('sources.index', [
            'sources' => $sources,
            'types' => SourceType::options(),
            'eras' => Era::ordered()->get(),
            'canEdit' => $request->user()?->canEditEvents() ?? false,
        ]);
    }

    public function show(Source $source): View
    {
        return view('sources.show', [
            'source' => $source->load(['events' => fn ($q) => $q->timelineOrder()->with('era')]),
            'eras' => Era::ordered()->get(),
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
