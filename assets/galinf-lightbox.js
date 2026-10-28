/* Galinf — PhotoSwipe lightbox UI (module separate from the grid).
 * Lightbox work, step 1: top bar (fullscreen, download),
 * + carry-over of what existed ("View the article" button, .meta.yaml
 * captions). The grid/virtualization stays in galinf-gallery.js, which calls
 * window.galinfLightboxUI(lightbox, root) after creating the lightbox.
 * Vanilla JS, no dependency. */
(function () {
  'use strict';

  function baseName(url) {
    var f = (url || '').split('?')[0].split('/').pop() || '';
    try { return decodeURIComponent(f); } catch (e) { return f; }
  }

  /* Is the user TYPING in a field? (comment form…) → every keyboard
     shortcut must step aside. */
  function isTyping(e) {
    var t = e.target;
    if (!t) return false;
    var tag = (t.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || t.isContentEditable;
  }

  window.galinfLightboxUI = function (lightbox, root) {
    var base        = root.dataset.base || '';
    var article     = !!root.dataset.route;          // article page mode
    var metaUrl     = root.dataset.meta || '';
    var commentUrl  = root.dataset.comment || '';    // comments POST endpoint
    var loop        = root.dataset.loop === '1';
    // Visitor texts: shared translator, created by galinf-gallery.js.
    var i18n        = window.galinfI18n(root);
    var t           = i18n.t;

    /* Top bar buttons allowed by the admin (allow list). */
    var btnList = (root.dataset.buttons || 'slideshow,rotate,download,fullscreen,strip,info').split(',');
    function btnOn(n) { return btnList.indexOf(n) !== -1; }

    /* Sidebar/strip states chosen by the admin. RULE: if the matching button
       is hidden, the state is ENFORCED (the visitor's remembered choice is
       ignored); otherwise it is only a default and the visitor's
       localStorage takes precedence once they have clicked. */
    var sbOpenDefault    = root.dataset.sbOpen === '1';
    var stripOpenDefault = root.dataset.stripOpen !== '0';
    var sbForced         = !btnOn('info');
    var stripForced      = !btnOn('strip');

    /* Date + time in the chosen language's format ("11 juillet 2026 à
       17:12", "July 11, 2026 at 5:12 PM"). */
    function fmtDate(iso) {
      var d = new Date(iso);
      if (isNaN(d.getTime())) return '';
      try {
        return d.toLocaleString(i18n.locale, { dateStyle: 'long', timeStyle: 'short' });
      } catch (e) {
        return d.toLocaleString();
      }
    }

    /* ---- .meta.yaml metadata: one request per article, memoized ---- */
    var metaCache = {};
    function fetchMeta(route) {
      if (!metaCache[route]) {
        var u = new URL(metaUrl, window.location.origin);
        u.searchParams.set('route', route);
        // Failure (network, or a server briefly refusing: 429, 503…): the
        // empty response is NOT kept, the next slide will ask again.
        metaCache[route] = fetch(u.toString())
          .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
          })
          .catch(function () { delete metaCache[route]; return {}; });
      }
      return metaCache[route];
    }

    lightbox.on('uiRegister', function () {
      var pswp = lightbox.pswp;
      var ui = pswp.ui;

      /* PhotoSwipe does not guard its OWN keys while typing: arrows = change
         image, z = zoom, Escape = close (goodbye draft). Listener in the
         CAPTURE phase on window → it runs before PhotoSwipe's and stops the
         propagation of ALL keys when a field has focus (typing itself is not
         affected: no preventDefault). Escape = simply leave the field. */
      var guardKeys = function (e) {
        if (!isTyping(e)) return;
        if (e.key === 'Escape') {
          e.target.blur();
        }
        e.stopPropagation();
      };
      window.addEventListener('keydown', guardKeys, true);
      pswp.on('destroy', function () { window.removeEventListener('keydown', guardKeys, true); });

      /* ---- slideshow (order 6: on the left, near the counter) ----
         Delay chosen by the VISITOR (preset menu, remembered in
         localStorage). Manual navigation during playback = timer restart;
         explicit pause (button); the timer waits for the current image to
         be loaded (slow connection). */
      var DELAYS = [3000, 5000, 8000, 15000];
      var delay = 0;
      try { delay = parseInt(localStorage.getItem('galinf-slideshow-delay'), 10); } catch (e) {}
      if (DELAYS.indexOf(delay) === -1) delay = 5000;
      var playing = false, ssTimer = null, ssWaiting = false, ssT0 = 0;
      var playBtn = null, delayLabel = null;

      // Progress ring around the delay button: fills as time passes on the
      // current image (rAF during playback).
      var RING_C = 2 * Math.PI * 16.5;   // circumference (r = 16.5, viewBox 36)
      var ringFg = null, ringRaf = 0;

      function ringSet(p) {
        if (ringFg) ringFg.style.strokeDashoffset = String(RING_C * (1 - p));
      }
      function ringLoop() {
        if (!playing) { ringSet(0); return; }
        ringSet(ssWaiting ? 1 : Math.min(1, (Date.now() - ssT0) / delay));
        ringRaf = requestAnimationFrame(ringLoop);
      }

      function ssSchedule() {
        clearTimeout(ssTimer);
        ssWaiting = false;
        ssT0 = Date.now();
        ssTimer = setTimeout(ssTick, delay);
      }
      function ssTick() {
        if (!playing) return;
        var c = pswp.currSlide && pswp.currSlide.content;
        if (c && typeof c.isLoading === 'function' && c.isLoading()) {
          ssWaiting = true;   // image not ready → restart on loadComplete
          return;
        }
        ssAdvance();
      }
      function ssAdvance() {
        if (!loop && pswp.currIndex >= pswp.getNumItems() - 1) {
          ssStop();           // end of the gallery without loop → stop
          return;
        }
        pswp.next();          // 'change' will restart the timer
      }
      function ssStart() {
        playing = true;
        if (playBtn) playBtn.classList.add('galinf-playing');
        ssSchedule();
        cancelAnimationFrame(ringRaf);
        ringRaf = requestAnimationFrame(ringLoop);
      }
      function ssStop() {
        playing = false;
        clearTimeout(ssTimer);
        ssWaiting = false;
        if (playBtn) playBtn.classList.remove('galinf-playing');
        cancelAnimationFrame(ringRaf);
        ringSet(0);
      }

      pswp.on('change', function () { if (playing) ssSchedule(); });
      pswp.on('loadComplete', function (e) {
        if (playing && ssWaiting && e.slide === pswp.currSlide) {
          ssWaiting = false;
          ssTimer = setTimeout(ssAdvance, 400);
        }
      });
      pswp.on('destroy', function () {
        clearTimeout(ssTimer);
        cancelAnimationFrame(ringRaf);
      });

      if (btnOn('slideshow'))
      ui.registerElement({
        name: 'galinf-play', order: 6, isButton: true,
        title: t('SLIDESHOW'),
        html: '<svg class="pswp__icn" viewBox="0 0 24 24" width="32" height="32" aria-hidden="true">'
            + '<path class="galinf-ic-play" d="M8 5.14v13.72L19 12z"/>'
            + '<path class="galinf-ic-pause" d="M7 5h4v14H7zm6 0h4v14h-4z"/></svg>',
        onInit: function (el, pswp) {
          playBtn = el;
          el.onclick = function () { playing ? ssStop() : ssStart(); };
        }
      });

      /* ---- delay picker (order 6.5, dropdown menu, localStorage) ---- */
      if (btnOn('slideshow'))
      ui.registerElement({
        name: 'galinf-delay', order: 6.5, tagName: 'div',
        onInit: function (el, pswp) {
          el.title = t('SLIDESHOW_DELAY');

          // Round badge: delay text + SVG progress ring.
          var badge = document.createElement('span');
          badge.className = 'galinf-delay-badge';
          delayLabel = document.createElement('span');
          delayLabel.className = 'galinf-delay-label';
          delayLabel.textContent = (delay / 1000) + 's';
          badge.appendChild(delayLabel);

          var NS = 'http://www.w3.org/2000/svg';
          var ring = document.createElementNS(NS, 'svg');
          ring.setAttribute('class', 'galinf-delay-ring');
          ring.setAttribute('viewBox', '0 0 36 36');
          var track = document.createElementNS(NS, 'circle');
          track.setAttribute('cx', '18'); track.setAttribute('cy', '18'); track.setAttribute('r', '16.5');
          track.setAttribute('class', 'galinf-ring-track');
          ringFg = document.createElementNS(NS, 'circle');
          ringFg.setAttribute('cx', '18'); ringFg.setAttribute('cy', '18'); ringFg.setAttribute('r', '16.5');
          ringFg.setAttribute('class', 'galinf-ring-fg');
          ringFg.style.strokeDasharray = String(RING_C);
          ringFg.style.strokeDashoffset = String(RING_C);
          ring.appendChild(track);
          ring.appendChild(ringFg);
          badge.appendChild(ring);
          el.appendChild(badge);

          var pop = document.createElement('span');
          pop.className = 'galinf-delay-pop';
          DELAYS.forEach(function (d) {
            var o = document.createElement('span');
            o.className = 'galinf-delay-opt' + (d === delay ? ' galinf-delay-cur' : '');
            o.textContent = (d / 1000) + ' s';
            o.onclick = function (e) {
              e.stopPropagation();
              delay = d;
              try { localStorage.setItem('galinf-slideshow-delay', String(d)); } catch (err) {}
              delayLabel.textContent = (d / 1000) + 's';
              var cur = pop.querySelector('.galinf-delay-cur');
              if (cur) cur.classList.remove('galinf-delay-cur');
              o.classList.add('galinf-delay-cur');
              el.classList.remove('galinf-open');
              if (playing) ssSchedule();   // new delay applied right away
            };
            pop.appendChild(o);
          });
          el.appendChild(pop);

          el.onclick = function (e) {
            e.stopPropagation();
            el.classList.toggle('galinf-open');
          };
          var closePop = function () { el.classList.remove('galinf-open'); };
          document.addEventListener('click', closePop);
          pswp.on('destroy', function () { document.removeEventListener('click', closePop); });
        }
      });

      /* ---- 90° COUNTER-CLOCKWISE rotation (order 7.5) ----
         Same direction as the icon. The angle ACCUMULATES (−90, −180, −270,
         −360…) without ever wrapping with a modulo: the CSS transition
         therefore always turns the same way (a modulo would bring 270→0
         backwards, visually awkward). Display only: nothing is written;
         reset when the image changes. At 90°/270°, the image is scaled down
         to stay whole. */
      var rot = 0, rotImg = null;

      function applyRot() {
        var c = pswp.currSlide && pswp.currSlide.content;
        var img = c && c.element;
        if (!img || !img.style) return;
        rotImg = img;
        if (rot === 0) {
          img.style.transform = '';
          return;
        }
        var s = 1;
        if (rot % 180 !== 0) {
          var w = img.offsetWidth, h = img.offsetHeight;
          if (w > 0 && h > 0) s = Math.min(w, h) / Math.max(w, h);
        }
        img.style.transform = 'rotate(' + rot + 'deg)' + (s !== 1 ? ' scale(' + s + ')' : '');
      }
      function rotate() {
        rot -= 90;   // counter-clockwise, cumulative (never brought back to 0 by modulo)
        applyRot();
      }

      pswp.on('change', function () {
        if (rotImg && rotImg.style) rotImg.style.transform = '';
        rot = 0;
        rotImg = null;
      });

      if (btnOn('rotate'))
      ui.registerElement({
        name: 'galinf-rotate', order: 7.5, isButton: true,
        title: t('ROTATE'),
        html: '<svg class="pswp__icn" viewBox="0 0 24 24" width="32" height="32" aria-hidden="true">'
            + '<path d="M12 5V2L7.5 6 12 10V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7z"/></svg>',
        onInit: function (el, pswp) {
          el.onclick = rotate;
        }
      });

      /* ---- download of the original (order 8) ----
         Native link with a download attribute (same origin → the browser
         saves the file under its real name instead of opening it). */
      if (btnOn('download'))
      ui.registerElement({
        name: 'galinf-download', order: 8, isButton: true, tagName: 'a',
        title: t('DOWNLOAD'),
        html: '<svg class="pswp__icn" viewBox="0 0 24 24" width="32" height="32" aria-hidden="true">'
            + '<path d="M12 15.6 6.8 10.4l1.4-1.4 2.8 2.8V3h2v8.8l2.8-2.8 1.4 1.4zM5 19v-4H3v4a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4h-2v4z"/></svg>',
        onInit: function (el, pswp) {
          el.rel = 'noopener';
          var sync = function () {
            var d = pswp.currSlide && pswp.currSlide.data;
            if (d && d.src) {
              el.href = d.src;
              el.setAttribute('download', baseName(d.src));
            }
          };
          pswp.on('change', sync);
          sync();
        }
      });

      /* ---- fullscreen (order 9) ----
         Hidden if the Fullscreen API does not exist (old iOS). */
      if (btnOn('fullscreen') && document.documentElement.requestFullscreen) {
        ui.registerElement({
          name: 'galinf-fullscreen', order: 9, isButton: true,
          title: t('FULLSCREEN'),
          html: '<svg class="pswp__icn" viewBox="0 0 24 24" width="32" height="32" aria-hidden="true">'
              + '<path class="galinf-fs-enter" d="M5 5h5v2H7v3H5zm9 0h5v5h-2V7h-3zM5 14h2v3h3v2H5zm12 0h2v5h-5v-2h3z"/>'
              + '<path class="galinf-fs-exit" d="M8 8V5h2v5H5V8zm8 0h3v2h-5V5h2zM8 16H5v-2h5v5H8zm8 0v3h-2v-5h5v2z"/></svg>',
          onInit: function (el, pswp) {
            var target = pswp.element;   // .pswp container
            var toggle = function () {
              if (document.fullscreenElement) {
                document.exitFullscreen();
              } else {
                target.requestFullscreen();
              }
            };
            el.onclick = toggle;
            var onChange = function () {
              el.classList.toggle('galinf-fs-on', !!document.fullscreenElement);
            };
            document.addEventListener('fullscreenchange', onChange);
            pswp.on('destroy', function () {
              document.removeEventListener('fullscreenchange', onChange);
              if (document.fullscreenElement) {
                document.exitFullscreen();
              }
            });
          }
        });
      }

      /* ---- layout shared by the sidebar + thumbnail strip ----
         PhotoSwipe dynamic padding: reserves room for the panel (right on
         desktop, bottom sheet on mobile) and the strip (bottom). Re-evaluated
         on every updateSize/resize. */
      var SB_W = 320;                            // panel width (px)
      var STRIP_H = 78;                          // strip height (px)
      var sbOpen = false;                        // (stays false if the panel is off)
      var stripOn = stripOpenDefault;            // admin state (enforced if no button)
      if (!stripForced) {
        try {
          var stripStored = localStorage.getItem('galinf-strip-open');
          if (stripStored !== null) stripOn = stripStored === '1';
        } catch (e) {}
      }
      var stripRender = null;                    // set by the strip block

      var sbNarrow = function () { return window.innerWidth < 700; };

      pswp.options.paddingFn = function () {
        var pad = { top: 0, bottom: 0, left: 0, right: 0 };
        if (sbOpen) {
          if (sbNarrow()) pad.bottom = Math.round(window.innerHeight * 0.42);
          else pad.right = SB_W;
        }
        // Mobile bottom sheet open → it covers the strip area (hidden).
        if (stripOn && !(sbOpen && sbNarrow())) {
          pad.bottom = Math.max(pad.bottom, STRIP_H);
        }
        return pad;
      };

      /* ---- info panel (right sidebar / bottom sheet on mobile) ----
         Every key of the image's .meta.yaml (one block per key, class
         galinf-meta-<key>), + Comments section.
         The panel PUSHES the image through PhotoSwipe's dynamic padding.
         Open/closed state remembered (localStorage). */
      if (metaUrl) {
        sbOpen = sbOpenDefault;                  // admin state (enforced if no button)
        if (!sbForced) {
          try {
            var sbStored = localStorage.getItem('galinf-sidebar-open');
            if (sbStored !== null) sbOpen = sbStored === '1';
          } catch (e) {}
        }
        var sbPanel = null, sbMeta = null, sbHead = null, sbBtn = null;

        function sbApply() {
          if (sbPanel) sbPanel.classList.toggle('galinf-sb-open', sbOpen);
          if (sbBtn) sbBtn.classList.toggle('galinf-sb-on', sbOpen);
          // Class on the PhotoSwipe root: lets the CSS shift the "next"
          // arrow (otherwise it slips under the panel).
          pswp.element.classList.toggle('galinf-sb-active', sbOpen);
          if (!sbForced) {
            try { localStorage.setItem('galinf-sidebar-open', sbOpen ? '1' : '0'); } catch (e) {}
          }
          pswp.updateSize(true);
          if (stripRender) stripRender();   // the strip readjusts (width)
        }
        function sbToggle() {
          sbOpen = !sbOpen;
          if (sbOpen) sbRender();
          sbApply();
        }
        function sbClose() {
          if (!sbOpen) return;
          sbOpen = false;
          sbApply();
        }

        // Content: header (file name, article, dimensions), then one block
        // per key (label = key name, value as plain text via textContent —
        // never any injected HTML).
        function sbHeadLine(cls, text) {
          var d = document.createElement('div');
          d.className = cls;
          d.textContent = text;
          sbHead.appendChild(d);
        }
        function sbRender() {
          if (!sbMeta) return;
          var data = pswp.currSlide && pswp.currSlide.data;
          sbMeta.innerHTML = '';
          sbHead.innerHTML = '';
          if (!data || !data._route || !data.src) return;
          var file = baseName(data.src);

          sbHeadLine('galinf-sb-file', file);
          // "Article: <title>" — link to the article, except on the article
          // page itself ("(this page)"). Replaces the old top bar "View the
          // article" button.
          if (data.alt) {
            var art = document.createElement('div');
            art.className = 'galinf-sb-article';
            art.appendChild(document.createTextNode(t('ARTICLE') + ' '));
            if (article) {
              art.appendChild(document.createTextNode(t('ARTICLE_CURRENT', { title: data.alt })));
            } else {
              var a = document.createElement('a');
              a.href = base + (data._route || '');
              a.textContent = data.alt;
              art.appendChild(a);
            }
            sbHead.appendChild(art);
          }
          if (data.width && data.height) {
            sbHeadLine('galinf-sb-dims', data.width + ' × ' + data.height + ' px');
          }
          fetchMeta(data._route).then(function (payload) {
            if (!pswp.currSlide || pswp.currSlide.data !== data) return; // slide changed
            sbMeta.innerHTML = '';
            var meta = (payload.meta || {})[file];
            var keys = meta ? Object.keys(meta) : [];
            // Nothing to show → the section simply stays empty (no "No
            // information" message).
            keys.forEach(function (k) {
              var block = document.createElement('div');
              block.className = 'galinf-meta galinf-meta-' + k.replace(/[^a-zA-Z0-9_-]/g, '-');
              var lab = document.createElement('span');
              lab.className = 'galinf-meta-label';
              lab.textContent = k;
              var val = document.createElement('div');
              val.className = 'galinf-meta-value';
              // List (e.g. `prompts:`): one line per item. Scalar: plain
              // text, as before.
              if (Array.isArray(meta[k])) {
                meta[k].forEach(function (line) {
                  var p = document.createElement('div');
                  p.className = 'galinf-meta-line';
                  p.textContent = line;
                  val.appendChild(p);
                });
              } else {
                val.textContent = meta[k];
              }
              block.appendChild(lab);
              block.appendChild(val);
              sbMeta.appendChild(block);
            });
            sbComments(payload, file, data);
          });
        }

        /* ---- comments (lower section of the sidebar) ---- */
        var comSection = null, comList = null, comForm = null, comMsg = null;
        var flashMsg = '';   // message shown after the next render (success…)

        function postComment(body) {
          return fetch(commentUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Galinf': '1' },
            body: JSON.stringify(body)
          }).then(function (r) {
            return r.json().then(function (j) { return j; })
              .catch(function () { return { ok: false, code: 'bad_response' }; });
          }).catch(function () {
            return { ok: false, code: 'network' };
          });
        }

        /* Server refusal: its code (e.g. 'rate_limited') gives the
           ERR_RATE_LIMITED key; a code unknown to the dictionary → the
           English sentence sent by the server, otherwise a generic message. */
        function errorText(res) {
          var key = res && res.code ? 'ERR_' + String(res.code).toUpperCase() : '';
          var msg = key ? t(key) : key;
          if (msg && msg !== key) return msg;
          return (res && res.error) || t('ERR_SEND');
        }

        /* Anti-bot question asked in the visitor's language. */
        function captchaText(c) {
          return (c && c.a !== undefined) ? t('CAPTCHA', { a: c.a, b: c.b }) : ((c && c.q) || '');
        }

        /* ---- Form plugin captchas (Cap, Turnstile): script loaded on first
           use only, when a visitor opens the form ---- */
        var scripts = {};
        function loadScript(src) {
          if (!scripts[src]) {
            scripts[src] = new Promise(function (resolve, reject) {
              var s = document.createElement('script');
              s.src = src;
              s.async = true;
              s.onload = resolve;
              s.onerror = function () { delete scripts[src]; reject(new Error('cannot load ' + src)); };
              document.head.appendChild(s);
            });
          }
          return scripts[src];
        }

        /* Cap: proof of work solved in the background (invisible, no
           click). One token per comment, valid across slides: solved when the
           form opens, again after each send. Older than 15 min = stale (the
           server keeps them 20). Safari 17 and older crash with several
           workers (see the Form plugin's cap-handler.js): one there. */
        var CAP_MAX_AGE = 15 * 60 * 1000;
        var capSingleWorker = (function () {
          if (navigator.vendor !== 'Apple Computer, Inc.') return false;
          var ua = navigator.userAgent;
          var m = ua.match(/\b(?:iPhone|iPad|iPod)\b.*?\bOS (\d+)_/) || ua.match(/\bVersion\/(\d+)/);
          return !!m && parseInt(m[1], 10) < 18;
        })();
        var capSolver = null, capToken = '', capAt = 0, capSolving = null;
        function capSolve(c) {
          if (capToken && Date.now() - capAt < CAP_MAX_AGE) return Promise.resolve(capToken);
          if (capSolving) return capSolving;
          // Read by cap.min.js as it runs: the Form plugin's copies rather
          // than jsDelivr.
          if (!window.CAP_CUSTOM_WASM_URL) window.CAP_CUSTOM_WASM_URL = c.wasm;
          if (!window.CAP_PAKO_URL) window.CAP_PAKO_URL = c.pako;
          capToken = '';
          capSolving = loadScript(c.script).then(function () {
            if (!capSolver) {
              var opts = { apiEndpoint: c.endpoint };
              if (capSingleWorker) opts['data-cap-worker-count'] = 1;
              capSolver = new window.Cap(opts);
            } else {
              try { capSolver.reset(); } catch (e) { /* ignore */ }
            }
            return capSolver.solve();
          }).then(function (r) {
            capSolving = null;
            if (!r || !r.token) throw new Error('no Cap token');
            capToken = r.token;
            capAt = Date.now();
            return capToken;
          }, function (err) {
            capSolving = null;
            throw err;
          });
          return capSolving;
        }

        /* Turnstile and reCAPTCHA v2: widget rendered in the form when it
           is opened (one challenge per opening, not one per slide), dropped
           when the form is rebuilt. reCAPTCHA v3 has no widget: a token is
           asked for at send time. */
        var TURNSTILE_JS = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        var RECAPTCHA_JS = 'https://www.google.com/recaptcha/api.js';
        var widget = null;   // { c, id, token, waiting }

        function captchaApi(c) {
          if (c.provider === 'turnstile') {
            return loadScript(TURNSTILE_JS).then(function () { return window.turnstile; });
          }
          var src = RECAPTCHA_JS + '?render=' + (c.version === '3' ? encodeURIComponent(c.sitekey) : 'explicit')
            + '&hl=' + encodeURIComponent(i18n.lang || 'en');
          return loadScript(src).then(function () {
            return new Promise(function (resolve) {
              window.grecaptcha.ready(function () { resolve(window.grecaptcha); });
            });
          });
        }

        function widgetRemove() {
          if (widget && widget.id !== null && widget.c.provider === 'turnstile') {
            try { window.turnstile.remove(widget.id); } catch (e) { /* ignore */ }
          }
          // reCAPTCHA has no remove: its iframe goes with the old form.
          widget = null;
        }

        function widgetRender(c, box) {
          if (widget) return;
          var w = widget = { c: c, id: null, token: '', waiting: null };
          var settle = function (tok) {
            w.token = tok || '';
            if (w.waiting) { w.waiting(w.token); w.waiting = null; }
          };
          var opts = {
            sitekey: c.sitekey,
            callback: settle,
            'expired-callback': function () { w.token = ''; },
            'error-callback': function () { settle(''); }
          };
          if (c.theme) opts.theme = c.theme;
          // Normal widgets are 300 px (Turnstile) or 304 px (reCAPTCHA)
          // wide, more than the side panel offers (the bottom sheet on
          // phones has room).
          var wide = box.clientWidth >= (c.provider === 'turnstile' ? 300 : 304);
          if (c.provider === 'turnstile') {
            opts.language = i18n.lang || 'auto';
            opts.size = wide ? 'normal' : 'compact';
          } else if (c.version === '2-invisible') {
            opts.size = 'invisible';
            opts.badge = 'inline';
          } else {
            opts.size = wide ? 'normal' : 'compact';
          }
          captchaApi(c).then(function (api) {
            if (widget !== w || !box.isConnected) return;   // form rebuilt meanwhile
            w.id = api.render(box, opts);
          }).catch(function () {
            if (widget === w) widget = null;
            comMsg.textContent = t('ERR_CAPTCHA_FAILED');
          });
        }

        /* Token for a send: the one the visitor already obtained (Turnstile,
           reCAPTCHA checkbox), or one asked for now (invisible reCAPTCHA,
           v3). Rejects when there is none. */
        function widgetToken(c) {
          var got;
          if (c.provider === 'recaptcha' && c.version === '3') {
            got = captchaApi(c).then(function (api) {
              return api.execute(c.sitekey, { action: c.action });
            });
          } else if (!widget || widget.id === null) {
            got = Promise.resolve('');
          } else if (c.provider === 'recaptcha' && c.version === '2-invisible') {
            var w = widget;
            got = new Promise(function (resolve) {
              w.waiting = resolve;
              // Closing Google's image challenge calls nothing back: give
              // up after 2 minutes.
              setTimeout(function () {
                if (w.waiting === resolve) { w.waiting = null; resolve(''); }
              }, 120000);
              window.grecaptcha.execute(w.id);
            });
          } else {
            got = Promise.resolve(widget.token);
          }
          return got.then(function (tok) {
            if (!tok) throw new Error('no captcha token');
            return tok;
          });
        }

        function widgetReset() {
          var w = widget;
          if (!w) return;
          w.token = '';
          if (w.id === null) return;
          try {
            (w.c.provider === 'turnstile' ? window.turnstile : window.grecaptcha).reset(w.id);
          } catch (e) { /* ignore */ }
        }

        function refreshAfterWrite(route) {
          delete metaCache[route];
          sbRender();
        }

        function sbComments(payload, file, data) {
          if (!comSection) return;
          if (!payload.comments_enabled) {
            comSection.style.display = 'none';
            return;
          }
          comSection.style.display = '';
          comList.innerHTML = '';
          widgetRemove();   // its box goes with the old form
          comForm.innerHTML = '';
          comMsg.textContent = flashMsg;
          flashMsg = '';

          var list = (payload.comments || {})[file] || [];
          if (!list.length) {
            var none = document.createElement('p');
            none.className = 'galinf-sb-none';
            none.textContent = t('NO_COMMENTS');
            comList.appendChild(none);
          }
          /* Only APPROVED comments arrive here: moderation happens in the
             admin (Flex directory), no longer in the lightbox. */
          list.forEach(function (c) {
            var div = document.createElement('div');
            div.className = 'galinf-comment';
            var head = document.createElement('div');
            head.className = 'galinf-comment-head';
            var who = document.createElement('span');
            who.className = 'galinf-comment-name';
            who.textContent = c.name || 'Anon';
            var when = document.createElement('span');
            when.className = 'galinf-comment-date';
            when.textContent = fmtDate(c.date);
            head.appendChild(who);
            head.appendChild(when);
            div.appendChild(head);
            var txt = document.createElement('div');
            txt.className = 'galinf-comment-text';
            txt.textContent = c.text;   // plain text, never HTML
            div.appendChild(txt);
            comList.appendChild(div);
          });

          if (!payload.can_comment) {
            if (payload.comments_enabled && !payload.authed) {
              var note = document.createElement('p');
              note.className = 'galinf-sb-none';
              note.textContent = t('LOGIN_TO_COMMENT');
              comForm.appendChild(note);
            }
            return;
          }

          /* Daily cap of the site already reached: a notice instead of a
             form whose comment would be refused when sent. */
          if (payload.daily_full) {
            var full = document.createElement('p');
            full.className = 'galinf-sb-none';
            full.textContent = t('DAILY_FULL');
            comForm.appendChild(full);
            return;
          }

          /* Anti-bot check: 'galinf' (sum question), 'cap', 'turnstile',
             'recaptcha', or 'unavailable' (misconfigured on the server: no
             form). None for a logged-in visitor. */
          var cap = payload.captcha;
          var kind = cap ? (cap.provider || 'galinf') : '';
          var isWidget = kind === 'turnstile' || kind === 'recaptcha';
          // Checks the visitor must pass BEFORE sending (the others work
          // unseen, at send time).
          var mustSolve = kind === 'turnstile' || (kind === 'recaptcha' && cap.version === '2-checkbox');
          if (kind === 'unavailable') {
            var off = document.createElement('p');
            off.className = 'galinf-sb-none';
            off.textContent = t('CAPTCHA_UNAVAILABLE');
            comForm.appendChild(off);
            return;
          }

          /* --- form, folded behind "Add a comment" ---
             ANIMATED opening in pure CSS (grid-template-rows 0fr→1fr), no
             dependency: the plugin works on any theme. */
          var addBtn = document.createElement('button');
          addBtn.type = 'button';
          addBtn.className = 'galinf-comment-add';
          addBtn.textContent = t('ADD_COMMENT');
          comForm.appendChild(addBtn);

          var fieldsBox = document.createElement('div');
          fieldsBox.className = 'galinf-comment-fields';
          var fields = document.createElement('div');
          fields.className = 'galinf-comment-fields-in';
          fieldsBox.appendChild(fields);
          comForm.appendChild(fieldsBox);

          var nameInput = null;
          if (!payload.authed) {
            nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.maxLength = 60;
            nameInput.placeholder = t(payload.need_name ? 'NAME_REQUIRED' : 'NAME_OPTIONAL');
            try { nameInput.value = localStorage.getItem('galinf-pseudo') || ''; } catch (e) {}
            fields.appendChild(nameInput);
          }

          var ta = document.createElement('textarea');
          ta.maxLength = payload.max_len || 300;
          ta.rows = 3;
          ta.placeholder = t('COMMENT_PLACEHOLDER');
          fields.appendChild(ta);

          var counter = document.createElement('div');
          counter.className = 'galinf-counter';
          // Shown only once less than 20 % of the limit is left, unless the
          // admin wants it always visible.
          var updateCounter = function () {
            var left = ta.maxLength - ta.value.length;
            counter.textContent = t('CHARS_LEFT', { n: left });
            counter.hidden = !payload.counter_always && left >= ta.maxLength * 0.2;
          };
          ta.addEventListener('input', updateCounter);
          updateCounter();
          fields.appendChild(counter);

          var capInput = null, wBox = null;
          if (isWidget) {
            wBox = document.createElement('div');
            wBox.className = 'galinf-captcha-widget';
            fields.appendChild(wBox);
          } else if (kind === 'galinf') {
            var capLabel = document.createElement('label');
            capLabel.className = 'galinf-captcha';
            var capText = document.createElement('span');
            capText.textContent = captchaText(payload.captcha);
            capInput = document.createElement('input');
            capInput.type = 'text';
            capInput.inputMode = 'numeric';
            capInput.autocomplete = 'off';
            capInput.size = 4;
            capLabel.appendChild(capText);
            capLabel.appendChild(capInput);
            fields.appendChild(capLabel);
          }

          // Honeypot: hidden in CSS, a human never fills it in.
          var hp = document.createElement('input');
          hp.type = 'text';
          hp.name = 'website';
          hp.className = 'galinf-hp';
          hp.tabIndex = -1;
          hp.autocomplete = 'off';
          fields.appendChild(hp);

          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'galinf-comment-send';
          btn.textContent = t('SEND');
          btn.onclick = function () {
            var text = ta.value.trim();
            if (!text) { comMsg.textContent = t('ERR_EMPTY'); return; }
            if (payload.need_name && nameInput && nameInput.value.trim() === '') {
              comMsg.textContent = t('ERR_NAME_REQUIRED');
              return;
            }
            if (mustSolve && !(widget && widget.token)) {
              comMsg.textContent = t('CAPTCHA_TODO');
              return;
            }
            btn.disabled = true;
            if (nameInput) {
              try { localStorage.setItem('galinf-pseudo', nameInput.value.trim()); } catch (e) {}
            }
            // Answer of the check: the sum typed in, or a widget token
            // (Cap: waits for the proof of work if it is still running).
            var answer;
            if (kind === 'cap') {
              comMsg.textContent = t('CAPTCHA_WAIT');
              answer = capSolve(cap).then(function (tok) {
                capToken = '';   // spent by this send
                return tok;
              });
            } else if (isWidget) {
              comMsg.textContent = t('CAPTCHA_WAIT');
              answer = widgetToken(cap);
            } else {
              answer = Promise.resolve(capInput ? capInput.value : '');
            }
            answer.then(function (value) {
              comMsg.textContent = t('SENDING');
              return postComment({
                route: data._route,
                file: file,
                name: nameInput ? nameInput.value : '',
                text: text,
                captcha: value,
                token: kind === 'galinf' ? cap.token : '',
                website: hp.value
              });
            }, function () {
              return { ok: false, code: 'captcha_failed' };
            }).then(function (res) {
              btn.disabled = false;
              if (res && res.ok) {
                flashMsg = t(res.pending ? 'THANKS_PENDING' : 'THANKS');
                refreshAfterWrite(data._route);
                return;
              }
              if (res && res.code === 'daily_limit') {
                // Cap reached while writing: same notice as on opening.
                payload.daily_full = true;
                sbComments(payload, file, data);
                return;
              }
              comMsg.textContent = errorText(res);
              // The token presented is consumed on the server side: the
              // refusal brings a new question, to show instead; a widget
              // gets a new token.
              if (res && res.captcha && capInput) {
                payload.captcha = cap = res.captcha;
                capText.textContent = captchaText(res.captcha);
                capInput.value = '';
              } else if (kind === 'cap') {
                capSolve(cap).catch(function () {});
              } else if (isWidget) {
                widgetReset();
              }
            });
          };
          fields.appendChild(btn);

          addBtn.onclick = function () {
            var open = fieldsBox.classList.toggle('galinf-open');
            addBtn.classList.toggle('galinf-on', open);
            if (!open) return;
            ta.focus();
            // Widget started when the form opens (no-op if it already runs
            // or holds a fresh token): it works while the visitor types.
            if (kind === 'cap') capSolve(cap).catch(function () {});
            else if (kind === 'recaptcha' && cap.version === '3') captchaApi(cap).catch(function () {});
            else if (isWidget) widgetRender(cap, wBox);
          };
        }

        ui.registerElement({
          name: 'galinf-sidebar', order: 1, appendTo: 'root', tagName: 'aside',
          onInit: function (el, pswp) {
            sbPanel = el;
            var inner = document.createElement('div');
            inner.className = 'galinf-sb-inner';

            // The panel's own close button.
            // Close cross — not in ENFORCED state (the visitor could not
            // reopen the panel, for lack of an "i" button).
            if (!sbForced) {
              var closeBtn = document.createElement('button');
              closeBtn.type = 'button';
              closeBtn.className = 'galinf-sb-close';
              closeBtn.title = t('CLOSE_PANEL');
              closeBtn.textContent = '×';
              closeBtn.onclick = sbClose;
              inner.appendChild(closeBtn);
            }

            sbHead = document.createElement('div');
            sbHead.className = 'galinf-sb-head';
            inner.appendChild(sbHead);

            sbMeta = document.createElement('div');
            sbMeta.className = 'galinf-sb-meta';
            inner.appendChild(sbMeta);

            // Comments section (list + form), hidden if disabled.
            comSection = document.createElement('div');
            comSection.className = 'galinf-sb-comments';
            comSection.style.display = 'none';
            var comTitle = document.createElement('h3');
            comTitle.textContent = t('COMMENTS');
            comList = document.createElement('div');
            comList.className = 'galinf-comments-list';
            comMsg = document.createElement('p');
            comMsg.className = 'galinf-comment-msg';
            comForm = document.createElement('div');
            comForm.className = 'galinf-comment-form';
            comSection.appendChild(comTitle);
            comSection.appendChild(comList);
            comSection.appendChild(comMsg);
            comSection.appendChild(comForm);
            inner.appendChild(comSection);

            el.appendChild(inner);

            pswp.on('change', function () { if (sbOpen) sbRender(); });
            if (sbOpen) { sbRender(); sbApply(); }
          }
        });

        // "i" button before close (20).
        if (btnOn('info'))
        ui.registerElement({
          name: 'galinf-info', order: 15, isButton: true,
          title: t('INFO'),
          html: '<svg class="pswp__icn" viewBox="0 0 24 24" width="32" height="32" aria-hidden="true">'
              + '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16zm-1 3h2v2h-2zm0 4h2v6h-2z"/></svg>',
          onInit: function (el, pswp) {
            sbBtn = el;
            if (sbOpen) el.classList.add('galinf-sb-on');
            el.onclick = sbToggle;
          }
        });
      }

      /* ---- thumbnail strip (bottom, both modes) ----
         VIRTUALIZED: fixed-size cells positioned by index, only ~the visible
         ones (+ margin) exist in the DOM, recycled on scroll — no risk even
         with tens of thousands of images. Click → goTo(i); current thumbnail
         highlighted and auto-centered (the slideshow stays visible, the
         indicator follows). Top bar button, remembered state.
         Pushed/narrowed by the sidebar (CSS galinf-sb-active). */
      (function () {
        var CELL_W = 84, CELL_GAP = 8, CELL = CELL_W + CELL_GAP;
        var ds = pswp.options.dataSource || [];
        var n = ds.length;
        if (!n) return;

        var stripEl = null, scroller = null, runwayEl = null;
        var thumbs = new Map(), pool = [];
        var curEl = null;

        function stripFallback() {
          if (this._full && this._src !== this._full) {
            this._src = this._full;
            this.src = this._full;
          }
        }

        function render() {
          if (!scroller || !stripOn) return;
          var viewW = scroller.clientWidth;
          var first = Math.max(0, Math.floor(scroller.scrollLeft / CELL) - 8);
          var last  = Math.min(n - 1, Math.ceil((scroller.scrollLeft + viewW) / CELL) + 8);

          thumbs.forEach(function (img, idx) {
            if (idx < first || idx > last) {
              img.style.display = 'none';
              pool.push(img);
              thumbs.delete(idx);
            }
          });
          for (var i = first; i <= last; i++) {
            var img = thumbs.get(i);
            if (!img) {
              img = pool.pop();
              if (!img) {
                img = document.createElement('img');
                img.className = 'galinf-th';
                img.decoding = 'async';
                runwayEl.appendChild(img);
              }
              img.style.display = '';
              var src = ds[i] && (ds[i].msrc || ds[i].src);
              if (img._src !== src) {
                img._src = src;
                // Thumbnail gone from disk → fall back on the original (only
                // once; _full is reread when the error happens because the
                // <img> element is recycled from one cell to another).
                img._full = ds[i] ? ds[i].src : '';
                img.onerror = stripFallback;
                img.src = src || '';
              }
              img.dataset.idx = i;
              img.style.transform = 'translateX(' + (i * CELL) + 'px)';
              thumbs.set(i, img);
            }
            img.classList.toggle('galinf-th-cur', i === pswp.currIndex);
          }
        }
        stripRender = render;

        // Centers the current thumbnail (instant on opening, smooth after).
        function centerCurrent(smooth) {
          if (!scroller || !stripOn) return;
          var target = pswp.currIndex * CELL - (scroller.clientWidth - CELL_W) / 2;
          scroller.scrollTo({ left: Math.max(0, target), behavior: smooth ? 'smooth' : 'auto' });
        }

        function stripApply(btn) {
          if (stripEl) stripEl.classList.toggle('galinf-strip-open', stripOn);
          if (btn) btn.classList.toggle('galinf-strip-on', stripOn);
          if (!stripForced) {
            try { localStorage.setItem('galinf-strip-open', stripOn ? '1' : '0'); } catch (e) {}
          }
          pswp.updateSize(true);
          if (stripOn) { render(); centerCurrent(false); }
        }

        ui.registerElement({
          name: 'galinf-strip', order: 2, appendTo: 'root',
          onInit: function (el, pswp) {
            stripEl = el;
            scroller = document.createElement('div');
            scroller.className = 'galinf-strip-scroll';
            runwayEl = document.createElement('div');
            runwayEl.className = 'galinf-strip-run';
            runwayEl.style.width = (n * CELL) + 'px';
            scroller.appendChild(runwayEl);
            el.appendChild(scroller);

            // Click (delegated) → jump straight to the image.
            scroller.addEventListener('click', function (e) {
              var t = e.target;
              if (t && t.classList && t.classList.contains('galinf-th')) {
                var idx = parseInt(t.dataset.idx, 10);
                if (!isNaN(idx)) pswp.goTo(idx);
              }
            });

            var ticking = false;
            scroller.addEventListener('scroll', function () {
              if (!ticking) {
                ticking = true;
                requestAnimationFrame(function () { ticking = false; render(); });
              }
            }, { passive: true });

            var onResize = function () { render(); centerCurrent(false); };
            window.addEventListener('resize', onResize);
            pswp.on('destroy', function () { window.removeEventListener('resize', onResize); });

            pswp.on('change', function () {
              if (!stripOn) return;
              render();
              centerCurrent(true);
            });

            if (stripOn) el.classList.add('galinf-strip-open');
            // First render once the lightbox has its final size.
            requestAnimationFrame(function () { render(); centerCurrent(false); });
          }
        });

        if (btnOn('strip'))
        ui.registerElement({
          name: 'galinf-thumbs', order: 7, isButton: true,
          title: t('THUMBNAILS'),
          html: '<svg class="pswp__icn" viewBox="0 0 24 24" width="32" height="32" aria-hidden="true">'
              + '<path d="M2 8h5v8H2zm7 0h6v8H9zm8 0h5v8h-5z"/></svg>',
          onInit: function (el, pswp) {
            if (stripOn) el.classList.add('galinf-strip-on');
            var toggle = function () { stripOn = !stripOn; stripApply(el); };
            el.onclick = toggle;
          }
        });
      })();
    });
  };
})();
