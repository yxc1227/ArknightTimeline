/* ==========================================================================
   明日方舟时间线 · 前端逻辑
   无构建步骤（容器内没有 Node），因此使用原生 ES2020，通过 <body data-page> 分派页面。
   ========================================================================== */

(function () {
    'use strict';

    const APP = window.APP || {};
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const PAGE = document.body.dataset.page || '';

    /* ------------------------------------------------------------------ 基础工具 */

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));

    const num = (n) => new Intl.NumberFormat('zh-CN').format(n ?? 0);

    function debounce(fn, wait = 260) {
        let timer = null;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), wait);
        };
    }

    /**
     * 统一请求封装。
     * 刻意不抛异常：409（冲突）和 403（拒绝写入）都是**业务分支**而非错误，
     * 调用方需要拿到响应体里的字段级 diff，而不是被 try/catch 吞掉。
     */
    async function api(url, opts = {}) {
        const res = await fetch(url, {
            method: opts.method || 'GET',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF,
                ...(opts.headers || {}),
            },
            body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
            credentials: 'same-origin',
        });

        let data = null;
        try { data = await res.json(); } catch (_) { /* 204 / 非 JSON 响应 */ }

        return { ok: res.ok, status: res.status, data: data || {} };
    }

    function toast(message, kind = 'info', title = null) {
        const host = $('#toasts');
        if (!host) { console.log(message); return; }

        const node = document.createElement('div');
        node.className = `toast toast--${kind}`;
        node.innerHTML = (title ? `<div class="toast__title">${esc(title)}</div>` : '') + esc(message);
        host.appendChild(node);
        setTimeout(() => node.remove(), kind === 'danger' ? 9000 : 5200);
    }

    /** 把后端返回的 422 校验错误摊平成人话。 */
    function validationMessage(payload) {
        if (!payload) return '请求失败。';
        if (payload.message) return payload.message;
        const errors = payload.errors || {};
        return Object.values(errors).flat().join(' ') || '请求失败。';
    }

    function uri(base, ...parts) {
        const clean = (base || '').replace(/\/+$/, '');
        return [clean, ...parts.map((p) => String(p).replace(/^\/+/, ''))].join('/');
    }

    /** 固定宽度编号：官方站的计数一律补零成两位（00 // 00 / 05）。 */
    const pad = (value, width = 2) => String(value ?? 0).padStart(width, '0');

    /**
     * 时间戳格式化为「2026 // 09 / 23 15:04」。
     *
     * 注意：这个 `//` 日期母题只用于**元数据**（版本时间、标注时间、公告时间）。
     * 条目的游戏内纪年（date_display）永远原样输出，绝不做任何格式化 ——
     * 泰拉历的粒度本身就是信息，重排它会伪造精度。
     */
    const stamp = (iso) => {
        if (!iso) return '—';

        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) return '—';

        return `${date.getFullYear()} // ${pad(date.getMonth() + 1)} / ${pad(date.getDate())} `
            + `${pad(date.getHours())}:${pad(date.getMinutes())}`;
    };

    /**
     * 触发 AI 梳理。审核台与出处详情页共用同一段逻辑 ——
     * 两处入口的检验规则（必填、持久化原文、结果去向）必须完全一致，
     * 否则用户会在不同入口得到不同行为。
     */
    function bindSynthesize(form) {
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const btn = form.querySelector('[type="submit"]');
            const original = btn.textContent;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> 正在梳理…';

            const payload = {
                source_id: form.source_id?.value || form.dataset.sourceId || null,
                era_id: form.era_id?.value || null,
                raw_text: form.raw_text?.value.trim() || null,
                instruction: form.instruction?.value.trim() || null,
                persist_raw_text: form.persist_raw_text?.checked ?? false,
            };

            const { ok, data } = await api(APP.urls.synthesize, { method: 'POST', body: payload });

            btn.disabled = false;
            btn.textContent = original;

            if (!ok) { toast(data.message || validationMessage(data), 'danger', '梳理失败'); return; }

            toast(data.message, 'ok', `梳理完成（${data.driver}${data.model ? ' · ' + data.model : ''}）`);
            setTimeout(() => { location.href = APP.urls.proposals; }, 1200);
        });
    }

    /* ------------------------------------------------------------------ 时间线页 */

    const Timeline = (() => {
        const urls = APP.urls || {};
        const options = APP.options || {};

        const state = {
            filters: {},
            page: 1,
            perPage: Number(APP.perPage || 40),
            events: [],
            total: 0,
            hasMore: false,
            scale: {},
            unanchored: 0,
            loading: false,
            current: null,      // 抽屉中打开的条目
            lockToken: null,
            lockTimer: null,
            pendingConflict: null,
            editing: false,
        };

        /**
         * 当前世界的历法名。
         *
         * 塔卫二用塔罗斯历而非泰拉历 —— 在它的条目上写「泰拉历」是错误信息，
         * 而这个错误只会出现在提示文案里，不会有人报错，只会有人被误导。
         */
        function calendar() {
            return APP.worldCalendar || '泰拉历';
        }

        /* ---------------- 数据 */

        function queryString() {
            const params = new URLSearchParams();
            const f = state.filters;

            Object.entries(f).forEach(([key, value]) => {
                if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) return;
                if (Array.isArray(value)) value.forEach((v) => params.append(`${key}[]`, v));
                else params.set(key, String(value));
            });

            /*
             * 世界是**页面级上下文**，不是可清除的筛选项。
             *
             * 因此它不放进 state.filters（那会被「重置」按钮抹掉，
             * 导致用户在塔卫二视图里点一下重置就被送回泰拉），
             * 而是每次请求都显式带上。
             */
            if (APP.world) params.set('world', APP.world);

            params.set('page', String(state.page));
            params.set('per_page', String(state.perPage));
            return params.toString();
        }

        async function load({ append = false } = {}) {
            if (state.loading) return;
            state.loading = true;
            renderLoading();

            const { ok, data } = await api(`${urls.timeline}?${queryString()}`);

            state.loading = false;

            if (!ok) {
                toast('时间线加载失败，请稍后重试。', 'danger');
                renderTimeline();
                return;
            }

            state.events = append ? state.events.concat(data.data) : data.data;
            state.total = data.meta.total;
            state.hasMore = data.meta.has_more;
            state.scale = data.scale || state.scale;
            state.unanchored = data.unanchored_count || 0;

            renderTimeline();
            renderScrubber();
        }

        function renderLoading() {
            const host = $('#timeline-host');
            if (!host || state.events.length) return;
            // 「LOADING ......」的加载语汇同样来自官方站的系统化文案习惯
            host.innerHTML = '<div class="load-more"><span class="spinner"></span> LOADING ...... 正在检索</div>';
        }

        /* ---------------- 渲染 */

        function renderTimeline() {
            const host = $('#timeline-host');
            if (!host) return;

            const stats = $('#stats-line');
            if (stats) {
                // 编号 / 计数仪表：TOTAL 51 // SHOWN 40 // UNDATED 03
                stats.innerHTML = `<span>TOTAL ${num(state.total)}</span>`
                    + `<span>SHOWN ${num(state.events.length)}</span>`
                    + (state.unanchored ? `<span>UNDATED ${pad(state.unanchored)}</span>` : '');
            }

            if (!state.events.length) {
                host.innerHTML = '<div class="empty">没有符合条件的条目。<br>'
                    + '<span class="small">可以放宽筛选条件，或用「AI 梳理」从剧情原文里补齐。</span></div>';
                return;
            }

            // 分组：按纪元顺序，未归属纪元与「时间未定」各自成组
            const eraOrder = (options.eras || []).map((e) => e.id);
            const groups = new Map();

            state.events.forEach((event) => {
                const key = event.date.precision === 'unknown'
                    ? 'unanchored'
                    : (event.era ? `era:${event.era.id}` : 'no-era');

                if (!groups.has(key)) groups.set(key, []);
                groups.get(key).push(event);
            });

            const sortedKeys = [...groups.keys()].sort((a, b) => {
                if (a === 'unanchored') return 1;
                if (b === 'unanchored') return -1;
                if (a === 'no-era') return 1;
                if (b === 'no-era') return -1;
                return eraOrder.indexOf(Number(a.split(':')[1])) - eraOrder.indexOf(Number(b.split(':')[1]));
            });

            host.innerHTML = sortedKeys.map((key) => {
                const items = groups.get(key);

                if (key === 'unanchored') {
                    return band('时间未定', `未能在文本中定位到${calendar()}区间，等待人工补全`, '#5c7189', items.length)
                        + items.map(card).join('');
                }

                if (key === 'no-era') {
                    return band('未归属纪元', '尚未挂载到任何时期', '#5c7189', items.length)
                        + items.map(card).join('');
                }

                const era = items[0].era;
                return band(era.name, `${era.date_label}${era.subtitle ? ' · ' + era.subtitle : ''}`, era.color, items.length)
                    + items.map(card).join('');
            }).join('') + (state.hasMore
                ? `<div class="load-more"><button class="btn" id="load-more">加载更多（剩余 ${num(state.total - state.events.length)} 条）</button></div>`
                : '');
        }

        function band(name, range, color, count) {
            return `<div class="era-band">
                <span class="era-band__bar" style="background:${esc(color)}"></span>
                <span class="era-band__name">${esc(name)}</span>
                <span class="era-band__range">${esc(range)}</span>
                <span class="era-band__count">CNT ${pad(count)}</span>
            </div><div class="tl">`;
        }

        function card(event) {
            const hasAnomaly = (event.anomalies_count || 0) > 0;
            const anchored = event.date.precision !== 'unknown';

            const chips = [];
            (event.sources || []).slice(0, 2).forEach((s) => chips.push(
                `<span class="chip"><span class="chip__dot" style="background:#38bdf8"></span>${esc(s.name)}${s.stage_code ? ' · ' + esc(s.stage_code) : ''}</span>`
            ));
            (event.factions || []).slice(0, 3).forEach((f) => chips.push(
                `<span class="chip"><span class="chip__dot" style="background:${esc(f.color)}"></span>${esc(f.name)}</span>`
            ));
            (event.characters || []).slice(0, 4).forEach((c) => chips.push(
                `<span class="chip"><span class="chip__dot"></span>${esc(c.name)}</span>`
            ));

            return `<div class="tl-item" data-confidence="${esc(event.date.confidence)}">
                <div class="tl-card" data-id="${event.id}" data-anchored="${anchored ? 1 : 0}"
                     data-anomaly="${hasAnomaly ? 1 : 0}" data-frozen="${event.status.frozen ? 1 : 0}">
                    <div class="tl-card__head">
                        <span class="tl-card__date">${esc(event.date.display)}</span>
                        <span class="tl-card__title">${esc(event.title)}</span>
                        <span class="badge ${esc(event.status.badge)}">${esc(event.status.label)}</span>
                        ${event.date.precision !== 'day' ? `<span class="badge badge--muted">${esc(event.date.precision_label)}</span>` : ''}
                        ${event.date.confidence !== 'confirmed' ? `<span class="badge badge--warn">${esc(event.date.confidence_label)}</span>` : ''}
                        ${hasAnomaly ? `<span class="badge badge--danger">异常 ${pad(event.anomalies_count)}</span>` : ''}
                        ${event.is_locked ? '<span class="badge badge--muted">已锁定</span>' : ''}
                    </div>
                    <p class="tl-card__summary">${esc(event.summary)}</p>
                    <div class="tl-card__meta">
                        ${event.location ? `<span>LOC ${esc(event.location)}</span>` : ''}
                        <span>VER ${pad(event.version)}</span>
                        ${event.annotations_count ? `<span>NOTE ${pad(event.annotations_count)}</span>` : ''}
                    </div>
                    ${chips.length ? `<div class="chips" style="margin-top:7px">${chips.join('')}</div>` : ''}
                </div>
            </div>`;
        }

        /* ---------------- 缩放条（时间轴概览） */

        function renderScrubber() {
            const canvas = $('#scrubber');
            if (!canvas || !state.scale.buckets) return;

            const dpr = window.devicePixelRatio || 1;
            const width = canvas.clientWidth || 600;
            const height = 46;
            canvas.width = width * dpr;
            canvas.height = height * dpr;

            const ctx = canvas.getContext('2d');
            ctx.scale(dpr, dpr);
            ctx.clearRect(0, 0, width, height);

            const buckets = Object.entries(state.scale.buckets)
                .map(([year, total]) => ({ year: Number(year), total: Number(total) }))
                .filter((b) => b.year > 0)
                .sort((a, b) => a.year - b.year);

            if (!buckets.length) return;

            const min = buckets[0].year;
            const max = buckets[buckets.length - 1].year;
            const span = Math.max(1, max - min);
            const peak = Math.max(...buckets.map((b) => b.total));
            const barWidth = Math.max(2, width / (span + 1));
            // 与后端 TerraDate::DAYS_PER_YEAR 保持一致，避免把网格常数散落在两处
            const daysPerYear = state.scale.days_per_year || 372;

            // 纪元色带：纪元颜色来自数据库（已按黄→红「递进」渐变重新配过色）
            (options.era_bands || []).forEach((eraBand) => {
                const from = Math.floor(eraBand.start_index / daysPerYear);
                const to = Math.ceil(eraBand.end_index / daysPerYear);
                const x = ((from - min) / span) * width;
                const w = Math.max(1, ((to - from) / span) * width);
                ctx.fillStyle = eraBand.color + '1f';
                ctx.fillRect(x, 0, w, height);

                // 纪元分界刻度
                ctx.fillStyle = eraBand.color + '55';
                ctx.fillRect(x, 0, 1, height);
            });

            // 年度分布：常态灰柱，只有明显的高峰才用标志黄点出来
            buckets.forEach((b) => {
                const x = ((b.year - min) / span) * width;
                const intensity = b.total / peak;
                const h = Math.max(2, intensity * (height - 14));
                ctx.fillStyle = intensity >= 0.75 ? '#ffd400' : '#3f3f3f';
                ctx.fillRect(x, height - h, Math.max(1, barWidth - 1), h);
            });

            // 已选区间
            if (state.selection) {
                const [from, to] = state.selection;
                const x1 = ((from - min) / span) * width;
                const x2 = ((to - min) / span) * width;
                ctx.fillStyle = 'rgba(255,212,0,.16)';
                ctx.fillRect(Math.min(x1, x2), 0, Math.abs(x2 - x1), height);
                ctx.strokeStyle = '#ffd400';
                ctx.strokeRect(Math.min(x1, x2) + .5, .5, Math.abs(x2 - x1) - 1, height - 1);
            }

            canvas.dataset.min = String(min);
            canvas.dataset.max = String(max);
        }

        function bindScrubber() {
            const canvas = $('#scrubber');
            if (!canvas) return;

            let dragging = false;

            const yearAt = (clientX) => {
                const rect = canvas.getBoundingClientRect();
                const min = Number(canvas.dataset.min || 1000);
                const max = Number(canvas.dataset.max || 1101);
                const ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
                return Math.round(min + ratio * (max - min));
            };

            // 年 → TerraDate 网格索引，往返必须与后端 toIndex/yearBounds 完全一致
            const toIndex = (year, end = false) => {
                const daysPerYear = state.scale.days_per_year || 372;
                return year * daysPerYear + (end ? daysPerYear - 1 : 0);
            };

            canvas.addEventListener('mousedown', (e) => {
                dragging = true;
                state.selection = [yearAt(e.clientX), yearAt(e.clientX)];
            });

            window.addEventListener('mousemove', (e) => {
                if (!dragging) return;
                state.selection = [state.selection[0], yearAt(e.clientX)];
                renderScrubber();
            });

            window.addEventListener('mouseup', () => {
                if (!dragging) return;
                dragging = false;

                if (!state.selection) return;

                const [a, b] = state.selection;
                const from = Math.min(a, b);
                const to = Math.max(a, b);

                state.filters.from_index = toIndex(from);
                state.filters.to_index = toIndex(to, true);

                $('#range-label').textContent = `${from} — ${to} 年`;
                reset();
            });

            $('#clear-range')?.addEventListener('click', () => {
                delete state.filters.from_index;
                delete state.filters.to_index;
                state.selection = null;
                $('#range-label').textContent = '全时段';
                renderScrubber();
                reset();
            });
        }

        /* ---------------- 筛选 */

        function reset() {
            state.page = 1;
            state.events = [];
            load();
        }

        function readFilters() {
            const sidebar = $('#filters');
            if (!sidebar) return;

            const next = {};

            $$('[data-filter]', sidebar).forEach((input) => {
                const key = input.dataset.filter;
                if (input.type === 'checkbox') {
                    if (input.checked) next[key] = 1;
                    return;
                }
                if (input.tagName === 'SELECT' && input.multiple) {
                    const values = Array.from(input.selectedOptions).map((o) => o.value).filter(Boolean);
                    if (values.length) next[key] = values;
                    return;
                }
                if (String(input.value).trim() !== '') next[key] = input.value.trim();
            });

            // 保留时间轴滑选
            if (state.filters.from_index !== undefined) next.from_index = state.filters.from_index;
            if (state.filters.to_index !== undefined) next.to_index = state.filters.to_index;

            state.filters = next;
        }

        function bindFilters() {
            const sidebar = $('#filters');
            if (!sidebar) return;

            sidebar.addEventListener('change', () => { readFilters(); reset(); });
            sidebar.addEventListener('input', debounce((e) => {
                if (e.target.type === 'search' || e.target.type === 'text') { readFilters(); reset(); }
            }, 320));

            $('#filters-reset')?.addEventListener('click', () => {
                $$('[data-filter]', sidebar).forEach((input) => {
                    if (input.type === 'checkbox') input.checked = false;
                    else if (input.multiple) Array.from(input.options).forEach((o) => { o.selected = false; });
                    else input.value = '';
                });
                sidebar.querySelectorAll('details[data-collapse]').forEach((d) => { d.open = false; });
                delete state.filters.from_index;
                delete state.filters.to_index;
                state.selection = null;
                $('#range-label').textContent = '全时段';
                renderScrubber();
                readFilters();
                reset();
            });
        }

        /* ---------------- 抽屉 / 详情 */

        function openDrawer() {
            $('#drawer').classList.add('is-open');
            $('#drawer-mask').classList.add('is-open');
        }

        function closeDrawer() {
            releaseLock();
            $('#drawer').classList.remove('is-open');
            $('#drawer-mask').classList.remove('is-open');
            state.current = null;
            state.editing = false;
        }

        async function openEvent(id) {
            openDrawer();
            $('#drawer-body').innerHTML = '<div class="load-more"><span class="spinner"></span> LOADING ...... 载入条目</div>';
            $('#drawer-title').textContent = '';
            $('#drawer-date').textContent = '';
            $('#drawer-foot').innerHTML = '';

            const { ok, data } = await api(`${uri(urls.events, id)}`);

            if (!ok) {
                $('#drawer-body').innerHTML = '<div class="alert alert--danger">条目载入失败。</div>';
                return;
            }

            state.current = data;
            renderDetail();
        }

        function renderDetail() {
            const { event, annotations, revisions, lock, anomalies, permissions } = state.current;

            $('#drawer-title').textContent = event.title;
            $('#drawer-date').innerHTML = `${esc(event.date.display)} <span class="faint small">· ${esc(event.date.hint)}</span>`;

            const lockNote = lock.locked
                ? `<div class="alert alert--warn">「${esc(lock.holder || '其他编辑者')}」正在编辑该条目（剩余 ${lock.seconds_left}s）。你的保存仍会执行，但可能触发字段级冲突合并。</div>`
                : '';

            $('#drawer-body').innerHTML = lockNote + `
                <div class="tabs" id="detail-tabs">
                    <button class="is-active" data-tab="view" data-en="DETAIL">详情</button>
                    <button data-tab="edit" data-en="EDIT">编辑</button>
                    <button data-tab="notes" data-en="NOTE">标注${annotations.length ? ` ${pad(annotations.length)}` : ''}</button>
                    <button data-tab="history" data-en="HISTORY">版本 ${pad(revisions.length)}</button>
                </div>

                <div class="tabpane is-active" data-pane="view">${paneView(event, anomalies, permissions)}</div>
                <div class="tabpane" data-pane="edit">${paneEdit(event, permissions)}</div>
                <div class="tabpane" data-pane="notes">${paneNotes(annotations, permissions)}</div>
                <div class="tabpane" data-pane="history">${paneHistory(revisions)}</div>
            `;

            $('#drawer-foot').innerHTML = `
                <button class="btn btn--ghost" id="drawer-close">关闭</button>
                <div class="spacer"></div>
                ${permissions.review ? `
                    <button class="btn btn--sm" id="toggle-lock">${event.is_locked ? '解锁条目' : '锁定条目'}</button>
                    <button class="btn btn--sm" id="mark-verified" ${event.status.value === 'verified' ? 'disabled' : ''}>标记已校验</button>
                ` : ''}
                ${permissions.update ? '<button class="btn btn--primary btn--sm" id="goto-edit">编辑条目</button>' : ''}
            `;

            bindDetailEvents();
        }

        function paneView(event, anomalies, permissions) {
            const sources = (event.sources || []).map((s) => {
                const locator = [s.type_label, s.code, s.chapter, s.stage_code]
                    .filter(Boolean)
                    .map((part) => esc(part))
                    .join(' // ');

                return `<div class="card">
                    <div class="row" style="align-items:baseline">
                        <strong>${esc(s.name)}</strong>
                        ${s.is_primary ? '<span class="badge badge--ok">主要出处</span>' : ''}
                    </div>
                    <div class="faint small mono" style="margin-top:3px">${locator}</div>
                    ${s.quote
                        ? `<div class="quote" style="margin-top:7px">${esc(s.quote)}</div>`
                        // 引文缺失是真实的待办状态，必须显式说明，而不是留白
                        : '<div class="faint small" style="margin-top:7px">该出处尚未附引文 —— 待录入原文后补齐，或直接标注说明依据。</div>'}
                </div>`;
            }).join('') || '<div class="faint small">尚未挂载出处。时间线的可信度取决于出处，建议补齐。</div>';

            const people = (event.characters || []).map((c) =>
                `<span class="chip">${esc(c.name)}<span class="faint">·${esc(c.role)}</span></span>`
            ).join('') || '<span class="faint small">—</span>';

            const factions = (event.factions || []).map((f) =>
                `<span class="chip"><span class="chip__dot" style="background:${esc(f.color)}"></span>${esc(f.name)}<span class="faint">·${esc(f.role)}</span></span>`
            ).join('') || '<span class="faint small">—</span>';

            const tags = (event.tags || []).map((t) =>
                `<span class="chip" style="border-color:${esc(t.color)}55">${esc(t.name)}</span>`
            ).join('') || '<span class="faint small">—</span>';

            const anomalyBlock = (anomalies || []).length
                ? '<div class="section-label" data-en="CONSISTENCY">一致性告警</div>' + anomalies.map((a) => `
                    <div class="alert ${a.severity === 'error' ? 'alert--danger' : 'alert--warn'}">
                        <span class="badge ${a.severity === 'error' ? 'badge--danger' : 'badge--warn'}">${esc(a.type_label)}</span>
                        ${esc(a.message)}
                    </div>`).join('')
                : '';

            return `
                ${anomalyBlock}
                <dl class="kv">
                    <dt>游戏内纪元</dt><dd class="mono">${esc(event.date.display)} <span class="faint">（${esc(event.date.precision_label)} · ${esc(event.date.confidence_label)}）</span></dd>
                    <dt>所属纪元</dt><dd>${event.era ? `${esc(event.era.name)} <span class="faint small">${esc(event.era.date_label)}</span>` : '<span class="faint">未归属</span>'}</dd>
                    <dt>发生地</dt><dd>${esc(event.location || '—')}</dd>
                    <dt>状态</dt><dd><span class="badge ${esc(event.status.badge)}">${esc(event.status.label)}</span> ${event.is_locked ? '<span class="badge badge--muted">已锁定</span>' : ''}</dd>
                    <dt>版本</dt><dd class="mono">VER ${pad(event.version)} // ${esc(stamp(event.updated_at))}</dd>
                </dl>

                <div class="section-label" data-en="DESCRIPTION">简要描述</div>
                <p style="margin:0">${esc(event.summary)}</p>

                ${event.details ? `<div class="section-label" data-en="DETAILS">详述</div><p style="margin:0;white-space:pre-wrap">${esc(event.details)}</p>` : ''}

                <div class="section-label" data-en="SOURCE">出处来源</div>
                ${sources}

                <div class="section-label" data-en="CHARACTER">相关人物</div>
                <div class="chips">${people}</div>

                <div class="section-label" data-en="FACTION">相关阵营</div>
                <div class="chips">${factions}</div>

                <div class="section-label" data-en="TAG">标签</div>
                <div class="chips">${tags}</div>
            `;
        }

        function paneEdit(event, permissions) {
            if (!permissions.update) {
                return `<div class="alert alert--info">
                    你当前没有该条目的编辑权限（${event.status.frozen ? '条目处于冻结状态，' : ''}可能需要在<a href="${uri(urls.events, event.id)}" target="_blank">登录</a>后由对应出处的负责人处理）。
                    你仍然可以在「标注」页提交纠错建议，审核员会看到。
                </div>`;
            }

            const sourceOptions = (options.sources || []).map((s) =>
                `<option value="${s.id}">${esc(s.name)}${s.code ? ' · ' + esc(s.code) : ''}</option>`).join('');

            const sourceRows = (event.sources || []).map((s) => sourceRow(s, sourceOptions)).join('');
            const characterRows = (event.characters || []).map((c) => characterRow(c)).join('');
            const factionRows = (event.factions || []).map((f) => factionRow(f)).join('');

            const eraOptions = (options.eras || []).map((e) =>
                `<option value="${e.id}" ${event.era && event.era.id === e.id ? 'selected' : ''}>${esc(e.name)}</option>`).join('');

            return `
                <div class="alert alert--info small">
                    保存时会携带版本号 VER ${pad(event.version)}。若他人已抢先保存，系统不会覆盖对方的改动，
                    而是把冲突逐字段摊开让你裁决（对方的独有改动会自动并入）。
                </div>

                <form id="edit-form">
                    <div class="field"><label>事件标题 *</label><input type="text" name="title" value="${esc(event.title)}" required></div>
                    <div class="field"><label>简要描述 *</label><textarea name="summary" style="min-height:70px;font-family:inherit">${esc(event.summary)}</textarea></div>
                    <div class="field"><label>详述（可选）</label><textarea name="details">${esc(event.details || '')}</textarea></div>

                    <div class="row">
                        <div class="field">
                            <label>游戏内纪元时间（原文）*</label>
                            <input type="text" name="date_display" value="${esc(event.date.display)}" placeholder="${esc(calendar())}1096年12月23日 / 1097年冬 / 时间未定">
                            <div class="faint small" style="margin-top:4px">保留原始表述，系统会自动解析排序区间；无法解析的会归入「时间未定」。</div>
                        </div>
                        <div class="field" style="max-width:150px">
                            <label>时间精度</label>
                            <select name="date_precision">
                                ${Object.entries(options.enums?.precisions || {}).map(([v, l]) =>
                                    `<option value="${v}" ${event.date.precision === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="field" style="max-width:150px">
                            <label>可信度</label>
                            <select name="date_confidence">
                                ${Object.entries(options.enums?.confidences || {}).map(([v, l]) =>
                                    `<option value="${v}" ${event.date.confidence === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="field">
                            <label>所属纪元</label>
                            <select name="era_id"><option value="">（未归属）</option>${eraOptions}</select>
                        </div>
                        <div class="field">
                            <label>发生地</label>
                            <input type="text" name="location" value="${esc(event.location || '')}">
                        </div>
                        <div class="field" style="max-width:120px">
                            <label>同日排序</label>
                            <input type="number" name="sort_seq" value="${event.sort_seq ?? 0}">
                        </div>
                    </div>

                    <div class="section-label" data-en="SOURCE">出处来源</div>
                    <div id="source-rows">${sourceRows}</div>
                    <button type="button" class="btn btn--sm" data-add="source">+ 添加出处</button>

                    <div class="section-label" data-en="CHARACTER">相关人物</div>
                    <div id="character-rows">${characterRows}</div>
                    <button type="button" class="btn btn--sm" data-add="character">+ 添加人物</button>

                    <div class="section-label" data-en="FACTION">相关阵营</div>
                    <div id="faction-rows">${factionRows}</div>
                    <button type="button" class="btn btn--sm" data-add="faction">+ 添加阵营</button>

                    <div class="section-label" data-en="TAG">标签</div>
                    <div class="field">
                        <input type="text" name="tags" value="${esc((event.tags || []).map((t) => t.name).join(', '))}" placeholder="用逗号分隔，例如：战役, 政变">
                    </div>

                    <div class="field">
                        <label>本次编辑备注（写入版本历史）</label>
                        <input type="text" name="comment" placeholder="例如：依据 7-18 关卡文本修正年份">
                    </div>

                    <div class="btn-row">
                        <button type="submit" class="btn btn--primary">保存</button>
                        <button type="button" class="btn" id="cancel-edit">取消</button>
                    </div>
                </form>
            `;
        }

        function sourceRow(source, optionsHtml) {
            return `<div class="card" data-row="source">
                <div class="row">
                    <div class="field"><label>出处</label>
                        <select data-name="id"><option value="">（选择出处）</option>${optionsHtml}</select>
                    </div>
                    <div class="field" style="max-width:130px"><label>关卡号</label><input data-name="stage_code" value="${esc(source?.stage_code || '')}"></div>
                    <div class="field" style="max-width:120px"><label>章节</label><input data-name="chapter" value="${esc(source?.chapter || '')}"></div>
                </div>
                <div class="field"><label>原文引证</label><textarea data-name="quote" style="min-height:52px">${esc(source?.quote || '')}</textarea></div>
                <div class="btn-row">
                    <label class="check"><input type="checkbox" data-name="is_primary" ${source?.is_primary ? 'checked' : ''}> 主要出处</label>
                    <button type="button" class="btn btn--sm btn--danger" data-remove>移除</button>
                </div>
            </div>`;
        }

        function characterRow(character) {
            return `<div class="card" data-row="character">
                <div class="row">
                    <div class="field"><label>人物</label><input data-name="name" list="character-list" value="${esc(character?.name || '')}"></div>
                    <div class="field" style="max-width:150px"><label>角色</label>
                        <select data-name="role">
                            ${['protagonist', 'support', 'mentioned'].map((r) =>
                                `<option value="${r}" ${character?.role === r ? 'selected' : ''}>${r}</option>`).join('')}
                        </select>
                    </div>
                    <div style="flex:0 0 auto;display:flex;align-items:flex-end">
                        <button type="button" class="btn btn--sm btn--danger" data-remove>移除</button>
                    </div>
                </div>
            </div>`;
        }

        function factionRow(faction) {
            return `<div class="card" data-row="faction">
                <div class="row">
                    <div class="field"><label>阵营</label><input data-name="name" list="faction-list" value="${esc(faction?.name || '')}"></div>
                    <div class="field" style="max-width:150px"><label>立场</label>
                        <select data-name="role">
                            ${['instigator', 'involved', 'victim'].map((r) =>
                                `<option value="${r}" ${faction?.role === r ? 'selected' : ''}>${r}</option>`).join('')}
                        </select>
                    </div>
                    <div style="flex:0 0 auto;display:flex;align-items:flex-end">
                        <button type="button" class="btn btn--sm btn--danger" data-remove>移除</button>
                    </div>
                </div>
            </div>`;
        }

        function paneNotes(annotations, permissions) {
            const list = annotations.map((a) => `
                <div class="card">
                    <div class="row" style="align-items:baseline">
                        <span class="badge badge--info">${esc(a.type_label)}</span>
                        <span class="faint small mono">${esc(a.author || '匿名访客')} // ${esc(stamp(a.created_at))}</span>
                        <span class="badge ${a.status === 'open' ? 'badge--warn' : 'badge--ok'}">${esc(a.status)}</span>
                    </div>
                    ${a.field ? `<div class="faint small mono" style="margin-top:4px">FIELD // ${esc(a.field)}</div>` : ''}
                    <div style="margin-top:6px;white-space:pre-wrap">${esc(a.body)}</div>
                    ${permissions.review && a.status === 'open' ? `
                        <div class="btn-row" style="margin-top:8px">
                            <button class="btn btn--sm btn--ok" data-resolve="${a.id}" data-status="accepted">采纳</button>
                            <button class="btn btn--sm" data-resolve="${a.id}" data-status="rejected">驳回</button>
                        </div>` : ''}
                </div>
            `).join('') || '<div class="faint small">暂无标注。</div>';

            return `
                <form id="annotation-form">
                    <div class="row">
                        <div class="field" style="max-width:150px"><label>类型</label>
                            <select name="type">
                                <option value="comment">备注</option>
                                <option value="correction">纠错</option>
                                <option value="question">存疑</option>
                                <option value="verification">复核</option>
                            </select>
                        </div>
                        <div class="field"><label>指向字段（可选）</label>
                            <input name="field" placeholder="例如：start_index / summary">
                        </div>
                    </div>
                    <div class="field"><label>内容 *</label><textarea name="body" placeholder="指出问题所在，并尽量给出依据（关卡号 / 设定集页码）"></textarea></div>
                    <button type="submit" class="btn btn--primary btn--sm">提交标注</button>
                    <span class="faint small" style="margin-left:8px">标注不需要编辑权限，访客也可提交。</span>
                </form>
                <div class="section-label" data-en="RECORD">标注记录</div>
                ${list}
            `;
        }

        function paneHistory(revisions) {
            if (!revisions.length) return '<div class="faint small">暂无版本记录。</div>';

            return '<div class="faint small" style="margin-bottom:10px">版本链只增不改：回滚会生成一个新版本，历史永远保留。</div>'
                + revisions.map((r) => `
                <div class="card">
                    <div class="row" style="align-items:baseline">
                        <span class="mono" style="color:var(--accent)">VER ${pad(r.version)}</span>
                        <strong>${esc(r.action_label)}</strong>
                        <span class="badge ${r.origin === 'ai' ? 'badge--info' : 'badge--muted'}">${esc(r.origin_label)}</span>
                        <span class="faint small mono">${esc(r.author || '—')} // ${esc(stamp(r.created_at))}</span>
                    </div>
                    ${r.changed_fields?.length ? `<div class="chips" style="margin-top:7px">${r.changed_fields.map((f) => `<span class="chip">${esc(f)}</span>`).join('')}</div>` : ''}
                    ${r.comment ? `<div class="faint small" style="margin-top:7px">${esc(r.comment)}</div>` : ''}
                    ${state.current.permissions.revert ? `<button class="btn btn--sm" style="margin-top:9px" data-revert="${r.version}">回滚到此版本</button>` : ''}
                </div>`).join('');
        }

        function bindDetailEvents() {
            const body = $('#drawer-body');

            body.addEventListener('click', async (e) => {
                const tab = e.target.closest('#detail-tabs button');
                if (tab) {
                    $$('#detail-tabs button', body).forEach((b) => b.classList.toggle('is-active', b === tab));
                    $$('.tabpane', body).forEach((p) => p.classList.toggle('is-active', p.dataset.pane === tab.dataset.tab));
                    if (tab.dataset.tab === 'edit') acquireLock();
                    return;
                }

                const addBtn = e.target.closest('[data-add]');
                if (addBtn) {
                    const kind = addBtn.dataset.add;
                    const host = $(`#${kind}-rows`);
                    const sourceOptions = (options.sources || []).map((s) =>
                        `<option value="${s.id}">${esc(s.name)}${s.code ? ' · ' + esc(s.code) : ''}</option>`).join('');
                    const html = kind === 'source' ? sourceRow(null, sourceOptions)
                        : kind === 'character' ? characterRow(null) : factionRow(null);
                    host.insertAdjacentHTML('beforeend', html);
                    return;
                }

                const removeBtn = e.target.closest('[data-remove]');
                if (removeBtn) {
                    removeBtn.closest('[data-row]').remove();
                    return;
                }

                const revertBtn = e.target.closest('[data-revert]');
                if (revertBtn) {
                    const version = revertBtn.dataset.revert;
                    if (!confirm(`确定回滚到 v${version}？会生成一个新的版本，历史不会被覆盖。`)) return;
                    const { ok, data } = await api(`${uri(urls.events, state.current.event.id)}/revert/${version}`, { method: 'POST' });
                    if (ok) { toast(data.message, 'ok'); refreshAfterWrite(data.event); } else { toast(validationMessage(data), 'danger'); }
                    return;
                }

                const resolveBtn = e.target.closest('[data-resolve]');
                if (resolveBtn) {
                    const { ok, data } = await api(
                        `${uri(urls.events, state.current.event.id)}/annotations/${resolveBtn.dataset.resolve}/resolve`,
                        { method: 'POST', body: { status: resolveBtn.dataset.status } },
                    );
                    if (ok) { toast(data.message, 'ok'); openEvent(state.current.event.id); } else { toast(validationMessage(data), 'danger'); }
                }
            });

            body.addEventListener('submit', async (e) => {
                if (e.target.id === 'edit-form') {
                    e.preventDefault();
                    await saveEdit(e.target);
                }

                if (e.target.id === 'annotation-form') {
                    e.preventDefault();
                    const form = e.target;
                    const payload = {
                        type: form.type.value,
                        field: form.field.value || null,
                        body: form.body.value,
                    };
                    const { ok, data } = await api(`${uri(urls.events, state.current.event.id)}/annotations`, { method: 'POST', body: payload });
                    if (ok) { toast(data.message, 'ok'); openEvent(state.current.event.id); } else { toast(validationMessage(data), 'danger'); }
                }
            });

            $('#goto-edit')?.addEventListener('click', () => {
                const tab = $('#detail-tabs button[data-tab="edit"]');
                tab?.click();
            });

            $('#toggle-lock')?.addEventListener('click', async () => {
                const { ok, data } = await api(`${uri(urls.events, state.current.event.id)}/lock-toggle`, { method: 'POST' });
                if (ok) { toast(data.message, 'ok'); openEvent(state.current.event.id); } else { toast(validationMessage(data), 'danger'); }
            });

            $('#mark-verified')?.addEventListener('click', async () => {
                const { ok, data } = await api(`${uri(urls.events, state.current.event.id)}/status`, {
                    method: 'POST',
                    body: { status: 'verified', expected_version: state.current.event.version },
                });
                if (ok) { toast(data.message, 'ok'); openEvent(state.current.event.id); } else { toast(validationMessage(data), 'danger'); }
            });
        }

        /* ---------------- 编辑租约 */

        async function acquireLock() {
            if (!state.current?.permissions.update) return;

            const { ok, data } = await api(`${uri(urls.events, state.current.event.id)}/lock`, { method: 'POST' });

            if (!ok) {
                toast(validationMessage(data), 'warn', '编辑冲突提示');
                return;
            }

            state.lockToken = data.token;
            clearInterval(state.lockTimer);
            state.lockTimer = setInterval(renewLock, 60000);
        }

        async function renewLock() {
            if (!state.lockToken || !state.current) return;
            const { ok } = await api(`${uri(urls.events, state.current.event.id)}/lock`, {
                method: 'PATCH',
                body: { token: state.lockToken },
            });
            if (!ok) { state.lockToken = null; clearInterval(state.lockTimer); }
        }

        async function releaseLock() {
            clearInterval(state.lockTimer);
            if (!state.lockToken || !state.current) return;
            await api(`${uri(urls.events, state.current.event.id)}/lock`, { method: 'DELETE', body: { token: state.lockToken } });
            state.lockToken = null;
        }

        /* ---------------- 保存与冲突合并 */

        function collectRows(kind) {
            return $$(`#${kind}-rows [data-row]`).map((row) => {
                const out = {};
                $$('[data-name]', row).forEach((input) => {
                    const name = input.dataset.name;
                    if (input.type === 'checkbox') out[name] = input.checked;
                    else if (input.value !== '') out[name] = input.value;
                });
                return out;
            }).filter((row) => row.id || row.name);
        }

        function collectForm(form) {
            const payload = {
                title: form.title.value.trim(),
                summary: form.summary.value.trim(),
                details: form.details.value.trim() || null,
                date_display: form.date_display.value.trim(),
                date_precision: form.date_precision.value,
                date_confidence: form.date_confidence.value,
                era_id: form.era_id.value || null,
                location: form.location.value.trim() || null,
                sort_seq: Number(form.sort_seq.value || 0),
                sources: collectRows('source'),
                characters: collectRows('character'),
                factions: collectRows('faction'),
                tags: form.tags.value.split(/[,，]/).map((s) => s.trim()).filter(Boolean).map((name) => ({ name })),
            };

            return payload;
        }

        async function saveEdit(form) {
            const payload = collectForm(form);
            const id = state.current.event.id;

            const { ok, status, data } = await api(uri(urls.events, id), {
                method: 'PUT',
                body: { ...payload, expected_version: state.current.event.version, comment: form.comment.value || null },
            });

            if (ok) {
                toast(data.message || '已保存。', 'ok');
                refreshAfterWrite(data.event);
                return;
            }

            if (status === 409) {
                openConflictModal(data, payload);
                return;
            }

            toast(validationMessage(data), 'danger', '保存被拒绝');
        }

        /**
         * 冲突合并面板。
         *
         * 这是整个协作模型里最关键的交互：**不丢任何一方的输入**。
         * 面板把每个打架的字段并排显示 base / theirs / mine，未打架的改动已经自动并入，
         * 用户只需要为少数几个字段做一次选择。
         */
        function openConflictModal(conflict, mine) {
            state.pendingConflict = { conflict, mine };

            const rows = (conflict.conflicts || []).map((c) => `
                <div class="head">${esc(c.label)}</div>
                <div>
                    <div class="faint small">基线 v${conflict.expected_version}</div>
                    <div class="mono small">${esc(fmt(c.base))}</div>
                </div>
                <div>
                    <div class="faint small">对方（${esc(conflict.editor || '他人')}，v${conflict.current_version}）</div>
                    <div class="mono small">${esc(fmt(c.theirs))}</div>
                    <div class="pick" style="margin-top:5px">
                        <button type="button" data-pick="${esc(c.field)}" data-value="theirs">保留对方</button>
                        <button type="button" class="is-active" data-pick="${esc(c.field)}" data-value="mine">保留我的</button>
                    </div>
                </div>
                <div style="grid-column:1 / -1">
                    <div class="faint small">我的提交</div>
                    <div class="mono small">${esc(fmt(c.mine))}</div>
                </div>
            `).join('');

            const autoMerged = Object.keys(conflict.auto_merged || {});
            const inline = (conflict.conflicts || []).length === 0;

            $('#conflict-body').innerHTML = `
                <div class="alert alert--warn">
                    在你编辑期间，${esc(conflict.editor || '其他编辑者')} 已把该条目推进到 v${conflict.current_version}。
                    系统没有覆盖任何一方的改动，下面只列出真正打架的字段。
                </div>
                ${inline
                    ? '<div class="alert alert--info">没有字段级冲突，双方改动互不重叠，可以直接合并保存。</div>'
                    : `<div class="diff" style="grid-template-columns:110px 1fr 1fr">${rows}</div>`}
                ${autoMerged.length ? `<div class="section-label">已自动并入的对方改动</div>
                    <div class="chips">${autoMerged.map((f) => `<span class="chip">${esc(f)} = ${esc(fmt(conflict.auto_merged[f]))}</span>`).join('')}</div>` : ''}
            `;

            $('#conflict-mask').classList.add('is-open');

            $('#conflict-body').onclick = (e) => {
                const btn = e.target.closest('[data-pick]');
                if (!btn) return;
                const group = btn.parentElement;
                $$('button', group).forEach((b) => b.classList.toggle('is-active', b === btn));
            };
        }

        function fmt(value) {
            if (value === null || value === undefined || value === '') return '（空）';
            if (Array.isArray(value)) return value.map((v) => v.name || v.id || JSON.stringify(v)).join('、') || '（空）';
            if (typeof value === 'object') return JSON.stringify(value);
            return String(value);
        }

        async function submitConflict() {
            const pending = state.pendingConflict;
            if (!pending) return;

            const resolutions = {};
            $$('#conflict-body [data-pick]').forEach((btn) => {
                if (btn.classList.contains('is-active')) resolutions[btn.dataset.pick] = btn.dataset.value;
            });

            const { ok, status, data } = await api(`${uri(urls.events, pending.conflict.event_id)}/resolve-conflict`, {
                method: 'POST',
                body: {
                    ...pending.mine,
                    expected_version: pending.conflict.current_version,
                    resolutions,
                },
            });

            if (ok) {
                $('#conflict-mask').classList.remove('is-open');
                state.pendingConflict = null;
                toast('冲突已解决并保存。', 'ok');
                refreshAfterWrite(data.event);
                return;
            }

            if (status === 409) {
                openConflictModal(data, pending.mine);
                toast('在你裁决期间又有人改动了该条目，请重新确认。', 'warn');
                return;
            }

            toast(validationMessage(data), 'danger');
        }

        function refreshAfterWrite(event) {
            const index = state.events.findIndex((e) => e.id === event.id);
            if (index >= 0) state.events[index] = { ...state.events[index], ...event };
            renderTimeline();
            openEvent(event.id);
        }

        /* ---------------- 新建 */

        function openCreate() {
            state.current = {
                event: {
                    id: null,
                    title: '',
                    summary: '',
                    details: '',
                    location: '',
                    date: { display: '', precision: 'day', confidence: 'inferred', hint: '' },
                    era: null,
                    sort_seq: 0,
                    status: { value: 'needs_review', label: '待校验', badge: 'badge badge--warn', frozen: false },
                    version: 0,
                    is_locked: false,
                    sources: [], characters: [], factions: [], tags: [],
                },
                annotations: [],
                revisions: [],
                lock: { locked: false },
                anomalies: [],
                permissions: { update: true, delete: true, review: APP.user?.role !== 'editor', revert: false },
            };

            openDrawer();
            $('#drawer-title').textContent = '新建事件条目';
            $('#drawer-date').textContent = 'NEW // 未保存';
            $('#drawer-body').innerHTML = `
                <div class="tabs" id="detail-tabs">
                    <button class="is-active" data-tab="edit" data-en="CREATE">新建条目</button>
                </div>
                <div class="tabpane is-active" data-pane="edit">${paneEdit(state.current.event, { update: true })}</div>
            `;
            $('#drawer-foot').innerHTML = '<button class="btn btn--ghost" id="drawer-close">关闭</button>';

            $('#drawer-close').onclick = closeDrawer;

            // 复用编辑表单事件
            const body = $('#drawer-body');
            body.addEventListener('click', (e) => {
                const addBtn = e.target.closest('[data-add]');
                if (addBtn) {
                    const kind = addBtn.dataset.add;
                    const host = $(`#${kind}-rows`);
                    const sourceOptions = (options.sources || []).map((s) =>
                        `<option value="${s.id}">${esc(s.name)}${s.code ? ' · ' + esc(s.code) : ''}</option>`).join('');
                    host.insertAdjacentHTML('beforeend', kind === 'source' ? sourceRow(null, sourceOptions)
                        : kind === 'character' ? characterRow(null) : factionRow(null));
                    return;
                }
                const removeBtn = e.target.closest('[data-remove]');
                if (removeBtn) removeBtn.closest('[data-row]').remove();
            });

            body.addEventListener('submit', async (e) => {
                if (e.target.id !== 'edit-form') return;
                e.preventDefault();

                const { ok, data } = await api(urls.events, { method: 'POST', body: collectForm(e.target) });

                if (!ok) { toast(validationMessage(data), 'danger'); return; }

                toast('条目已创建（状态：待校验）。', 'ok');
                closeDrawer();
                reset();
            });
        }

        /* ---------------- 初始化 */

        function boot() {
            if (!$('#timeline-host')) return;

            // datalist，供人物 / 阵营输入联想
            const dl = document.createElement('div');
            dl.innerHTML = `
                <datalist id="character-list">${(options.characters || []).map((c) => `<option value="${esc(c.name)}"></option>`).join('')}</datalist>
                <datalist id="faction-list">${(options.factions || []).map((f) => `<option value="${esc(f.name)}"></option>`).join('')}</datalist>
            `;
            document.body.appendChild(dl);

            bindFilters();
            bindScrubber();

            $('#timeline-host').addEventListener('click', (e) => {
                if (e.target.closest('#load-more')) {
                    state.page += 1;
                    load({ append: true });
                    return;
                }

                const card = e.target.closest('.tl-card');
                if (card) openEvent(Number(card.dataset.id));
            });

            $('#drawer-close')?.addEventListener('click', closeDrawer);
            $('#detail-close')?.addEventListener('click', closeDrawer);
            $('#drawer-mask')?.addEventListener('click', closeDrawer);
            $('#new-event')?.addEventListener('click', openCreate);
            $('#conflict-cancel')?.addEventListener('click', () => $('#conflict-mask').classList.remove('is-open'));
            $('#conflict-submit')?.addEventListener('click', submitConflict);

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    if ($('#conflict-mask')?.classList.contains('is-open')) { $('#conflict-mask').classList.remove('is-open'); return; }
                    closeDrawer();
                }
            });

            window.addEventListener('resize', debounce(renderScrubber, 200));

            load();
        }

        return { boot };
    })();

    /* ------------------------------------------------------------------ AI 审核台 */

    const Proposals = (() => {
        const urls = APP.urls || {};

        /**
         * 采纳提案，并处理两级闸门。
         *
         *  - 硬闸门（缺可定位出处）：只能靠显式免责声明放行，理由会记入审核记录；
         *  - 软闸门（时间不可解析 / 一致性阻断）：要求审核人补正字段后再放行。
         *
         * 这两条分支故意做成「不能一键跳过」，因为 AI 内容入库一旦无门槛，
         * 时间线的可信度就再也补不回来了。
         */
        async function submitApproval(id, acknowledged = false) {
            const { ok, data } = await api(`${uri(urls.proposals, id)}/approve`, {
                method: 'POST',
                body: { acknowledge_missing_evidence: acknowledged },
            });

            if (ok) {
                toast(data.message, 'ok');
                setTimeout(() => location.reload(), 700);
                return;
            }

            const hard = data.hard_issues || [];
            const soft = data.soft_issues || [];

            if (!acknowledged && hard.length) {
                const reason = prompt(
                    `该提案未通过出处校验：\n\n${hard.join('\n')}\n\n`
                    + '如果确认仍需入库（例如出处仅见于未数字化的官方设定集），请填写放行理由：',
                );

                if (!reason) return;
                await submitApproval(id, true);
                return;
            }

            if (soft.length) {
                const corrected = prompt(
                    `需补正以下问题后才能采纳：\n\n${soft.join('\n')}\n\n`
                    // 示例不带历法名：这个弹窗同时服务泰拉与塔卫二，写死哪一个都会在另一个世界里说错话
                    + '请输入修正后的「游戏内纪元时间」原文（示例：1099年12月），留空则放弃：',
                );

                if (!corrected) return;

                const retry = await api(`${uri(urls.proposals, id)}/approve`, {
                    method: 'POST',
                    body: { overrides: { date_display: corrected }, acknowledge_missing_evidence: acknowledged },
                });

                if (retry.ok) {
                    toast(retry.data.message, 'ok');
                    setTimeout(() => location.reload(), 700);
                } else {
                    toast(retry.data.message || validationMessage(retry.data), 'danger', '无法采纳');
                }

                return;
            }

            toast(data.message || validationMessage(data), 'danger', '无法采纳');
        }

        function boot() {
            const host = $('#proposals-host');
            if (!host) return;

            // 展开 / 收起提案
            host.addEventListener('click', async (e) => {
                const head = e.target.closest('.proposal__head');
                if (head && !e.target.closest('button')) {
                    head.parentElement.classList.toggle('is-open');
                    return;
                }

                const approve = e.target.closest('[data-approve]');
                if (approve) {
                    await submitApproval(approve.dataset.approve, false);
                    return;
                }

                const reject = e.target.closest('[data-reject]');
                if (reject) {
                    const note = prompt('驳回理由（会作为反馈数据保留，便于后续调优 prompt）：');
                    if (!note) return;
                    const { ok, data } = await api(`${uri(urls.proposals, reject.dataset.reject)}/reject`, { method: 'POST', body: { note } });
                    if (ok) { toast(data.message, 'ok'); setTimeout(() => location.reload(), 700); }
                    else { toast(validationMessage(data), 'danger'); }
                    return;
                }

                const merge = e.target.closest('[data-merge]');
                if (merge) {
                    const target = prompt('合并到哪条既有条目 ID？（可从提案面板的「疑似重复」提示里取）', merge.dataset.target || '');
                    if (!target) return;
                    const { ok, data } = await api(`${uri(urls.proposals, merge.dataset.merge)}/merge`, {
                        method: 'POST',
                        body: { target_event_id: Number(target) },
                    });
                    if (ok) { toast(data.message, 'ok'); setTimeout(() => location.reload(), 700); }
                    else { toast(data.message || validationMessage(data), 'danger'); }
                    return;
                }

                const bulk = e.target.closest('#bulk-approve');
                if (bulk) {
                    const ids = $$('#proposals-host input[name="pick"]:checked').map((i) => Number(i.value));
                    if (!ids.length) { toast('请先勾选要采纳的提案。', 'warn'); return; }
                    if (!confirm(`确定批量采纳 ${ids.length} 条？存在阻断级问题的提案会被跳过。`)) return;
                    const { ok, data } = await api(urls.proposalsBulk, { method: 'POST', body: { ids } });
                    toast(data.message, ok ? 'ok' : 'danger');
                    if (ok) setTimeout(() => location.reload(), 900);
                    return;
                }

                const all = e.target.closest('#pick-all');
                if (all) {
                    $$('#proposals-host input[name="pick"]').forEach((i) => { i.checked = all.checked; });
                }
            });

            bindSynthesize($('#synthesize-form'));
        }

        return { boot };
    })();

    /* ------------------------------------------------------------------ 一致性收件箱 */

    const Anomalies = (() => {
        const urls = APP.urls || {};

        function boot() {
            const host = $('#anomalies-host');
            if (!host) return;

            host.addEventListener('click', async (e) => {
                const btn = e.target.closest('[data-anomaly]');
                if (!btn) return;

                const { ok, data } = await api(`${uri(urls.anomalies, btn.dataset.anomaly)}/resolve`, {
                    method: 'POST',
                    body: { status: btn.dataset.status },
                });

                if (ok) {
                    btn.closest('tr').style.opacity = .35;
                    toast(data.message, 'ok');
                } else {
                    toast(validationMessage(data), 'danger');
                }
            });

            $('#scan')?.addEventListener('click', async (e) => {
                e.target.disabled = true;
                e.target.innerHTML = '<span class="spinner"></span> 全量体检中…';
                const { ok, data } = await api(urls.anomaliesScan, { method: 'POST' });
                e.target.disabled = false;
                e.target.textContent = '全量体检';
                toast(data.message || validationMessage(data), ok ? 'ok' : 'danger');
                if (ok) setTimeout(() => location.reload(), 1200);
            });
        }

        return { boot };
    })();

    /* ------------------------------------------------------------------ 出处 / 语料 */

    const Sources = (() => {
        const urls = APP.urls || {};

        function boot() {
            const form = $('#source-form');

            form?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const id = form.dataset.sourceId;

                const { ok, data } = await api(uri(urls.sources, id), {
                    method: 'PUT',
                    body: {
                        name: form.name.value,
                        code: form.code.value,
                        chapter: form.chapter.value,
                        release_order: Number(form.release_order.value || 0),
                        release_date: form.release_date.value,
                        description: form.description.value,
                        raw_text: form.raw_text.value,
                    },
                });

                toast(data.message || validationMessage(data), ok ? 'ok' : 'danger');
            });

            bindSynthesize($('#synthesize-form'));
        }

        return { boot };
    })();

    /* ------------------------------------------------------------------ 账号管理 */

    const Users = (() => {
        const urls = APP.urls || {};

        /**
         * 表单字段。
         *
         * ⚠️ 必须用元素引用而不是 `form.name`：HTMLFormElement 上已经有一个内建的
         * `name` 属性（返回表单自身的 name 特性，是字符串），
         * 因此 `form.name.value` 会直接抛错。这是最容易踩的 DOM 命名冲突之一。
         */
        const F = () => ({
            name: $('#uf-name'),
            display: $('#uf-display'),
            email: $('#uf-email'),
            password: $('#uf-password'),
            role: $('#uf-role'),
            active: $('#uf-active'),
            scope: $('#uf-scope'),
        });

        /** 统一给按钮加上「禁用 + 转圈」，避免重复点击与无反馈的等待。 */
        async function withBusy(button, label, fn) {
            if (!button) return fn();

            const original = button.innerHTML;
            button.disabled = true;
            button.innerHTML = `<span class="spinner"></span> ${esc(label)}`;

            try {
                return await fn();
            } finally {
                button.disabled = false;
                button.innerHTML = original;
            }
        }

        const success = (message, delay = 700) => {
            toast(message, 'ok');
            setTimeout(() => location.reload(), delay);
        };

        /* ---------------------------------------------------------- 弹窗 */

        function openModal(selector) {
            $(selector)?.classList.add('is-open');
        }

        /**
         * 关闭弹窗。若弹窗内含 `data-after-close="reload"`，
         * 则无论用哪种方式关闭（按钮 / 遮罩 / Esc）都会刷新列表 ——
         * 一次性密码这类「关掉就再也看不到」的内容必须保持行为一致。
         */
        function closeModal(target) {
            const mask = typeof target === 'string' ? $(target) : target;
            if (!mask) return;

            const needsReload = !!mask.querySelector('[data-after-close="reload"]');
            mask.classList.remove('is-open');

            if (needsReload) setTimeout(() => location.reload(), 260);
        }

        function closeAllModals() {
            $$('.modal-mask.is-open').forEach((mask) => closeModal(mask));
        }

        /* ---------------------------------------------------------- 一次性密码 */

        function showPassword(password, targetName, generated) {
            $('#password-value').textContent = password;
            $('#password-target').textContent = targetName
                ? `${generated ? '系统生成' : '按指定值设置'} · ${targetName}`
                : '';

            openModal('#password-modal');
        }

        /**
         * 复制到剪贴板。
         *
         * http 站点不是安全上下文，`navigator.clipboard` 会直接是 undefined，
         * 因此必须保留选区 + execCommand 的回退路径，否则按钮在内网 http 上完全无效。
         */
        async function copyText(text, node) {
            if (navigator.clipboard?.writeText) {
                try {
                    await navigator.clipboard.writeText(text);
                    return true;
                } catch (_) { /* 落到回退方案 */ }
            }

            if (!node) return false;

            try {
                const range = document.createRange();
                range.selectNodeContents(node);

                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);

                return document.execCommand('copy');
            } catch (_) {
                return false;
            }
        }

        /* ---------------------------------------------------------- 筛选 */

        function submitFilters(form) {
            // 服务端渲染的列表，加载态只能在跳转前用一个视觉占位表达
            $('#users-table-wrap')?.classList.add('is-loading');
            form.submit();
        }

        function bindFilters() {
            const form = $('#user-filter-form');
            if (!form) return;

            $$('[data-autosubmit]', form).forEach((field) => {
                if (field.tagName === 'SELECT') {
                    field.addEventListener('change', () => submitFilters(form));
                    return;
                }

                // 文本框用防抖，并且只在值真的变了才提交 ——
                // 否则「打字后又删回原样」也会触发一次无意义的整页刷新
                const initial = field.value;
                field.addEventListener('input', debounce(() => {
                    if (field.value !== initial) submitFilters(form);
                }, 600));
            });
        }

        /* ---------------------------------------------------------- 批量选择 */

        function selectedIds() {
            return $$('.row-check:checked').map((box) => Number(box.value));
        }

        function syncBulkBar() {
            const ids = selectedIds();

            $('#bulk-count').textContent = String(ids.length);
            $('#bulk-bar')?.classList.toggle('is-open', ids.length > 0);
        }

        function bindSelection() {
            $('#select-all')?.addEventListener('change', (e) => {
                $$('.row-check:not([disabled])').forEach((box) => { box.checked = e.target.checked; });
                syncBulkBar();
            });

            document.addEventListener('change', (e) => {
                if (e.target.classList?.contains('row-check')) syncBulkBar();
            });

            $('#bulk-clear')?.addEventListener('click', () => {
                $$('.row-check').forEach((box) => { box.checked = false; });
                const all = $('#select-all');
                if (all) all.checked = false;
                syncBulkBar();
            });
        }

        function showBulkResult(data) {
            $('#bulk-summary').textContent = data.message;

            const host = $('#bulk-skipped');
            const skipped = data.skipped || [];

            host.innerHTML = skipped.length
                ? '<div class="section-label" data-en="Skipped">以下账号被跳过</div>'
                    + skipped.map((row) => `<div class="card">
                        <strong>${esc(row.name)}</strong>
                        <div class="faint small" style="margin-top:3px">${esc(row.reason)}</div>
                    </div>`).join('')
                : '';

            openModal('#bulk-modal');
        }

        async function runBulk(action, button) {
            const ids = selectedIds();
            if (!ids.length) return;

            const verb = { activate: '启用', deactivate: '禁用', delete: '删除' }[action] || action;
            const warning = action === 'delete'
                ? `确认删除所选的 ${ids.length} 个账号？\n\n`
                    + '删除是软删除：条目归属与操作日志都会保留，可在「只看已删除」中恢复。\n'
                    + '（当前登录账号会被自动跳过）'
                : `确认${verb}所选的 ${ids.length} 个账号？\n\n（当前登录账号会被自动跳过）`;

            if (!confirm(warning)) return;

            await withBusy(button, '处理中…', async () => {
                const { ok, data } = await api(urls.usersBulk, { method: 'POST', body: { action, ids } });

                if (!ok) {
                    toast(data.message || validationMessage(data), 'danger', '批量操作失败');
                    return;
                }

                // 有跳过项时展开明细，而不是用一句「已完成」掩盖部分失败
                if ((data.skipped || []).length) {
                    showBulkResult(data);
                    return;
                }

                success(data.message);
            });
        }

        /* ---------------------------------------------------------- 单条操作 */

        function openUserModal(payload) {
            const mask = $('#user-modal');
            const form = $('#user-form');
            if (!mask || !form) return;

            const fields = F();
            const mode = payload ? 'edit' : 'create';

            mask.dataset.mode = mode;
            form.reset();

            const title = $('#user-modal-title');
            if (title) {
                // 英文微标签由 CSS 的 attr(data-en) 生成，因此要连属性一起改
                title.dataset.en = mode === 'edit' ? 'Edit' : 'Create';
                title.textContent = mode === 'edit' ? `编辑账号 · ${payload.label}` : '新建账号';
            }

            $$('[data-only="create"]', mask).forEach((el) => {
                el.style.display = mode === 'create' ? '' : 'none';
            });

            if (payload) {
                form.dataset.userId = payload.id;
                fields.name.value = payload.name || '';
                fields.display.value = payload.display_name || '';
                fields.email.value = payload.email || '';
                fields.role.value = payload.role || 'viewer';
                fields.active.checked = !!payload.is_active;
                fields.scope.checked = !!payload.strict_source_scope;
            } else {
                delete form.dataset.userId;
                fields.role.value = 'viewer';
                fields.active.checked = true;
                fields.scope.checked = true;
            }

            openModal('#user-modal');
            setTimeout(() => fields.name?.focus(), 60);
        }

        async function submitUserForm(event) {
            event.preventDefault();

            const form = $('#user-form');
            const fields = F();
            const id = form?.dataset.userId;
            const isEdit = !!id;

            const body = {
                name: fields.name.value.trim(),
                display_name: fields.display.value.trim() || null,
                email: fields.email.value.trim(),
                role: fields.role.value,
                is_active: fields.active.checked,
                strict_source_scope: fields.scope.checked,
            };

            if (!isEdit) body.password = fields.password.value.trim() || null;

            // 客户端只做「空值」这种最明显的拦截，格式与唯一性以服务端为准
            if (!body.name || !body.email) {
                toast('登录名与邮箱为必填项。', 'warn');
                (!body.name ? fields.name : fields.email).focus();
                return;
            }

            await withBusy($('#user-submit'), '保存中…', async () => {
                const { ok, data } = await api(isEdit ? `${urls.users}/${id}` : urls.users, {
                    method: isEdit ? 'PUT' : 'POST',
                    body,
                });

                if (!ok) {
                    toast(validationMessage(data), 'danger', '保存失败');
                    return;
                }

                if (fields.password) fields.password.value = '';
                closeModal('#user-modal');

                if (data.password) {
                    // 新建且密码由系统生成：必须先把密码交给操作者，再考虑刷新
                    showPassword(data.password, data.user?.label, data.password_generated);
                    return;
                }

                success(data.message);
            });
        }

        async function runToggle(button) {
            const id = button.dataset.toggle;
            const isActive = button.dataset.active === '1';
            const name = button.dataset.name || '该账号';

            const message = isActive
                ? `确认禁用「${name}」？\n\n该账号将无法登录，已登录的会话会在下一次请求时被强制退出。\n条目归属与操作日志全部保留。`
                : `确认启用「${name}」？该账号将恢复登录权限。`;

            if (!confirm(message)) return;

            await withBusy(button, '处理中…', async () => {
                const { ok, data } = await api(`${urls.users}/${id}/toggle`, {
                    method: 'POST',
                    body: { active: !isActive },
                });

                if (!ok) {
                    toast(data.message || validationMessage(data), 'danger', '操作失败');
                    return;
                }

                success(data.message);
            });
        }

        async function runDelete(button) {
            const id = button.dataset.delete;
            const name = button.dataset.name || '该账号';

            const reason = prompt(
                `确认删除账号「${name}」？\n\n`
                + '删除是软删除：其条目归属与操作日志全部保留，可在「只看已删除」中恢复。\n\n'
                + '可填写删除原因（会记入操作日志，可留空）：',
                '',
            );

            if (reason === null) return; // 用户取消

            await withBusy(button, '删除中…', async () => {
                const { ok, data } = await api(`${urls.users}/${id}`, {
                    method: 'DELETE',
                    body: { reason: reason.trim() || null },
                });

                if (!ok) {
                    toast(data.message || validationMessage(data), 'danger', '删除失败');
                    return;
                }

                success(data.message);
            });
        }

        async function runRestore(button) {
            const id = button.dataset.restore;

            if (!confirm('确认恢复该账号？恢复后即可继续登录，历史归属不变。')) return;

            await withBusy(button, '恢复中…', async () => {
                const { ok, data } = await api(`${urls.users}/${id}/restore`, { method: 'POST', body: {} });

                if (!ok) {
                    toast(data.message || validationMessage(data), 'danger', '恢复失败');
                    return;
                }

                success(data.message);
            });
        }

        async function runResetPassword(button) {
            const id = button.dataset.reset;
            const name = button.dataset.name || '该账号';

            if (!confirm(
                `确认重置「${name}」的密码？\n\n`
                + '原密码会立即失效，系统将生成一个新的随机密码，并且只展示一次。',
            )) return;

            await withBusy(button, '重置中…', async () => {
                const { ok, data } = await api(`${urls.users}/${id}/password`, { method: 'POST', body: {} });

                if (!ok) {
                    toast(data.message || validationMessage(data), 'danger', '重置失败');
                    return;
                }

                showPassword(data.password, name, data.password_generated);
            });
        }

        /* ---------------------------------------------------------- 装配 */

        /**
         * 核验 / 驳回用户自助登记的外部身份。
         *
         * 两个动作的 URL 形状一致，只有尾部动词不同，因此合并成一个函数 ——
         * 分开写迟早会出现「驳回忘了带 reason」这类不一致。
         */
        async function runIdentityAction(kind, button) {
            const userId = button.dataset.userId;
            const identityId = kind === 'verify' ? button.dataset.verifyIdentity : button.dataset.rejectIdentity;
            const account = button.dataset.account || '该绑定';

            const isVerify = kind === 'verify';

            const message = isVerify
                ? `确认已与本人核对，并核验「${account}」？\n\n`
                    + '核验后该绑定会显示为「已核验」——对外意味着更高的可信度，请确保确实核对过。'
                : `确认驳回「${account}」的自助登记？\n\n`
                    + '驳回会删除这条绑定，该外部账号将重新可被登记（操作记入日志）。';

            if (!confirm(message)) return;

            let reason = null;

            if (!isVerify) {
                reason = prompt('可填写驳回原因（会写入操作日志，可留空）：', '');
                if (reason === null) return;
            }

            await withBusy(button, '处理中…', async () => {
                const { ok, data } = await api(
                    `${urls.users}/${userId}/identities/${identityId}/${kind}`,
                    { method: 'POST', body: { reason } },
                );

                if (!ok) {
                    toast(data.message || validationMessage(data), 'danger', '操作失败');
                    return;
                }

                success(data.message);
            });
        }

        function bindActions() {
            // 事件委托：列表页与详情页共用同一套按钮，逐个绑定会有两处漏绑的风险
            document.addEventListener('click', (event) => {
                const target = event.target.closest(
                    '[data-new-user],[data-edit],[data-delete],[data-toggle],[data-reset],[data-restore],'
                    + '[data-bulk],[data-close-modal],[data-copy-password],'
                    + '[data-verify-identity],[data-reject-identity]',
                );

                if (!target) return;

                if (target.hasAttribute('data-new-user')) {
                    openUserModal(null);
                    return;
                }

                if (target.dataset.edit) {
                    try {
                        openUserModal(JSON.parse(target.dataset.payload || '{}'));
                    } catch (_) {
                        toast('无法解析账号数据，请刷新页面重试。', 'danger');
                    }
                    return;
                }

                if (target.dataset.verifyIdentity) { runIdentityAction('verify', target); return; }
                if (target.dataset.rejectIdentity) { runIdentityAction('reject', target); return; }

                if (target.dataset.toggle) { runToggle(target); return; }
                if (target.dataset.delete) { runDelete(target); return; }
                if (target.dataset.reset) { runResetPassword(target); return; }
                if (target.dataset.restore) { runRestore(target); return; }
                if (target.dataset.bulk) { runBulk(target.dataset.bulk, target); return; }

                if (target.hasAttribute('data-close-modal')) {
                    // 「取消」只关窗；带 data-after-close 的按钮由 closeModal 统一触发刷新
                    const mask = target.closest('.modal-mask');
                    const isCancel = target.textContent.trim() === '取消';
                    if (isCancel && mask) {
                        mask.classList.remove('is-open');
                    } else {
                        closeModal(mask);
                    }
                }
            });

            // 点击遮罩关闭 / Esc 关闭
            $$('.modal-mask').forEach((mask) => {
                mask.addEventListener('mousedown', (event) => {
                    if (event.target === mask) closeModal(mask);
                });
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') closeAllModals();
            });

            $('#user-form')?.addEventListener('submit', submitUserForm);

            $('#copy-password')?.addEventListener('click', async () => {
                const node = $('#password-value');
                const ok = await copyText(node?.textContent?.trim() || '', node);
                toast(ok ? '密码已复制到剪贴板。' : '浏览器拒绝了自动复制，请手动选中复制。', ok ? 'ok' : 'warn');
            });
        }

        function boot() {
            bindFilters();

            // 详情页没有表格与批量栏，这些绑定各自做了空值保护
            bindSelection();
            bindActions();
            syncBulkBar();
        }

        return { boot };
    })();

    /* ------------------------------------------------------------------ 个人账号设置 */

    const Settings = (() => {
        const urls = APP.urls || {};

        /** 与账号管理模块同形的忙碌态包装：禁用按钮 + 转圈，避免重复提交。 */
        async function withBusy(button, label, fn) {
            if (!button) return fn();

            const original = button.innerHTML;
            button.disabled = true;
            button.innerHTML = `<span class="spinner"></span> ${esc(label)}`;

            try {
                return await fn();
            } finally {
                button.disabled = false;
                button.innerHTML = original;
            }
        }

        /* ---------------------------------------------------------- 头像预览 */

        /**
         * 选中文件后就地预览。
         *
         * 用本地 object URL 而不是先上传再回显：用户能在花掉一次上传之前
         * 就看到图片会被裁成什么样。同时在客户端挡掉明显不合规的文件，
         * 省掉一次必然会失败的往返（服务端仍然会独立校验，这里只是体验优化）。
         */
        function bindAvatarPicker() {
            const form = $('[data-avatar-form]');
            const input = form && $('[data-avatar-input]', form);
            const dropzone = form && $('[data-avatar-dropzone]', form);
            const preview = form && $('.dropzone__preview', form);

            if (!input || !preview) return;

            let objectUrl = null;

            input.addEventListener('change', () => {
                const file = input.files && input.files[0];

                if (!file) return;

                const maxKb = Number(input.dataset.maxKb || 0);

                if (!/^image\/(jpeg|png)$/.test(file.type)) {
                    toast('只支持 JPG 与 PNG（SVG 可能携带脚本，因此不予接受）。', 'warn');
                    input.value = '';
                    return;
                }

                if (maxKb > 0 && file.size > maxKb * 1024) {
                    toast(`图片超过 ${maxKb} KB，请换一张或先压缩。`, 'warn');
                    input.value = '';
                    return;
                }

                // 之前的预览 URL 要及时释放，否则连续换几次图会一直占着内存
                if (objectUrl) URL.revokeObjectURL(objectUrl);

                objectUrl = URL.createObjectURL(file);
                preview.innerHTML = `<img class="avatar avatar--xl" src="${objectUrl}" alt="待上传的头像">`;
                dropzone?.classList.add('is-filled');
            });
        }

        /* ---------------------------------------------------------- 移除头像 / 解绑 */

        async function removeAvatar(button) {
            if (!confirm('确认移除头像？移除后会显示由昵称首字生成的方块，可随时重新上传。')) return;

            await withBusy(button, '移除中…', async () => {
                const { ok, data } = await api(urls.avatarDestroy, { method: 'DELETE' });

                if (!ok) {
                    toast(data.message || validationMessage(data), 'danger', '移除失败');
                    return;
                }

                toast('头像已移除。', 'ok');
                setTimeout(() => location.reload(), 600);
            });
        }

        async function unlinkIdentity(button) {
            const url = button.dataset.unlinkIdentity;
            const label = button.dataset.providerLabel || '该渠道';
            const isLastMethod = button.dataset.lastMethod === '1';

            /*
             * 只有一种登录方式时，服务端会拒绝解绑（这是刻意的不变量）。
             * 这里仍然把请求发出去，而不是在客户端「猜到」结果后拦下：
             * 规则只有一处定义（IdentityManager），前端只负责把话说清楚。
             */
            const message = isLastMethod
                ? `「${label}」看起来是你目前唯一的登录方式。\n\n`
                    + '服务端会拒绝这次解绑 —— 请先在上面设置一个登录密码，然后再回来解绑。'
                : `确认解绑「${label}」？\n\n解绑后该渠道将无法再用于登录，可随时重新绑定。`;

            if (!confirm(message)) return;

            await withBusy(button, '解绑中…', async () => {
                const { ok, data } = await api(url, { method: 'DELETE' });

                if (!ok) {
                    toast(data.message || validationMessage(data), 'danger', '解绑失败');
                    return;
                }

                toast('已解绑。', 'ok');
                setTimeout(() => location.reload(), 600);
            });
        }

        function bindActions() {
            document.addEventListener('click', (event) => {
                const target = event.target.closest('[data-remove-avatar],[data-unlink-identity]');

                if (!target) return;

                if (target.hasAttribute('data-remove-avatar')) {
                    removeAvatar(target);
                    return;
                }

                unlinkIdentity(target);
            });
        }

        function boot() {
            // 先把上限写到 input 上，再绑定监听：让选文件时就能拦下明显超限的图片
            // （必须在这之前写，否则首次 change 读到的还是空值）
            const form = $('[data-avatar-form]');
            const input = form && $('[data-avatar-input]', form);

            if (input) input.dataset.maxKb = String(APP.avatarMaxKb || 0);

            bindAvatarPicker();
            bindActions();
        }

        return { boot };
    })();

    /* ------------------------------------------------------------------ 分派 */

    document.addEventListener('DOMContentLoaded', () => {
        if (PAGE === 'timeline') Timeline.boot();
        if (PAGE === 'proposals') Proposals.boot();
        if (PAGE === 'anomalies') Anomalies.boot();
        if (PAGE === 'sources') Sources.boot();
        if (PAGE === 'users' || PAGE === 'user-show') Users.boot();
        if (PAGE === 'settings') Settings.boot();
    });
})();
