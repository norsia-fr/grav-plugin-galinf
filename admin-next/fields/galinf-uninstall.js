/**
 * Galinf — "Uninstall" custom field of the Maintenance section.
 *
 * Deletes what the plugin created on the server and its uninstallation
 * would leave in place: the index and the thumbnails (POST
 * /galinf/remove-data), and the visitor comments if the box is ticked.
 * Action field without a value: it takes no part in saving the form.
 */
const TAG = window.__GRAV_FIELD_TAG;

// Texts: Admin Next dictionary (PLUGIN_GALINF.MAINT.*, ICU), in the user's
// language (see galinf-reindex.js).
const I18N = () => window.__GRAV_I18N;
const T = (key, params) => (I18N() ? I18N().t('PLUGIN_GALINF.MAINT.' + key, params) : key);
const num = (n, digits = 0) => new Intl.NumberFormat(I18N()?.locale || undefined,
    { maximumFractionDigits: digits }).format(n);

class GalinfUninstallField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._value = null;
        this._busy = false;
        this._comments = false;     // "also delete the comments" box
        this._result = null;        // { kind: 'ok'|'error', title, text } — stays displayed until "Close"
        this.attachShadow({ mode: 'open' });
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }
    set value(v) { this._value = v; }   // action field: value ignored
    get value() { return this._value; }

    connectedCallback() {
        this._render();
        // Admin language changed without a reload: render again.
        this._unsubLocale = I18N()?.subscribe?.(() => this._render());
    }

    disconnectedCallback() {
        this._unsubLocale?.();
    }

    async _api(method, path, body) {
        const headers = { 'Content-Type': 'application/json' };
        const token = window.__GRAV_API_TOKEN;
        if (token) headers['X-API-Token'] = token;   // not Authorization: Bearer (FastCGI)
        const resp = await fetch(
            (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path,
            { method, headers, body: JSON.stringify(body) }
        );
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const json = await resp.json();
        return json.data || json;
    }

    _esc(s) {
        return String(s).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    _humanSize(bytes) {
        const units = T('SIZE_UNITS').split(',');
        let n = bytes, i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return num(n, 1) + ' ' + units[i];
    }

    async _remove() {
        if (this._busy) return;
        const withComments = this._comments;
        const ok = await (window.__GRAV_DIALOGS?.confirm({
            title: T('UN_CONFIRM_TITLE'),
            message: T('UN_CONFIRM_TEXT') + ' ' + T(withComments ? 'UN_CONFIRM_COMMENTS' : 'UN_CONFIRM_KEEP'),
            confirmLabel: T(withComments ? 'UN_CONFIRM_BUTTON_ALL' : 'UN_CONFIRM_BUTTON'),
            variant: 'destructive',
        }) ?? Promise.resolve(false));
        if (!ok) return;

        this._busy = true;
        this._result = null;
        this._render();
        try {
            const r = await this._api('POST', '/galinf/remove-data', { comments: withComments });
            const parts = [T(r.index ? 'UN_INDEX_DELETED' : 'UN_INDEX_ABSENT'),
                           T('UN_THUMBS', { n: r.thumbs })];
            if (r.comments != null) parts.push(T('UN_COMMENTS', { n: r.comments }));
            parts.push(T('UN_FREED', { size: this._humanSize(r.freed) }));
            this._result = {
                kind: 'ok',
                title: T('UN_DONE'),
                text: parts.join(T('SEP')) + '. ' + T('UN_DONE_HINT'),
            };
            this._comments = false;
            // The "Gallery index" field resets its display.
            window.dispatchEvent(new CustomEvent('galinf-data-removed'));
        } catch (e) {
            this._result = { kind: 'error', title: T('UN_FAILED'), text: e.message + '.' };
        } finally {
            this._busy = false;
            this._render();
        }
    }

    _render() {
        const off = this._busy ? 'disabled' : '';
        const r = this._result;
        this.shadowRoot.innerHTML = `
            <style>
                /* Same theme tokens as the "Gallery index" field. */
                :host {
                    display: block;
                    --g-border: var(--border, rgba(128, 128, 128, .35));
                    --g-card: var(--card, transparent);
                    --g-muted: var(--muted, rgba(128, 128, 128, .12));
                    --g-soft: var(--muted-foreground, #6b7280);
                    --g-danger: var(--destructive, #dc2626);
                    --g-success: var(--success, #15803d);
                    --g-radius: var(--radius, .5rem);
                }
                .box {
                    padding: .85rem 1rem;
                    border: 1px solid var(--g-border);
                    border-radius: var(--g-radius);
                    background: var(--g-card);
                }
                .action { display: flex; align-items: center; gap: .9rem; }
                .chip {
                    flex: none; display: grid; place-items: center;
                    width: 2.25rem; height: 2.25rem; border-radius: 999px;
                    background: var(--g-muted); color: var(--g-danger);
                }
                .what { flex: 1; font-size: .85rem; line-height: 1.45; }
                .what > strong { display: block; font-size: .92rem; }
                .what span { color: var(--g-soft); }
                label {
                    display: flex; align-items: center; gap: .4rem;
                    margin-top: .4rem; cursor: pointer; color: var(--g-danger);
                }
                .act {
                    flex: none; min-width: 15.5rem;
                    padding: .5rem .9rem;
                    border: 1px solid var(--g-danger); border-radius: calc(var(--g-radius) - 2px);
                    background: transparent; color: var(--g-danger);
                    font: inherit; font-size: .88rem; font-weight: 500; cursor: pointer;
                }
                .act:hover:not([disabled]) { background: color-mix(in srgb, var(--g-danger) 12%, transparent); }
                button[disabled] { opacity: .55; cursor: default; }
                @media (max-width: 640px) {
                    .action { flex-wrap: wrap; }
                    .act { min-width: 0; width: 100%; }
                }
                .result {
                    margin-top: .75rem;
                    padding: .6rem .8rem;
                    border-radius: 6px;
                    color: #fff; font-size: .95rem;
                }
                .result.ok { background: var(--g-success); }
                .result.error { background: var(--g-danger); }
                .result strong { display: block; font-size: 1.05rem; }
                .result button {
                    display: block;
                    margin-top: .55rem; padding: .3rem .7rem;
                    border: 1px solid #fff; border-radius: 6px;
                    background: transparent; color: #fff;
                    font-size: .85rem; cursor: pointer;
                }
            </style>
            <div class="box">
                <div class="action">
                    <span class="chip"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="m10 11 4 4M14 11l-4 4"/></svg></span>
                    <div class="what"><strong>${T('UN_TITLE')}</strong>
                        <span>${T('UN_DESC', { warning: `<strong>${T('UN_DESC_WARNING')}</strong>` })}</span>
                        <label><input type="checkbox" id="rmc" ${off} ${this._comments ? 'checked' : ''}>
                        ${T('UN_WITH_COMMENTS')}</label></div>
                    <button id="rm" class="act" ${off}>${T(this._busy ? 'UN_BUSY' : 'UN_BUTTON')}</button>
                </div>
                ${r ? `
                <div class="result ${r.kind}" role="status">
                    <strong>${this._esc(r.title)}</strong>
                    <span>${this._esc(r.text)}</span>
                    <button id="close">${T('CLOSE')}</button>
                </div>` : ''}
            </div>
        `;
        this.shadowRoot.getElementById('rm')?.addEventListener('click', () => this._remove());
        this.shadowRoot.getElementById('rmc')?.addEventListener('change', (e) => {
            this._comments = e.target.checked;
        });
        this.shadowRoot.getElementById('close')?.addEventListener('click', () => {
            this._result = null;
            this._render();
        });
    }
}

customElements.define(TAG, GalinfUninstallField);
