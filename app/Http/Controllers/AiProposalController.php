<?php

namespace App\Http\Controllers;

use App\Enums\ProposalStatus;
use App\Enums\World;
use App\Exceptions\WriteDeniedException;
use App\Http\Requests\SynthesizeRequest;
use App\Models\AiProposal;
use App\Models\Era;
use App\Models\Event;
use App\Models\Source;
use App\Services\Ai\AiEventSynthesizer;
use App\Services\ProposalApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class AiProposalController extends Controller
{
    public function __construct(
        private readonly AiEventSynthesizer $synthesizer,
        private readonly ProposalApplier $applier,
    ) {
    }

    /** AI 审核台。 */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AiProposal::class);

        // 状态 / 出处 / 批次在侧栏各有自己的筛选项，关键词只搜标题与摘要
        $keyword = trim((string) $request->string('q')->value());

        $proposals = AiProposal::query()
            ->with(['source', 'era', 'duplicateOf', 'reviewer'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batch_id', $request->string('batch_id')->value()))
            ->when($request->filled('source_id'), fn ($q) => $q->where('source_id', $request->integer('source_id')))
            ->when($keyword !== '', fn ($query) => $query->where(fn ($search) => $search
                ->where('title', 'like', "%{$keyword}%")
                ->orWhere('summary', 'like', "%{$keyword}%")))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('proposals.index', [
            'filters' => ['q' => $keyword],
            'proposals' => $proposals,
            'counters' => [
                'pending' => AiProposal::where('status', ProposalStatus::Pending->value)->count(),
                'duplicate' => AiProposal::where('status', ProposalStatus::Duplicate->value)->count(),
                'unverified' => AiProposal::where('status', ProposalStatus::Unverified->value)->count(),
                'applied' => AiProposal::where('status', ProposalStatus::Applied->value)->count(),
                'rejected' => AiProposal::where('status', ProposalStatus::Rejected->value)->count(),
            ],
            'sources' => Source::orderBy('name')->get(),
            // 纪元选项按当前世界收敛：跨世界的纪元区间不可比较，列出来只是干扰
            'eras' => Era::ofWorld(World::fromRequest($request->string('world')->value()))->leaves()->ordered()->get(),
            'canReview' => $request->user()->canReview(),
        ]);
    }

    /**
     * 触发梳理。这是 AI 补全的唯一入口 —— 产出永远是提案，不是条目。
     */
    public function synthesize(SynthesizeRequest $request): JsonResponse
    {
        Gate::authorize('create', AiProposal::class);

        $source = $request->filled('source_id') ? Source::findOrFail($request->integer('source_id')) : null;
        $rawText = (string) ($request->input('raw_text') ?: $source?->raw_text);

        if (blank($rawText)) {
            return response()->json(['message' => '该出处尚未录入原文，请先粘贴待梳理的文本。'], 422);
        }

        // 即席梳理时允许把文本回写到出处，便于下次复现与人工在原文上定位引用
        if ($source && $request->boolean('persist_raw_text') && $request->filled('raw_text')) {
            $source->forceFill(['raw_text' => $rawText])->save();
        }

        $era = $request->filled('era_id') ? Era::find($request->integer('era_id')) : null;

        try {
            $result = $this->synthesizer->synthesize(
                source: $source ?? $this->detachedSource(),
                rawText: $rawText,
                actor: $request->user(),
                era: $era,
                instruction: $request->input('instruction'),
            );
        } catch (Throwable $e) {
            return response()->json([
                'message' => '梳理失败：'.$e->getMessage(),
                'driver' => config('timeline.ai.driver'),
            ], 502);
        }

        return response()->json([
            'batch_id' => $result['batch_id'],
            'driver' => $result['driver'],
            'model' => $result['model'],
            'stats' => $result['stats'],
            'proposals' => $result['proposals']->map(fn (AiProposal $p) => $p->load(['source', 'era', 'duplicateOf'])->toApiArray()),
            'message' => sprintf(
                '本次抽取 %d 条候选：待审阅 %d、疑似重复 %d、出处缺失 %d。全部需要在审核台放行后才会进入时间线。',
                $result['stats']['extracted'],
                $result['stats']['pending'],
                $result['stats']['duplicate'],
                $result['stats']['unverified'],
            ),
        ]);
    }

    /** 采纳为新建条目。 */
    public function approve(Request $request, AiProposal $proposal): JsonResponse
    {
        Gate::authorize('review', $proposal);

        $validated = $request->validate([
            'overrides' => ['nullable', 'array'],
            'overrides.title' => ['nullable', 'string', 'max:160'],
            'overrides.summary' => ['nullable', 'string', 'max:2000'],
            'overrides.date_display' => ['nullable', 'string', 'max:120'],
            'overrides.start_index' => ['nullable', 'integer'],
            'overrides.end_index' => ['nullable', 'integer'],
            'overrides.date_precision' => ['nullable', 'string'],
            'overrides.date_confidence' => ['nullable', 'string'],
            'overrides.era_id' => ['nullable', 'integer', 'exists:eras,id'],
            'overrides.location' => ['nullable', 'string', 'max:120'],
            // 无出处放行必须显式勾选，且不可通过 overrides 绕过
            'acknowledge_missing_evidence' => ['nullable', 'boolean'],
        ]);

        try {
            $event = $this->applier->approve(
                proposal: $proposal,
                reviewer: $request->user(),
                overrides: $validated['overrides'] ?? [],
                acknowledgeMissingEvidence: $request->boolean('acknowledge_missing_evidence'),
            );
        } catch (WriteDeniedException $e) {
            return response()->json([
                ...$e->toApiPayload(),
                'hard_issues' => $proposal->hardIssues(),
                'soft_issues' => $proposal->softIssues(),
            ], 403);
        }

        return response()->json([
            'event' => $event->load(['era', 'sources', 'characters', 'factions', 'tags'])->toApiArray(),
            'message' => '提案已采纳并写入时间线（条目 #'.$event->id.'，状态：待校验）。',
        ]);
    }

    /** 判定为重复，合并进既有条目。 */
    public function merge(Request $request, AiProposal $proposal): JsonResponse
    {
        Gate::authorize('review', $proposal);

        $validated = $request->validate([
            'target_event_id' => ['required', 'integer', 'exists:events,id'],
        ]);

        $target = Event::findOrFail($validated['target_event_id']);

        $event = $this->applier->mergeInto($proposal, $target, $request->user());

        return response()->json([
            'event' => $event->load(['era', 'sources', 'characters', 'factions', 'tags'])->toApiArray(),
            'message' => '已合并进条目 #'.$event->id.'，出处引文与关联信息已追加。',
        ]);
    }

    public function reject(Request $request, AiProposal $proposal): JsonResponse
    {
        Gate::authorize('review', $proposal);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:2', 'max:1000'],
        ]);

        $proposal = $this->applier->reject($proposal, $request->user(), $validated['note']);

        return response()->json([
            'proposal' => $proposal->toApiArray(),
            'message' => '提案已驳回。驳回理由会作为后续 prompt 调优的反馈数据保留。',
        ]);
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        Gate::authorize('review', AiProposal::class);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:ai_proposals,id'],
        ]);

        $result = $this->applier->bulkApprove($validated['ids'], $request->user());

        return response()->json([
            'applied' => $result['applied'],
            'failed' => $result['failed'],
            'message' => sprintf('成功采纳 %d 条，失败 %d 条。', count($result['applied']), count($result['failed'])),
        ]);
    }

    public function show(AiProposal $proposal): JsonResponse
    {
        Gate::authorize('view', $proposal);

        return response()->json([
            'proposal' => $proposal->load(['source', 'era', 'duplicateOf', 'reviewer'])->toApiArray(),
            'raw_payload' => $proposal->raw_payload,
        ]);
    }

    /**
     * 即席梳理（无出处）时使用的临时载体。
     * 不落库，避免为用户临时粘贴的文本污染出处字典。
     */
    private function detachedSource(): Source
    {
        return new Source([
            'name' => '即席文本',
            'slug' => 'ad-hoc',
            'type' => 'other',
        ]);
    }
}
