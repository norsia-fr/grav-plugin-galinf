/**
 * Galinf — notes under "Included image extensions" (General tab).
 *
 * One note per kind of format in the list that will not show as usual:
 *  - refused (SVG, see Settings::REFUSED_EXTENSIONS): left out;
 *  - unknown to Grav (not in media.types): never part of a page's media;
 *  - not displayed by browsers (tiff, heic…): empty tiles;
 *  - no thumbnail possible on this server (Grav type or image engine):
 *    originals served.
 * What the server can do comes once from GET /galinf/formats
 * (ImageFormats); the list is then followed as it is edited, before saving
 * (Admin Next `watch` + `formChanged()`). Display only: no value, it takes
 * no part in saving the form.
 */
const TAG = window.__GRAV_FIELD_TAG;

// Texts: Admin Next dictionary (PLUGIN_GALINF.*, ICU), in the user's
// language (see galinf-reindex.js).
const I18N = () => window.__GRAV_I18N;
const T = (key, params) => (I18N() ? I18N().t('PLUGIN_GALINF.' + key, params) : key);

// Same list as Settings::REFUSED_EXTENSIONS (each field file is
// self-contained: nothing can be shared with the server code).
const REFUSED = ['svg', 'svgz'];

// What the server can do with each format: fetched once per admin page.
let formatsPromise = null;
const loadFormats = () => {
    if (!formatsPromise) {
        const headers = {};
        const token = window.__GRAV_API_TOKEN;
        if (token) headers['X-API-Token'] = token;   // not Authorization: Bearer (FastCGI)
        formatsPromise = fetch(
            (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + '/galinf/formats',
            { headers }
        )
            .then((r) => (r.ok ? r.json() : null))
            .then((j) => (j ? (j.data || j) : null))
            .catch(() => null);   // no answer: only the SVG note, which needs nothing
    }
    return formatsPromise;
};

class GalinfExtensionsNoteField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._value = null;
        this._formats = null;   // ImageFormats::report(), once loaded
        this.attachShadow({ mode: 'open' });
    }

    set field(v) { this._field = v; this._render(); }
    get field() { return this._field; }
    set value(v) { this._value = v; }   // display field: value ignored
    get value() { return this._value; }

    // Re-rendered by Admin Next each time the list changes.
    get watch() { return ['extensions']; }
    formChanged() { this._render(); }

    connectedCallback() {
        this._render();
        loadFormats().then((f) => { this._formats = f; this._render(); });
        // Admin language changed without a reload: render again.
        this._unsubLocale = I18N()?.subscribe?.(() => this._render());
    }

    disconnectedCallback() {
        this._unsubLocale?.();
    }

    // The list as currently typed (sibling field, same prefix as ours).
    _extensions() {
        if (typeof this.getValue !== 'function') return [];
        const name = String(this._field?.name || '');
        const prefix = name.includes('.') ? name.slice(0, name.lastIndexOf('.') + 1) : '';
        const raw = this.getValue(prefix + 'extensions');
        const list = Array.isArray(raw) ? raw : (raw && typeof raw === 'object' ? Object.values(raw) : []);
        return list
            .map((e) => (typeof e === 'string' || typeof e === 'number' ? String(e).trim().replace(/^\.+/, '').toLowerCase() : ''))
            .filter((e) => e !== '');
    }

    _esc(s) {
        return String(s).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    // The formats of the list sorted by note; each format in one note
    // only, the most serious.
    _groups() {
        const groups = { refused: [], unknown: [], browser: [], thumb: [] };
        const f = this._formats;
        for (const ext of new Set(this._extensions())) {
            if (REFUSED.includes(ext)) groups.refused.push(ext);
            else if (!f) continue;
            else if (!(ext in (f.types || {}))) groups.unknown.push(ext);
            else if (!(f.browser || []).includes(ext)) groups.browser.push(ext);
            // animated (gif): served as originals on purpose, so they animate
            else if (f.types[ext] !== 'animated' && !(f.thumbnails || []).includes(ext)) groups.thumb.push(ext);
        }
        return groups;
    }

    _render() {
        if (!this.shadowRoot) return;
        const g = this._groups();
        const notes = [
            ['EXT_NOTE', g.refused],
            ['EXT_UNKNOWN', g.unknown],
            ['EXT_BROWSER', g.browser],
            ['EXT_THUMB', g.thumb],
        ].filter(([, exts]) => exts.length);
        if (!notes.length) {
            this.shadowRoot.innerHTML = '';
            return;
        }
        this.shadowRoot.innerHTML = `
            <style>
                :host { display: block; }
                .note + .note { margin-top: .5rem; }
                .note {
                    padding: .7rem .9rem;
                    border: 1px solid var(--border, rgba(128, 128, 128, .35));
                    border-inline-start: 3px solid var(--warning, #d97706);
                    border-radius: var(--radius, .5rem);
                    background: var(--muted, rgba(128, 128, 128, .08));
                    font-size: .85rem; line-height: 1.45;
                }
                strong { font-weight: 600; }
            </style>
            ${notes.map(([key, exts]) => `
            <div class="note" role="status">
                <strong>${this._esc(T(key + '_TITLE'))}</strong>
                ${this._esc(T(key + '_TEXT', { exts: exts.join(', ') }))}
            </div>`).join('')}`;
    }
}

customElements.define(TAG, GalinfExtensionsNoteField);
