@extends('layouts.app')

@section('title', 'AI 审核台 · 明日方舟时间线')
@section('page', 'proposals')

@section('content')
    {{-- ============================ 检索与筛选侧栏 ============================ --}}
    <aside class="sidebar">
        <form method="GET" action="{{ route('proposals.index') }}" class="sidebar__form">
            <x-filter-head :reset-url="route('proposals.index')"
                           placeholder="搜索标题 / 摘要"
                           :q="$filters['q']"/>

            <div class="sidebar__scroll">
                <details class="filter-group" open>
                    <summary data-en="Status">状态</summary>
                    <div class="filter-group__body">
                        <select name="status" data-autosubmit>
                            <option value="">全部</option>
                            @foreach (['pending' => '待审阅', 'duplicate' => '疑似重复', 'unverified' => '出处缺失', 'applied' => '已入库', 'rejected' => '已驳回'] as $value => $label)
                                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </details>

                <details class="filter-group" open>
                    <summary data-en="Source">出处</summary>
                    <div class="filter-group__body">
                        <select name="source_id" data-autosubmit>
                            <option value="">全部</option>
                            @foreach ($sources as $source)
                                <option value="{{ $source->id }}" @selected((int) request('source_id') === $source->id)>{{ $source->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </details>

                <details class="filter-group">
                    <summary data-en="Batch">批次</summary>
                    <div class="filter-group__body">
                        {{-- 批号是一次 AI 梳理的唯一标识：排查「某一批为什么错了」时按它收敛 --}}
                        <input type="text" name="batch_id" value="{{ request('batch_id') }}" placeholder="batch id" data-autosubmit>
                    </div>
                </details>
            </div>
        </form>
    </aside>

    <main class="main">

        {{-- ===================== 触发 AI 梳理 ===================== --}}
        <div class="panel">
            <div class="panel__title">
                <span data-en="Synthesize">AI 梳理 · 触发与校验</span>
                <span class="badge badge--info">产出仅为提案，需人工放行</span>
            </div>

            <div class="alert alert--info small">
                梳理结果会先经过四层校验（结构 → 时间可解析 → 原文可定位 → 时间线一致性），
                然后**停在提案区等待人工放行**。AI 永远不会直接写入时间线：
                错误的时间比缺失的时间更有害，所以这道闸门不可绕过。
            </div>

            <form id="synthesize-form">
                <div class="row">
                    <div class="field">
                        <label>出处</label>
                        <select name="source_id">
                            <option value="">（即席文本，不归属出处）</option>
                            @foreach ($sources as $source)
                                <option value="{{ $source->id }}">
                                    {{ $source->name }} · {{ $source->type->label() }}{{ $source->code ? ' · '.$source->code : '' }}{{ $source->raw_text ? ' ✓已存原文' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label>目标纪元（用于一致性预检）</label>
                        <select name="era_id">
                            <option value="">（不限）</option>
                            @foreach ($eras as $era)
                                <option value="{{ $era->id }}">{{ $era->name }} · {{ $era->date_label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label>待梳理原文（留空则使用所选出处已保存的 raw_text）</label>
                    <textarea name="raw_text" style="min-height:150px"
                              placeholder="粘贴剧情文本、设定集段落或年表。示例：&#10;泰拉历1096年12月23日，切尔诺伯格事变爆发，整合运动占领切尔诺伯格城区，罗德岛介入救援。&#10;1097年冬，龙门危机爆发…"></textarea>
                </div>

                <div class="row">
                    <div class="field">
                        <label>额外要求（可选）</label>
                        <input type="text" name="instruction" placeholder="例如：只抽取与乌萨斯相关的事件">
                    </div>
                    <div class="field" style="max-width:220px;display:flex;align-items:flex-end">
                        <label class="check"><input type="checkbox" name="persist_raw_text" checked> 把原文回写到出处</label>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn--primary" id="synthesize-submit"><x-icon name="spark"/>开始梳理</button>
                    <span class="faint small">
                        当前驱动：
                        <span class="mono">{{ config('timeline.ai.driver') }}</span>
                        @if (config('timeline.ai.driver') !== 'openai-compatible' || ! config('timeline.ai.api_key'))
                            <span class="badge badge--muted">未配置模型密钥，将使用离线规则抽取</span>
                        @endif
                    </span>
                </div>
            </form>
        </div>

        {{-- ===================== 统计与批量操作 ===================== --}}
        <div class="panel">
            <div class="panel__title">
                <span data-en="Queue">提案队列</span>
                <span class="stats-line">
                    <span>PENDING {{ str_pad($counters['pending'], 2, '0', STR_PAD_LEFT) }}</span>
                    <span>DUPLICATE {{ str_pad($counters['duplicate'], 2, '0', STR_PAD_LEFT) }}</span>
                    <span>UNGROUNDED {{ str_pad($counters['unverified'], 2, '0', STR_PAD_LEFT) }}</span>
                    <span>APPLIED {{ str_pad($counters['applied'], 2, '0', STR_PAD_LEFT) }}</span>
                    <span>REJECTED {{ str_pad($counters['rejected'], 2, '0', STR_PAD_LEFT) }}</span>
                </span>
            </div>

            {{-- 全选与批量采纳是**操作**不是筛选：筛选在侧栏，这里只对当前页做批量处理 --}}
            @if ($canReview)
                <div class="row" style="margin-bottom:12px">
                    <label class="check"><input type="checkbox" id="pick-all"> 全选本页</label>
                    <button type="button" class="btn" id="bulk-approve">批量采纳</button>
                </div>
            @endif

            <div id="proposals-host">
                @forelse ($proposals as $proposal)
                    @php $payload = $proposal->toApiArray(); @endphp
                    <div class="proposal">
                        <div class="proposal__head">
                            @if ($canReview && $proposal->status->canBeApproved())
                                <input type="checkbox" name="pick" value="{{ $proposal->id }}" onclick="event.stopPropagation()">
                            @endif

                            <div style="flex:1;min-width:0">
                                <div class="row" style="align-items:baseline">
                                    <strong style="font-size:14px">{{ $proposal->title }}</strong>
                                    <span class="mono small" style="color:var(--accent)">{{ $proposal->date_display }}</span>
                                    <span class="badge {{ $payload['status_badge'] }}"><x-icon name="{{ $proposal->status->icon() }}" class="icon--sm"/>{{ $payload['status_label'] }}</span>
                                    <span class="badge {{ $payload['confidence_tier'] === 'high' ? 'badge--ok' : ($payload['confidence_tier'] === 'medium' ? 'badge--warn' : 'badge--danger') }}">
                                        <x-icon name="{{ $payload['confidence_tier'] === 'high' ? 'status-ok' : ($payload['confidence_tier'] === 'medium' ? 'status-warn' : 'status-danger') }}" class="icon--sm"/>置信度 {{ $proposal->confidence }}
                                    </span>
                                    @if ($proposal->start_index)
                                        <span class="badge badge--muted">{{ $payload['date']['hint'] }}</span>
                                    @endif
                                </div>
                                <div class="faint small" style="margin-top:4px">
                                    {{ $payload['source']['name'] ?? '即席文本' }}
                                    · {{ $payload['driver'] }}{{ $payload['model'] ? ' / '.$payload['model'] : '' }}
                                    · {{ $proposal->created_at?->format('m-d H:i') }}
                                </div>
                            </div>
                        </div>

                        <div class="proposal__body">
                            @php
                                $hardIssues = $proposal->hardIssues();
                                $softIssues = $proposal->softIssues();
                            @endphp

                            @if ($hardIssues)
                                <div class="alert alert--danger" style="margin-top:12px">
                                    <strong>硬闸门：出处无法核实</strong>
                                    <ul style="margin:6px 0 0;padding-left:18px">
                                        @foreach ($hardIssues as $issue) <li>{{ $issue }}</li> @endforeach
                                    </ul>
                                    <div class="faint small" style="margin-top:6px">
                                        这条闸门不能靠修改字段绕过 —— 放行时会在审核记录里留下免责痕迹。
                                    </div>
                                </div>
                            @endif

                            @if ($softIssues)
                                <div class="alert alert--warn" style="margin-top:12px">
                                    <strong>需补正的问题（可用 overrides 修正后放行）</strong>
                                    <ul style="margin:6px 0 0;padding-left:18px">
                                        @foreach ($softIssues as $issue) <li>{{ $issue }}</li> @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($payload['duplicate_of'])
                                <div class="alert alert--warn" style="margin-top:12px">
                                    疑似与既有条目
                                    <a href="{{ route('timeline.index') }}?q={{ urlencode($payload['duplicate_of']['title']) }}" target="_blank">
                                        「{{ $payload['duplicate_of']['title'] }}」
                                    </a>
                                    重复（#{{ $payload['duplicate_of']['id'] }}）。建议合并而非新建。
                                </div>
                            @endif

                            <div class="section-label" data-en="Summary">摘要</div>
                            <p style="margin:0">{{ $proposal->summary }}</p>

                            <div class="section-label" data-en="Grounding / L3">出处核验 · 原文可定位</div>
                            @forelse ($proposal->evidence ?? [] as $item)
                                <div class="quote" data-matched="{{ ($item['matched'] ?? false) ? 1 : 0 }}" style="margin-bottom:7px">
                                    @if ($item['matched'] ?? false)
                                        <span class="badge badge--ok">✓ 已在原文定位</span>
                                    @else
                                        <span class="badge badge--danger">✗ 原文中找不到该片段</span>
                                    @endif
                                    <div style="margin-top:5px">{{ $item['quote'] }}</div>
                                    @if (($item['offset'] ?? null) !== null)
                                        <div class="faint small" style="margin-top:4px">原文字符偏移：{{ $item['offset'] }}</div>
                                    @endif
                                </div>
                            @empty
                                <div class="alert alert--danger">该提案没有提供任何原文引用，无法核实。</div>
                            @endforelse

                            <div class="section-label" data-en="Validation">校验明细</div>
                            <dl class="kv">
                                <dt>结构校验</dt><dd>{{ ($proposal->validation['schema']['passed'] ?? false) ? '通过' : '未通过' }}</dd>
                                <dt>时间可解析</dt><dd>
                                    {{ ($proposal->validation['date_parse']['passed'] ?? false) ? '通过' : '未通过' }}
                                    <span class="faint small">{{ $proposal->validation['date_parse']['hint'] ?? '' }}</span>
                                </dd>
                                <dt>出处定位</dt><dd>
                                    命中 {{ $proposal->validation['grounding']['matched'] ?? 0 }} / {{ $proposal->validation['grounding']['total'] ?? 0 }} 条引文
                                </dd>
                            </dl>

                            @if (! empty($proposal->validation['anomalies']))
                                <div class="section-label" data-en="Consistency / L4">一致性预检</div>
                                @foreach ($proposal->validation['anomalies'] as $anomaly)
                                    <div class="alert {{ ($anomaly['blocking'] ?? false) ? 'alert--danger' : 'alert--warn' }} small">
                                        {{ $anomaly['message'] }}
                                    </div>
                                @endforeach
                            @endif

                            @php
                                $characters = collect($proposal->characters ?? [])->pluck('name')->filter();
                                $factions = collect($proposal->factions ?? [])->pluck('name')->filter();
                                $tags = collect($proposal->tags ?? [])->map(fn ($t) => is_array($t) ? ($t['name'] ?? '') : $t)->filter();
                            @endphp

                            <div class="row" style="margin-top:14px">
                                <div>
                                    <div class="section-label" data-en="Character">相关人物</div>
                                    <div class="chips">
                                        @forelse ($characters as $name) <span class="chip">{{ $name }}</span> @empty <span class="faint small">—</span> @endforelse
                                    </div>
                                </div>
                                <div>
                                    <div class="section-label" data-en="Faction">相关阵营</div>
                                    <div class="chips">
                                        @forelse ($factions as $name) <span class="chip">{{ $name }}</span> @empty <span class="faint small">—</span> @endforelse
                                    </div>
                                </div>
                                <div>
                                    <div class="section-label" data-en="Tag">建议标签</div>
                                    <div class="chips">
                                        @forelse ($tags as $name) <span class="chip">{{ $name }}</span> @empty <span class="faint small">—</span> @endforelse
                                    </div>
                                </div>
                            </div>

                            <details class="raw" style="margin-top:14px">
                                <summary>Raw Model Output // 模型原始返回</summary>
                                <pre>{{ json_encode($proposal->raw_payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                            </details>

                            @if ($proposal->review_note)
                                <div class="section-label" data-en="Review">审核记录</div>
                                <div class="faint small" style="white-space:pre-wrap">{{ $proposal->review_note }}</div>
                            @endif

                            @if ($canReview && $proposal->status->isOpen())
                                <div class="btn-row" style="margin-top:14px">
                                    <button class="btn btn--primary btn--sm" data-approve="{{ $proposal->id }}"
                                            @if ($softIssues) data-needs-overrides="1" title="需先补正字段才能采纳" @endif>
                                        采纳为新建条目
                                    </button>
                                    @if ($payload['duplicate_of'])
                                        <button class="btn btn--sm" data-merge="{{ $proposal->id }}"
                                                data-target="{{ $payload['duplicate_of']['id'] }}">
                                            合并进既有条目
                                        </button>
                                    @else
                                        <button class="btn btn--sm" data-merge="{{ $proposal->id }}">合并进指定条目</button>
                                    @endif
                                    <button class="btn btn--sm btn--danger" data-reject="{{ $proposal->id }}">驳回</button>
                                </div>
                            @elseif ($proposal->applied_event_id)
                                <div class="btn-row" style="margin-top:14px">
                                    <a class="btn btn--sm" href="{{ route('timeline.index') }}?q={{ urlencode($proposal->title) }}" target="_blank">
                                        查看已入库条目 #{{ $proposal->applied_event_id }}
                                    </a>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="empty">
                        提案队列为空。<br>
                        <span class="small">可以用上方的梳理表单，把剧情原文或设定集段落交给 AI 先做一轮粗筛。</span>
                    </div>
                @endforelse
            </div>

            {{ $proposals->links() }}
        </div>
    </main>
@endsection
