<?php

namespace App\Http\Controllers;

use App\Models\TimelineAnomaly;
use App\Services\TimelineConsistencyChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * 一致性收件箱。
 *
 * 这里体现的是「冲突处理的第二条线」：第一条线是写入时的乐观锁冲突（技术层，秒级解决），
 * 第二条线是巡检发现的语义冲突（需要人来判断谁对），它没有截止时间但必须可见、可指派、可消解。
 */
class AnomalyController extends Controller
{
    public function __construct(private readonly TimelineConsistencyChecker $checker)
    {
    }

    public function index(Request $request): View
    {
        // 类型 / 级别 / 状态在侧栏各有自己的下拉，关键词只搜告警正文，各管各的维度
        $keyword = trim((string) $request->string('q')->value());

        $anomalies = TimelineAnomaly::query()
            ->with(['event', 'relatedEvent'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->value()))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')->value()))
            ->when($request->input('status', 'open') !== 'all', fn ($q) => $q->where('status', $request->input('status', 'open')))
            ->when($keyword !== '', fn ($query) => $query->where('message', 'like', "%{$keyword}%"))
            ->orderByRaw("case severity when 'error' then 0 when 'warning' then 1 else 2 end")
            ->latest()
            ->paginate(40)
            ->withQueryString();

        return view('anomalies.index', [
            'anomalies' => $anomalies,
            'summary' => $this->checker->openSummary(),
            'canReview' => $request->user()?->canReview() ?? false,
            'filters' => ['q' => $keyword],
        ]);
    }

    public function resolve(Request $request, TimelineAnomaly $anomaly): JsonResponse
    {
        Gate::authorize('resolve', $anomaly);

        $validated = $request->validate([
            'status' => ['required', 'in:resolved,ignored,open'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $anomaly->forceFill([
            'status' => $validated['status'],
            'resolved_by' => $validated['status'] === 'open' ? null : $request->user()->id,
            'resolved_at' => $validated['status'] === 'open' ? null : now(),
            'context' => [...($anomaly->context ?? []), 'resolution_note' => $validated['note'] ?? null],
        ])->save();

        return response()->json(['anomaly' => $anomaly->fresh(['event'])->toApiArray(), 'message' => '异常已更新。']);
    }

    /** 全量体检。规则变更后或定期执行，保证历史数据也被新规则覆盖。 */
    public function scan(Request $request): JsonResponse
    {
        Gate::authorize('scan', TimelineAnomaly::class);

        $result = $this->checker->checkAll();

        return response()->json([
            'result' => $result,
            'summary' => $this->checker->openSummary(),
            'message' => sprintf('已体检 %d 条条目，产出 %d 项异常。', $result['checked'], $result['anomalies']),
        ]);
    }
}
