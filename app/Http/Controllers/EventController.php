<?php

namespace App\Http\Controllers;

use App\Enums\World;
use App\Exceptions\EditConflictException;
use App\Exceptions\WriteDeniedException;
use App\Http\Requests\StoreAnnotationRequest;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Annotation;
use App\Models\Character;
use App\Models\Era;
use App\Models\Event;
use App\Models\Faction;
use App\Models\Source;
use App\Models\Tag;
use App\Services\EventLockService;
use App\Services\EventWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Gate;

class EventController extends Controller
{
    public function __construct(
        private readonly EventWriter $writer,
        private readonly EventLockService $locks,
    ) {
    }

    /**
     * 条目详情。
     *
     * 同一份数据两个出口：
     *  · 时间线页内的编辑侧栏（抽屉）通过 fetch 拉 JSON —— `api()` 始终带
     *    `Accept: application/json`，因此 `$request->wantsJson()` 为真，走 JSON；
     *  · 直接访问 `/events/61`（例如从人物页、或别人发来的链接）是浏览器整页导航，
     *    走 HTML 详情页，否则浏览器会把 JSON 原样显示出来。
     */
    public function show(Request $request, Event $event): \Symfony\Component\HttpFoundation\Response
    {
        $event->load(['era', 'sources', 'characters', 'factions', 'tags', 'annotations.user', 'causedBy', 'parent']);

        $payload = [
            'event' => $event->toApiArray(),
            'annotations' => $event->annotations->map(fn (Annotation $a) => $a->toApiArray()),
            'revisions' => $event->revisions()->with('user')->limit(20)->get()->map(fn ($r) => $r->toApiArray()),
            'lock' => $this->locks->status($event, $request->user()),
            'anomalies' => $event->openAnomalies()->with(['event', 'relatedEvent'])->get()->map(fn ($a) => $a->toApiArray()),
            'permissions' => $this->permissions($request, $event),
            'reference' => [
                // 纪元选项按条目自身的世界收敛：给泰拉条目列出塔罗斯历的纪元，
                // 只会诱使用户选出一个跨世界的归属，然后被 EventWriter 拒绝
                'eras' => Era::ofWorld($event->world())->ordered()->get(['id', 'name', 'slug']),
                'sources' => Source::orderBy('name')->get(['id', 'name', 'type', 'code']),
                'characters' => Character::orderBy('name')->limit(600)->get(['id', 'name', 'codename']),
                'factions' => Faction::orderBy('name')->get(['id', 'name', 'color']),
                'tags' => Tag::orderBy('name')->get(['id', 'name', 'color']),
            ],
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return response()->view('events.show', [
            'event' => $event,
            'data' => $payload,
        ]);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        Gate::authorize('create', Event::class);

        $event = $this->writer->create(
            data: $request->validated(),
            actor: $request->user(),
            ip: $request->ip(),
        );

        return response()->json([
            'event' => $event->load(['era', 'sources', 'characters', 'factions', 'tags'])->toApiArray(),
            'message' => '条目已创建。',
        ], 201);
    }

    /**
     * 更新。
     *
     * 三条出口：
     *  - 200：成功；
     *  - 409：版本冲突，返回字段级 diff 供前端合并（不是简单报错）；
     *  - 403：无权限 / 条目冻结 / 超出出处归属范围。
     */
    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validated();
        $expectedVersion = (int) $data['expected_version'];
        unset($data['expected_version'], $data['comment'], $data['resolutions']);

        try {
            // 冲突解决模式：前端已给出字段级裁决，走 resolveConflict。
            if ($request->filled('resolutions')) {
                $event = $this->writer->resolveConflict(
                    event: $event,
                    resolutions: $request->input('resolutions'),
                    mine: $data,
                    latestVersion: $event->fresh()->version,
                    actor: $request->user(),
                    ip: $request->ip(),
                );
            } else {
                $event = $this->writer->update(
                    event: $event,
                    data: $data,
                    expectedVersion: $expectedVersion,
                    actor: $request->user(),
                    ip: $request->ip(),
                );
            }
        } catch (EditConflictException $e) {
            return response()->json([...$e->toApiPayload(), 'current' => $event->fresh(['sources', 'characters', 'factions', 'tags'])->toApiArray()], 409);
        } catch (WriteDeniedException $e) {
            return response()->json($e->toApiPayload(), 403);
        }

        return response()->json([
            'event' => $event->load(['era', 'sources', 'characters', 'factions', 'tags'])->toApiArray(),
            'message' => '已保存（v'.$event->version.'）。',
        ]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('delete', $event);

        try {
            $this->writer->delete($event, $request->user(), $request->string('comment')->value() ?: null);
        } catch (WriteDeniedException $e) {
            return response()->json($e->toApiPayload(), 403);
        }

        return response()->json(['message' => '条目已删除（可从版本历史恢复）。']);
    }

    public function restore(Request $request, int $event): JsonResponse
    {
        $model = Event::withTrashed()->findOrFail($event);
        Gate::authorize('restore', $model);

        $restored = $this->writer->restore($event, $request->user());

        return response()->json(['event' => $restored->toApiArray(), 'message' => '条目已恢复。']);
    }

    public function revisions(Event $event): JsonResponse
    {
        return response()->json([
            'revisions' => $event->revisions()->with('user')->get()->map(fn ($r) => [
                ...$r->toApiArray(),
                'snapshot' => $r->snapshot,
                'base_snapshot' => $r->base_snapshot,
            ]),
        ]);
    }

    public function revert(Request $request, Event $event, int $version): JsonResponse
    {
        Gate::authorize('revert', $event);

        try {
            $event = $this->writer->revertTo($event, $version, $request->user());
        } catch (WriteDeniedException $e) {
            return response()->json($e->toApiPayload(), 403);
        }

        return response()->json([
            'event' => $event->load(['era', 'sources', 'characters', 'factions', 'tags'])->toApiArray(),
            'message' => "已回滚到 v{$version}（生成新版本 v{$event->version}，历史不被覆盖）。",
        ]);
    }

    /** 冻结 / 解冻：争议与废弃状态由审核员裁定。 */
    public function updateStatus(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('review', $event);

        $validated = $request->validate([
            'status' => ['required', 'in:draft,needs_review,verified,disputed,deprecated'],
            'expected_version' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $event = $this->writer->update(
                event: $event,
                data: ['status' => $validated['status']],
                expectedVersion: (int) $validated['expected_version'],
                actor: $request->user(),
                ip: $request->ip(),
            );
        } catch (EditConflictException $e) {
            return response()->json($e->toApiPayload(), 409);
        }

        return response()->json(['event' => $event->toApiArray(), 'message' => '状态已更新。']);
    }

    public function toggleLock(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('review', $event);

        $event->forceFill(['is_locked' => ! $event->is_locked])->save();

        return response()->json([
            'message' => $event->is_locked ? '条目已锁定，普通编辑者将只能提交标注建议。' : '条目已解锁。',
            'is_locked' => $event->is_locked,
        ]);
    }

    // ------------------------------------------------------------------ 标注

    public function annotate(StoreAnnotationRequest $request, Event $event): JsonResponse
    {
        $annotation = $this->writer->annotate($event, $request->validated(), $request->user());

        return response()->json([
            'annotation' => $annotation->load('user')->toApiArray(),
            'message' => '标注已提交。',
        ], 201);
    }

    public function resolveAnnotation(Request $request, Event $event, Annotation $annotation): JsonResponse
    {
        Gate::authorize('review', $event);

        $validated = $request->validate(['status' => ['required', 'in:accepted,rejected,resolved']]);

        $annotation->forceFill([
            'status' => $validated['status'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ])->save();

        return response()->json(['annotation' => $annotation->fresh('user')->toApiArray(), 'message' => '标注已处置。']);
    }

    // ------------------------------------------------------------------ 编辑租约

    public function acquireLock(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);

        try {
            $lock = $this->locks->acquire($event, $request->user(), $request->boolean('force'));
        } catch (WriteDeniedException $e) {
            return response()->json([...$e->toApiPayload(), 'lock' => $this->locks->status($event, $request->user())], 409);
        }

        return response()->json(['token' => $lock->token, 'expires_at' => $lock->expires_at->toIso8601String()]);
    }

    public function renewLock(Request $request, Event $event): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $lock = $this->locks->renew($request->string('token')->value(), $request->user());

        if (! $lock) {
            return response()->json(['message' => '租约已失效，可能已被他人接管。'], 410);
        }

        return response()->json(['expires_at' => $lock->expires_at->toIso8601String()]);
    }

    public function releaseLock(Request $request, Event $event): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $this->locks->release($request->string('token')->value(), $request->user());

        return response()->json(['message' => '已释放编辑租约。']);
    }

    private function permissions(Request $request, Event $event): array
    {
        $user = $request->user();

        return [
            'update' => $user?->can('update', $event) ?? false,
            'delete' => $user?->can('delete', $event) ?? false,
            'review' => $user?->can('review', $event) ?? false,
            'revert' => $user?->can('revert', $event) ?? false,
            'annotate' => true,
        ];
    }
}
