/**
 * Galinf — "commented image preview" custom field (edit form of a Flex
 * comment).
 *
 * Reads the comment key from the admin URL (/galinf-comments/{key}), queries
 * GET /galinf/comment-preview/{key} and shows the persistent thumbnail
 * (fallback: the original image), clickable to the original.
 * Display field without a value: it takes no part in saving.
 */
const TAG = window.__GRAV_FIELD_TAG;

// Texts: Admin Next dictionary (PLUGIN_GALINF.MAINT.*), in the user's
// language.
const T = (key) => (window.__GRAV_I18N ? window.__GRAV_I18N.t('PLUGIN_GALINF.MAINT.' + key) : key);

class GalinfCommentPreviewField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._value = null;
        this._data = null;
        this._error = false;
        this.attachShadow({ mode: 'open' });
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }
    set value(v) { this._value = v; }   // display field: value ignored
    get value() { return this._value; }

    connectedCallback() {
        this._render();
        this._load();
    }

    // ─── API ──────────────────────────────────────────
    _apiUrl(path) {
        return (window.__GRAV_API_SERVER_URL || '') +
               (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }
    _headers() {
        const h = {};
        const token = window.__GRAV_API_TOKEN;
        if (token) h['X-API-Token'] = token;   // not Authorization: Bearer (FastCGI)
        return h;
    }

    _commentKey() {
        // Admin URL: …/galinf-comments/{key} (hex key, not "new").
        const m = window.location.pathname.match(/\/galinf-comments\/([0-9a-f]{8,64})(?:[/?#]|$)/);
        return m ? m[1] : null;
    }

    async _load() {
        const key = this._commentKey();
        if (!key) { this._error = true; this._render(); return; }
        try {
            const resp = await fetch(this._apiUrl('/galinf/comment-preview/' + encodeURIComponent(key)), {
                headers: this._headers(),
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const json = await resp.json();
            this._data = json.data || json;
        } catch (e) {
            this._error = true;
        }
        this._render();
    }

    _render() {
        const d = this._data;
        let body;
        if (this._error) {
            body = `<p class="gcp-note">${T('PREVIEW_UNAVAILABLE')}</p>`;
        } else if (!d) {
            body = `<p class="gcp-note">${T('PREVIEW_LOADING')}</p>`;
        } else if (!d.image) {
            body = `<p class="gcp-note">${T('PREVIEW_NOT_FOUND')}</p>`;
        } else {
            const src = d.thumb || d.image;
            body = `
                <a href="${this._esc(d.image)}" target="_blank" rel="noopener" title="${this._esc(T('PREVIEW_OPEN'))}">
                    <img src="${this._esc(src)}" alt="${this._esc(d.file || '')}" loading="lazy">
                </a>`;
        }

        this.shadowRoot.innerHTML = `
            <style>
                :host { display: block; }
                a { display: inline-block; line-height: 0; border-radius: 6px; overflow: hidden;
                    border: 1px solid var(--border, #e5e7eb); }
                img { display: block; max-width: 320px; max-height: 200px; width: auto; height: auto; }
                .gcp-note { margin: 0; font-size: 13px; color: var(--muted-foreground, #6b7280); }
            </style>
            ${body}`;
    }

    _esc(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }
}

customElements.define(TAG, GalinfCommentPreviewField);
