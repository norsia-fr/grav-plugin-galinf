/**
 * Galinf — custom field of the "Maintenance" block.
 *
 * Shows the index stats then a list of actions (reindexes, purge). Reindexes
 * go through POST /galinf/reindex-batch: short chained batches, with
 * progress and the option to stop. Action field without a value: it takes
 * no part in saving the form.
 *
 * Deleting the data before uninstalling is a separate field
 * (galinf-uninstall.js), at the same level in the Maintenance section.
 *
 * Under this block, a box lists the images left without a thumbnail. Those
 * exceeding system.images.max_pixels can be shrunk in place (POST
 * /galinf/shrink, one image per request): the original is replaced.
 */
const TAG = window.__GRAV_FIELD_TAG;

// Texts: Admin Next dictionary (PLUGIN_GALINF.MAINT.* keys, ICU format), in
// the user's language. Admin Next's formatter rejects HTML tags in a
// message: the markup is added here and passed as a parameter ({count},
// {warning}, {setting}).
const I18N = () => window.__GRAV_I18N;
const T = (key, params) => (I18N() ? I18N().t('PLUGIN_GALINF.MAINT.' + key, params) : key);
const has = (key) => !!I18N()?.has('PLUGIN_GALINF.MAINT.' + key);
const locale = () => I18N()?.locale || undefined;
const num = (n, digits = 0) => new Intl.NumberFormat(locale(), { maximumFractionDigits: digits }).format(n);
const dateTime = (ts) => new Date(ts * 1000).toLocaleString(locale(), { dateStyle: 'short', timeStyle: 'short' });

// Line icons (inline SVG: the admin's icon fonts do not cross the shadow
// DOM). Color = currentColor.
const ICONS = {
    all: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
    index: '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
    missing: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
    force: '<path d="m17 2 4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    scratch: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
    purge: '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/>',
};
const icon = (id) => `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
    stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[id] || ''}</svg>`;

// Grouping for display: everyday upkeep on one side, rebuilds (long or
// destructive) on the other.
const GROUPS = [
    { title: 'GROUP_ROUTINE', ids: ['all', 'index', 'missing', 'purge'] },
    { title: 'GROUP_REBUILD', ids: ['force', 'scratch'] },
];

// Visual signal of an action (`kind`):
//   heavy  = heavy operation, keeping the server busy for long → red;
//   delete = permanent deletion → orange (confirmation asked).
const KINDS = {
    heavy: 'KIND_HEAVY',
    delete: 'KIND_DELETE',
};

// `mode` = value sent to /galinf/reindex-batch; `purge` = purge of orphan
// thumbnails (alone, or at the end of "Run all"). Texts: keys ACT_<ID>,
// ACT_<ID>_DESC and, if `confirm`, ACT_<ID>_CONFIRM.
const ACTIONS = [
    { id: 'all', mode: 'missing', purge: true, primary: true, est: 'missing' },
    { id: 'index', mode: 'index', est: 'index' },
    { id: 'missing', mode: 'missing', est: 'missing' },
    { id: 'force', mode: 'force', kind: 'heavy', est: 'force', confirm: true },
    { id: 'scratch', mode: 'scratch', kind: 'delete', est: 'missing', confirm: true },
    { id: 'purge', purge: true, kind: 'delete', confirm: true },
];
const actText = (a, suffix = '') => T('ACT_' + a.id.toUpperCase() + suffix);

class GalinfReindexField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._value = null;
        this._stats = null;
        this._busy = false;
        this._sel = new Set();      // ticked image_path values
        this._errors = new Map();   // image_path → failure message
        this._shrink = null;        // { done, total, current } during a shrink
        this._result = null;        // { kind: 'ok'|'stopped'|'error', title, text } — stays displayed until "Close"
        this._job = null;           // { title, done, total, images, thumbs, route, stop, phase }
        this.attachShadow({ mode: 'open' });
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }
    set value(v) { this._value = v; }   // action field: value ignored
    get value() { return this._value; }

    connectedCallback() {
        this._render();
        this._loadStats();
        // The neighboring "Uninstall" field just deleted the data: the
        // display is reset WITHOUT querying the server (the stats request
        // would immediately recreate an empty database).
        this._onRemoved = () => {
            this._stats = { total: 0, last_indexed_at: null, missing_thumbs: null, last_runs: {} };
            this._result = null;
            this._render();
        };
        window.addEventListener('galinf-data-removed', this._onRemoved);
        // Admin language changed without a reload: render again.
        this._unsubLocale = I18N()?.subscribe?.(() => this._render());
    }

    disconnectedCallback() {
        window.removeEventListener('galinf-data-removed', this._onRemoved);
        this._unsubLocale?.();
    }

    // ─── API ──────────────────────────────────────────
    _apiUrl(path) {
        return (window.__GRAV_API_SERVER_URL || '') +
               (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }
    _headers(json = false) {
        const h = {};
        const token = window.__GRAV_API_TOKEN;
        if (token) h['X-API-Token'] = token;   // not Authorization: Bearer (FastCGI)
        if (json) h['Content-Type'] = 'application/json';
        return h;
    }
    async _api(method, path, body) {
        const opts = { method, headers: this._headers(!!body) };
        if (body) opts.body = JSON.stringify(body);
        const resp = await fetch(this._apiUrl(path), opts);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const json = await resp.json();
        return json.data || json;
    }

    async _loadStats() {
        try {
            this._stats = await this._api('GET', '/galinf/stats');
            // The stats only carry the first 20: multiple selection needs
            // the full list.
            const m = this._stats?.missing_thumbs;
            if (m && m.count > (m.items || []).length) {
                this._stats.missing_thumbs = await this._api('GET', '/galinf/missing-thumbs');
            }
        } catch (e) {
            this._stats = null;
        }
        this._render();
    }

    // Shrinks the given images in place, one per request.
    async _shrinkPaths(paths) {
        if (this._busy || this._shrink || !paths.length) return;
        const m = this._stats.missing_thumbs;
        const ok = await (window.__GRAV_DIALOGS?.confirm({
            title: T('SHRINK_CONFIRM_TITLE', { n: paths.length }),
            message: T('SHRINK_CONFIRM_TEXT', { mp: this._mp(m.max_pixels) }),
            confirmLabel: T('SHRINK_CONFIRM_BUTTON'),
            variant: 'destructive',
        }) ?? Promise.resolve(false));
        if (!ok) return;

        this._result = null;
        this._shrink = { done: 0, total: paths.length, current: null };
        this._render();
        let success = 0;
        for (const path of paths) {
            const started = Date.now();
            this._shrink.current = path;
            this._render();
            try {
                const res = await this._api('POST', '/galinf/shrink', { image_path: path });
                if (res.ok && res.thumb) {
                    success++;
                    this._errors.delete(path);
                    this._sel.delete(path);
                    m.items = m.items.filter((i) => i.image_path !== path);
                    m.count = Math.max(0, m.count - 1);
                    m.over_limit = Math.max(0, m.over_limit - 1);
                } else {
                    this._errors.set(path, res.ok ? T('SHRINK_NO_THUMB') : this._shrinkError(res));
                }
            } catch (e) {
                this._errors.set(path, e.message);
            }
            this._shrink.done++;
            this._render();
            // Never more than ~3 requests/s to the same URI (server anti-DoS),
            // including when failures come back instantly.
            const wait = 350 - (Date.now() - started);
            if (wait > 0) await new Promise((r) => setTimeout(r, wait));
        }
        this._shrink = null;

        const failed = paths.length - success;
        this._result = {
            kind: failed ? 'error' : 'ok',
            title: T(failed ? 'SHRINK_DONE_FAILED' : 'SHRINK_DONE'),
            text: T(failed ? 'SHRINK_DONE_TEXT_FAILED' : 'SHRINK_DONE_TEXT',
                    { ok: success, total: paths.length, failed }),
        };
        this._render();
    }

    // Server refusal: its code (e.g. 'not_writable') gives the
    // SHRINK_ERR_NOT_WRITABLE key; missing or unknown code → raw message.
    _shrinkError(res) {
        const key = res.code ? 'SHRINK_ERR_' + String(res.code).toUpperCase() : '';
        if (key && has(key)) return T(key);
        return res.error || T('SHRINK_ERR_FAILED');
    }

    _humanSize(bytes) {
        const units = T('SIZE_UNITS').split(',');
        let n = bytes, i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return num(n, 1) + ' ' + units[i];
    }

    // Runs a block action: batched reindex, then purge if any. One operation
    // at a time.
    async _run(action) {
        if (this._busy || this._shrink) return;
        if (action.confirm) {
            const ok = await (window.__GRAV_DIALOGS?.confirm({
                title: T('CONFIRM_TITLE', { action: actText(action) }),
                message: actText(action, '_CONFIRM'),
                confirmLabel: actText(action),
                variant: 'destructive',
            }) ?? Promise.resolve(false));
            if (!ok) return;
        }

        this._busy = true;
        this._result = null;
        this._job = { title: actText(action), done: 0, total: 0, images: 0, thumbs: 0, route: '',
                      stop: false, phase: action.mode ? 'index' : 'purge', started: Date.now() };
        this._render();

        const parts = [];
        try {
            if (action.mode) {
                let offset = 0;
                let res;
                do {
                    const started = Date.now();
                    res = await this._api('POST', '/galinf/reindex-batch',
                        { mode: action.mode, offset, action: action.id });
                    offset = res.offset;
                    Object.assign(this._job, { done: res.offset, total: res.total, route: res.route });
                    this._job.images += res.images;
                    this._job.thumbs += res.thumbs_created;
                    this._render();
                    // Never more than ~3 requests/s to the same URI (server anti-DoS).
                    const wait = 350 - (Date.now() - started);
                    if (!res.done && wait > 0) await new Promise((r) => setTimeout(r, wait));
                } while (!res.done && !this._job.stop);

                parts.push(T('PART_ARTICLES', { articles: this._job.done, images: this._job.images }));
                if (this._job.thumbs) parts.push(T('PART_THUMBS', { n: this._job.thumbs }));
                if (res.pruned) parts.push(T('PART_PRUNED', { n: res.pruned }));
            }
            if (action.purge && !this._job.stop) {
                // Batched purge as well (server side: one thumbnail folder
                // after another, ~2 s per call), resumed from `cursor`.
                Object.assign(this._job, { phase: 'purge', done: 0, total: 0, deleted: 0, freed: 0,
                                           purgeStarted: Date.now() });
                this._render();
                let cursor = '';
                let p;
                do {
                    const started = Date.now();
                    p = await this._api('POST', '/galinf/purge-thumbs', { cursor });
                    cursor = p.cursor;
                    Object.assign(this._job, { done: p.processed, total: p.total, deleted: p.deleted, freed: p.freed });
                    this._render();
                    // Never more than ~3 requests/s to the same URI (server anti-DoS).
                    const wait = 350 - (Date.now() - started);
                    if (!p.done && wait > 0) await new Promise((r) => setTimeout(r, wait));
                } while (!p.done && !this._job.stop);
                parts.push(T('PART_PURGED', { n: p.deleted, size: this._humanSize(p.freed) }));
            }
            const stopped = this._job.stop;
            parts.push(T('PART_DURATION', { duration: this._duration((Date.now() - this._job.started) / 1000) }));
            this._result = {
                kind: stopped ? 'stopped' : 'ok',
                title: T(stopped ? 'RESULT_STOPPED' : 'RESULT_DONE', { action: actText(action) }),
                text: parts.join(T('SEP')) + '.' + (stopped ? ' ' + T('RESULT_STOPPED_HINT') : ''),
            };
        } catch (e) {
            this._result = { kind: 'error', title: T('RESULT_FAILED', { action: actText(action) }), text: e.message + '.' };
        } finally {
            this._busy = false;
            this._job = null;
            this._sel.clear();
            this._errors.clear();
            await this._loadStats();
        }
    }

    _duration(seconds) {
        if (seconds < 1) return T('DURATION_UNDER_1S');
        if (seconds < 90) return num(Math.round(seconds)) + ' s';
        if (seconds < 5400) return num(Math.round(seconds / 60)) + ' min';
        return num(seconds / 3600, 1) + ' h';
    }

    // Line shown under each action: its last FULL run on this site (date,
    // duration, volumes), logged by the server. Never run → estimate on a
    // Raspberry Pi 5, announced as such.
    _lastRunLine(action) {
        if (!this._stats) return '';
        const run = (this._stats.last_runs || {})[action.id];
        if (run) {
            const what = action.id === 'purge'
                ? T('LAST_RUN_PURGE', { n: run.deleted, size: this._humanSize(run.freed) })
                : T('LAST_RUN_INDEX', { images: run.images, thumbs: run.thumbs });
            return T('LAST_RUN', { when: dateTime(run.at), duration: this._duration(run.seconds), what });
        }
        // Figures given per 1,000 images, easy to scale to one's own volume
        // (measured on 08/17/2026: ~3 ms per image reread, ~195 ms per
        // thumbnail made).
        return action.est ? T('EST_' + action.est.toUpperCase()) : T('NEVER_RUN');
    }

    // Report of the last operation: stays displayed until "Close" or until
    // another action is started.
    _resultHtml() {
        const r = this._result;
        if (!r || this._job || this._shrink) return '';
        return `
            <div class="prog result ${r.kind}" role="status">
                <strong>${this._esc(r.title)}</strong>
                <span>${this._esc(r.text)}</span>
                <button id="close">${T('CLOSE')}</button>
            </div>`;
    }

    _jobHtml() {
        const j = this._job;
        if (!j) return '';
        const pct = j.total ? Math.round(j.done / j.total * 100) : 0;
        const purge = j.phase === 'purge';
        const head = purge
            ? (j.total ? T('JOB_FOLDER', { n: Math.min(j.done + 1, j.total), total: j.total }) : T('JOB_PURGING'))
            : (j.total ? T('JOB_ARTICLE', { n: Math.min(j.done + 1, j.total), total: j.total }) : T('JOB_STARTING'));
        const since = purge ? j.purgeStarted : j.started;
        return `
            <div class="prog job" role="status">
                <strong>${this._esc(j.title)} — ${head}</strong>
                <span>${purge
                    ? T('PART_PURGED', { n: j.deleted, size: this._humanSize(j.freed) })
                    : T('JOB_PROGRESS', { images: j.images, thumbs: j.thumbs }) + (j.route ? ' — ' + this._esc(j.route) : '')}.
                ${j.done > 0 && j.done < j.total
                    ? T('JOB_REMAINING', { duration: this._duration((Date.now() - since) / 1000 * (j.total - j.done) / j.done) }) : ''}
                ${T('JOB_KEEP_OPEN')}</span>
                <div class="bar"><div style="width:${pct}%"></div></div>
                <button id="stop" ${j.stop ? 'disabled' : ''}>
                    ${T(j.stop ? (purge ? 'JOB_STOPPING_FOLDER' : 'JOB_STOPPING') : 'JOB_STOP')}</button>
            </div>`;
    }

    _actionsHtml() {
        const off = this._busy || this._shrink ? 'disabled' : '';
        const row = (a) => `
            <div class="action ${a.primary ? 'primary' : ''} ${a.kind || ''}">
                <span class="chip">${icon(a.id)}</span>
                <div class="what"><strong>${actText(a)}${a.kind
                    ? ` <b class="badge ${a.kind}">${T(KINDS[a.kind])}</b>` : ''}</strong><span>${actText(a, '_DESC')}</span>
                    <em>${this._lastRunLine(a)}</em></div>
                <button class="act ${a.primary ? 'primary' : ''} ${a.kind || ''}"
                    data-id="${a.id}" ${off}>${actText(a)}</button>
            </div>`;
        return GROUPS.map((g) => `
            <div class="group">
                <h4>${T(g.title)}</h4>
                ${g.ids.map((id) => row(ACTIONS.find((a) => a.id === id))).join('')}
            </div>`).join('');
    }

    // No page chosen yet (default after installing): say where to choose.
    _scopeHtml() {
        if (!this._stats?.scope_empty) return '';
        return `<div class="missing scope" role="status">
            <strong>${T('SCOPE_EMPTY_TITLE')}</strong> ${T('SCOPE_EMPTY_TEXT')}</div>`;
    }

    // Comment captcha that cannot work (Form plugin missing, Turnstile keys
    // not set): visitors cannot post, say why.
    _captchaHtml() {
        const issue = this._stats?.captcha_issue;
        if (!issue) return '';
        return `<div class="missing scope" role="status">
            <strong>${T('CAPTCHA_ISSUE_TITLE')}</strong> ${T('CAPTCHA_ISSUE_' + String(issue).toUpperCase())}</div>`;
    }

    // Figures header of the block: three markers readable at a glance.
    _tilesHtml() {
        if (!this._stats) return `<div class="tiles"><div class="tile"><b>—</b><span>${T('STATS_UNAVAILABLE')}</span></div></div>`;
        const ts = this._stats.last_indexed_at;
        const when = ts ? dateTime(ts) : T('NEVER');
        const total = this._stats.total ?? 0;
        const missing = this._stats.missing_thumbs?.count ?? 0;
        return `
            <div class="tiles">
                <div class="tile"><b>${num(total)}</b><span>${T('TILE_INDEXED', { n: total })}</span></div>
                <div class="tile"><b>${when}</b><span>${T('TILE_LAST')}</span></div>
                <div class="tile ${missing ? 'warn' : ''}"><b>${num(missing)}</b><span>${T('TILE_MISSING', { n: missing })}</span></div>
            </div>`;
    }

    _esc(s) {
        return String(s).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    _mp(n) {
        return num(n / 1e6, 1);
    }

    // Images left without a thumbnail: the gallery serves the original
    // instead.
    _missingHtml() {
        const m = this._stats?.missing_thumbs;
        if (!m || !m.count) return '';
        const items = m.items || [];
        const locked = this._busy || !!this._shrink;
        const off = locked ? 'disabled' : '';
        const reducible = items.filter((i) => i.over_limit);
        const selected = reducible.filter((i) => this._sel.has(i.image_path));

        const rows = items.map((i) => {
            const path = this._esc(i.image_path);
            const err = this._errors.get(i.image_path);
            return `
            <li>
                ${i.over_limit
                    ? `<input type="checkbox" class="pick" data-path="${path}" ${off}
                        ${this._sel.has(i.image_path) ? 'checked' : ''}>`
                    : '<span class="nopick"></span>'}
                <span class="name">${path} — ${i.width} × ${i.height}
                (${this._mp(i.width * i.height)} MP)${i.over_limit ? '' : ' — ' + T('MISSING_GEN_FAILED')}
                ${i.over_limit && i.target_width
                    ? `<span class="target">${T('MISSING_TARGET', { width: i.target_width, height: i.target_height })}</span>` : ''}
                ${this._shrink?.current === i.image_path ? `<span class="doing">${T('SHRINK_DOING')}</span>` : ''}
                ${err ? `<span class="err">${this._esc(T('SHRINK_FAILED', { reason: err }))}</span>` : ''}</span>
                ${i.over_limit
                    ? `<button class="one" data-path="${path}" ${off}>${T('SHRINK')}</button>` : ''}
            </li>`;
        }).join('');
        const more = m.count > items.length
            ? `<li><span class="nopick"></span><span class="name">${T('MISSING_MORE', { n: m.count - items.length })}</span></li>` : '';

        const tools = reducible.length ? `
            <p class="note">${T('MISSING_NOTE', { mp: this._mp(m.max_pixels),
                warning: `<strong>${T('MISSING_NOTE_WARNING')}</strong>` })}</p>
            <div class="tools">
                <label><input type="checkbox" id="all" ${off}
                    ${selected.length === reducible.length ? 'checked' : ''}> ${T('SELECT_ALL')}</label>
                <button id="sel" ${off || (selected.length ? '' : 'disabled')}>
                    ${T('SHRINK_SELECTION', { n: selected.length })}</button>
            </div>
            ${this._shrink ? `
            <div class="prog" role="status">
                <strong>${T('SHRINK_PROGRESS_TITLE', { n: Math.min(this._shrink.done + 1, this._shrink.total), total: this._shrink.total })}</strong>
                <span>${T('SHRINK_PROGRESS_TEXT', { n: this._shrink.done })}</span>
                <div class="bar"><div style="width:${Math.round(this._shrink.done / this._shrink.total * 100)}%"></div></div>
            </div>` : ''}` : '';

        return `
            <div class="missing">
                ${T('MISSING_HEAD', { count: `<strong>${T('MISSING_COUNT', { n: m.count })}</strong>` })}
                ${m.over_limit ? T('MISSING_OVER', { n: m.over_limit, mp: this._mp(m.max_pixels),
                    setting: '<code>system.images.max_pixels</code>' }) : ''}
                ${tools}
                <ul>${rows}${more}</ul>
            </div>`;
    }

    _render() {
        this.shadowRoot.innerHTML = `
            <style>
                /* Admin Next theme tokens (inherited through the shadow DOM),
                   with fallbacks: the block follows the admin's light/dark
                   theme and accent color. */
                :host {
                    display: block;
                    --g-primary: var(--primary, #2563eb);
                    --g-on-primary: var(--primary-foreground, #fff);
                    --g-border: var(--border, rgba(128, 128, 128, .35));
                    --g-card: var(--card, transparent);
                    --g-muted: var(--muted, rgba(128, 128, 128, .12));
                    --g-soft: var(--muted-foreground, #6b7280);
                    --g-danger: var(--destructive, #dc2626);
                    --g-success: var(--success, #15803d);
                    --g-warning: var(--warning, #d97706);
                    --g-radius: var(--radius, .5rem);
                }
                .box {
                    border: 1px solid var(--g-border);
                    border-radius: var(--g-radius);
                    background: var(--g-card);
                    overflow: hidden;
                }
                .tiles {
                    display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px;
                    background: var(--g-border);
                    border-bottom: 1px solid var(--g-border);
                }
                .tile { padding: .8rem 1rem; background: var(--g-card); }
                .tile b { display: block; font-size: 1.25rem; font-weight: 600; line-height: 1.2; }
                .tile span { font-size: .78rem; color: var(--g-soft); }
                .tile.warn b { color: var(--g-warning); }
                .body { padding: 0 1rem .4rem; }
                .group h4 {
                    margin: 1rem 0 .15rem;
                    font-size: .72rem; font-weight: 600; letter-spacing: .06em;
                    text-transform: uppercase; color: var(--g-soft);
                }
                .action {
                    display: flex; align-items: center; gap: .9rem;
                    padding: .7rem 0;
                    border-top: 1px solid var(--g-border);
                }
                .group h4 + .action { border-top: 0; }
                .chip {
                    flex: none; display: grid; place-items: center;
                    width: 2.25rem; height: 2.25rem; border-radius: 999px;
                    background: var(--g-muted); color: var(--g-soft);
                }
                .action.primary .chip { background: var(--g-primary); color: var(--g-on-primary); }
                .action.heavy .chip { color: var(--g-danger); }
                .action.delete .chip { color: var(--g-warning); }
                .badge {
                    display: inline-block; margin-inline-start: .4rem; padding: .05rem .45rem;
                    border-radius: 999px; vertical-align: .1em;
                    font-size: .68rem; font-weight: 600; letter-spacing: .02em;
                }
                .badge.heavy { color: var(--g-danger); background: color-mix(in srgb, var(--g-danger) 14%, transparent); }
                .badge.delete { color: var(--g-warning); background: color-mix(in srgb, var(--g-warning) 16%, transparent); }
                .what { flex: 1; font-size: .85rem; line-height: 1.45; }
                .what strong { display: block; font-size: .92rem; }
                .what span { color: var(--g-soft); }
                .what em { display: block; margin-top: .15rem; font-style: normal; font-size: .78rem; color: var(--g-soft); opacity: .85; }
                .act {
                    flex: none; min-width: 15.5rem;
                    padding: .5rem .9rem;
                    border: 1px solid var(--g-border); border-radius: calc(var(--g-radius) - 2px);
                    background: transparent; color: inherit;
                    font: inherit; font-size: .88rem; font-weight: 500; cursor: pointer;
                    transition: background .15s, filter .15s;
                }
                .act:hover:not([disabled]) { background: var(--g-muted); }
                .act.primary { border-color: var(--g-primary); background: var(--g-primary); color: var(--g-on-primary); }
                .act.primary:hover:not([disabled]) { background: var(--g-primary); filter: brightness(1.1); }
                .act.heavy { border-color: var(--g-danger); color: var(--g-danger); }
                .act.heavy:hover:not([disabled]) { background: color-mix(in srgb, var(--g-danger) 12%, transparent); }
                .act.delete { border-color: var(--g-warning); color: var(--g-warning); }
                .act.delete:hover:not([disabled]) { background: color-mix(in srgb, var(--g-warning) 12%, transparent); }
                button[disabled] { opacity: .55; cursor: default; }
                @media (max-width: 640px) {
                    .tiles { grid-template-columns: 1fr; }
                    .action { flex-wrap: wrap; }
                    .act { min-width: 0; width: 100%; }
                }
                .prog {
                    margin: .6rem 0;
                    padding: .7rem .9rem;
                    border-radius: calc(var(--g-radius) - 2px);
                    background: var(--g-warning); color: #fff;
                    font-size: .95rem;
                }
                .prog.job { background: var(--g-primary); color: var(--g-on-primary); margin: 1rem 0 0; }
                .prog.result { margin: 1rem 0 0; }
                .prog.result.ok { background: var(--g-success); }
                .prog.result.stopped { background: var(--g-warning); }
                .prog.result.error { background: var(--g-danger); }
                .prog strong { display: block; font-size: 1.05rem; }
                .prog .bar {
                    height: 8px; margin-top: .45rem;
                    border-radius: 4px; overflow: hidden;
                    background: rgba(255, 255, 255, .35);
                }
                .prog .bar div { height: 100%; background: #fff; transition: width .3s; }
                .prog #stop, .prog #close {
                    margin-top: .55rem; padding: .3rem .7rem;
                    border: 1px solid currentColor; border-radius: calc(var(--g-radius) - 2px);
                    background: transparent; color: inherit;
                    font: inherit; font-size: .85rem; cursor: pointer;
                }
                .missing {
                    margin-top: .75rem;
                    padding: .75rem .9rem;
                    border: 1px solid color-mix(in srgb, var(--g-warning) 45%, transparent);
                    border-inline-start: 4px solid var(--g-warning);
                    border-radius: var(--g-radius);
                    background: color-mix(in srgb, var(--g-warning) 8%, transparent);
                    font-size: .85rem;
                    line-height: 1.45;
                }
                .missing ul { list-style: none; margin: .4rem 0 0; padding: 0; }
                .missing li { display: flex; align-items: center; gap: .6rem; padding: .25rem 0; }
                .missing .name { flex: 1; word-break: break-all; }
                .missing .note { margin: .5rem 0; }
                .missing .tools {
                    display: flex; align-items: center; gap: .9rem; flex-wrap: wrap;
                    padding-bottom: .5rem;
                    border-bottom: 1px solid color-mix(in srgb, var(--g-warning) 30%, transparent);
                }
                .missing label { display: inline-flex; align-items: center; gap: .4rem; cursor: pointer; }
                .missing button {
                    padding: .3rem .65rem;
                    border: 0; border-radius: calc(var(--g-radius) - 2px);
                    background: var(--g-warning); color: #fff;
                    font: inherit; font-size: .82rem; cursor: pointer; white-space: nowrap;
                }
                .missing button:hover:not([disabled]) { filter: brightness(.92); }
                .missing .err { display: block; color: var(--g-danger); }
                .missing .target { display: block; opacity: .8; }
                .missing .doing { display: block; color: var(--g-warning); font-weight: 600; }
                .missing.scope { margin: 0 0 .75rem; }
            </style>
            ${this._scopeHtml()}
            ${this._captchaHtml()}
            <div class="box">
                ${this._tilesHtml()}
                <div class="body">
                    ${this._jobHtml()}
                    ${this._resultHtml()}
                    ${this._actionsHtml()}
                </div>
            </div>
            ${this._missingHtml()}
        `;
        this.shadowRoot.querySelectorAll('.act').forEach((btn) => btn.addEventListener('click',
            () => this._run(ACTIONS.find((a) => a.id === btn.dataset.id))));

        this.shadowRoot.getElementById('close')?.addEventListener('click', () => {
            this._result = null;
            this._render();
        });
        this.shadowRoot.getElementById('stop')?.addEventListener('click', () => {
            if (this._job) this._job.stop = true;
            this._render();
        });

        const root = this.shadowRoot;
        const reducible = () => (this._stats?.missing_thumbs?.items || [])
            .filter((i) => i.over_limit).map((i) => i.image_path);
        root.querySelectorAll('.pick').forEach((box) => box.addEventListener('change', () => {
            if (box.checked) this._sel.add(box.dataset.path);
            else this._sel.delete(box.dataset.path);
            this._render();
        }));
        root.getElementById('all')?.addEventListener('change', (e) => {
            this._sel = new Set(e.target.checked ? reducible() : []);
            this._render();
        });
        root.querySelectorAll('.one').forEach((btn) => btn.addEventListener('click',
            () => this._shrinkPaths([btn.dataset.path])));
        root.getElementById('sel')?.addEventListener('click',
            () => this._shrinkPaths(reducible().filter((p) => this._sel.has(p))));
    }
}

customElements.define(TAG, GalinfReindexField);
