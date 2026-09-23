@extends('layouts.app')

@section('title', $source->name.' · 出处 · 明日方舟时间线')
@section('page', 'sources')

@section('content')
    <div style="flex:1;min-width:0">
        <div class="panel">
            <div class="panel__title">
                <span data-en="Source">{{ $source->name }}</span>
                <span class="badge">{{ $source->type->label() }}{{ $source->code ? ' · '.$source->code : '' }}</span>
            </div>

            <dl class="kv">
                <dt>收录条目</dt><dd class="mono">{{ $source->events->count() }} 条</dd>
                <dt>现实发布序</dt><dd class="mono">{{ $source->release_order }}{{ $source->release_date ? ' · '.$source->release_date : '' }}</dd>
            </dl>
        </div>

        <div class="panel">
            <div class="panel__title">
                <span data-en="Corpus">出处信息与原文语料</span>
                @auth
                    @if (auth()->user()->canEditEvents())
                        <span class="faint small mono">WARN // 覆盖原文会使既有引用的字符偏移失效</span>
                    @endif
                @endauth
            </div>

            @auth
                @if (auth()->user()->canEditEvents())
                    <form id="source-form" data-source-id="{{ $source->id }}">
                        <div class="row">
                            <div class="field"><label>名称</label><input name="name" value="{{ $source->name }}"></div>
                            <div class="field" style="max-width:150px"><label>编号</label><input name="code" value="{{ $source->code }}"></div>
                            <div class="field" style="max-width:170px"><label>章节</label><input name="chapter" value="{{ $source->chapter }}"></div>
                            <div class="field" style="max-width:110px"><label>发布序</label><input type="number" name="release_order" value="{{ $source->release_order }}"></div>
                        </div>
                        <div class="field"><label>说明</label><input name="description" value="{{ $source->description }}"></div>
                        <div class="field">
                            <label>原文语料（AI 梳理的输入 · 引用定位的基准）</label>
                            <textarea name="raw_text" style="min-height:260px"
                                      placeholder="粘贴该出处的剧情原文 / 设定集段落…">{{ $source->raw_text }}</textarea>
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn btn--primary">保存</button>
                        </div>
                    </form>

                    <div class="section-label" data-en="Synthesize">用这段原文触发 AI 梳理</div>
                    <form id="synthesize-form" data-source-id="{{ $source->id }}">
                        <input type="hidden" name="source_id" value="{{ $source->id }}">
                        <div class="row">
                            <div class="field">
                                <label>目标纪元</label>
                                <select name="era_id">
                                    <option value="">（不限）</option>
                                    @foreach ($eras as $era)
                                        <option value="{{ $era->id }}">{{ $era->name }} · {{ $era->date_label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label>额外要求（可选）</label>
                                <input type="text" name="instruction" placeholder="例如：只抽取与整合运动相关的事件">
                            </div>
                        </div>
                        <div class="field">
                            <label>原文（默认取上方已保存的语料，可临时覆盖）</label>
                            <textarea name="raw_text" style="min-height:120px">{{ $source->raw_text }}</textarea>
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn btn--primary" id="synthesize-submit"><x-icon name="spark"/>开始梳理</button>
                            <span class="faint small">梳理结果会进入 <a href="{{ route('proposals.index') }}">AI 审核台</a> 等待人工放行。</span>
                        </div>
                    </form>
                @else
                    <div class="quote">{{ $source->raw_text ?: '（暂无原文语料）' }}</div>
                @endif
            @else
                <div class="quote">{{ $source->raw_text ?: '（暂无原文语料）' }}</div>
            @endauth
        </div>

        <div class="panel">
            <div class="panel__title">
                <span data-en="Entries">该出处记录的条目</span>
                <span class="faint small mono">CNT {{ str_pad($source->events->count(), 2, '0', STR_PAD_LEFT) }}</span>
            </div>

            @forelse ($source->events as $event)
                <div class="card">
                    <div class="row" style="align-items:baseline">
                        <span class="mono small" style="color:var(--accent)">{{ $event->date_display }}</span>
                        <strong>{{ $event->title }}</strong>
                        <span class="badge {{ $event->status->badgeClass() }}"><x-icon name="{{ $event->status->icon() }}" class="icon--sm"/>{{ $event->status->label() }}</span>
                        @if ($event->date_confidence !== \App\Enums\DateConfidence::Confirmed)
                            <span class="badge badge--warn"><x-icon name="{{ $event->date_confidence->icon() }}" class="icon--sm"/>{{ $event->date_confidence->label() }}</span>
                        @endif
                        @php $pivot = $event->pivot; @endphp
                        {{-- 章节 / 关卡号：告诉审核人该去出处的哪个位置核对 --}}
                        @if ($pivot?->chapter)
                            <span class="chip">{{ $pivot->chapter }}</span>
                        @endif
                        @if ($pivot?->stage_code)
                            <span class="chip mono">{{ $pivot->stage_code }}</span>
                        @endif
                    </div>
                    <p class="muted small" style="margin:6px 0 0">{{ $event->summary }}</p>
                    @if ($pivot?->quote)
                        <div class="quote" style="margin-top:8px">{{ $pivot->quote }}</div>
                        {{-- 引文能不能在语料里定位，就是 L3 闸门的结论。不给出来的话，
                             读者无从分辨这段话是原文还是转述 —— 而「分不清」正是
                             考据场景里最不该出现的状态。 --}}
                        <div class="faint small mono" style="margin-top:4px">
                            @if ($pivot->source_line > 0)
                                原文第 {{ $pivot->source_line }} 行 · 字符偏移 {{ $pivot->quote_offset }}
                            @else
                                未能在原文中定位 —— 引文与语料不一致，待核对
                            @endif
                            @if ($pivot->is_annotation)
                                · 编者按条目
                            @endif
                        </div>
                    @else
                        {{-- 引文缺失是**真实的待办状态**，必须显式呈现而不是留白。
                             留白会让人误以为「已核对过、无引文可引」。 --}}
                        <div class="faint small" style="margin-top:8px">
                            该出处尚未附引文 —— 待录入原文后补齐，或直接标注说明依据。
                        </div>
                    @endif
                </div>
            @empty
                <div class="empty">
                    该出处还没有任何条目。<br>
                    <span class="small">可以先用上面的梳理表单跑一轮，产出候选提案。</span>
                </div>
            @endforelse
        </div>
    </div>
@endsection
