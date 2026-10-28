/* Galinf — gallery component (shared by the gallery page + article page).
 * Step 1: feed + justified layout + infinite scroll + lazy loading.
 * Step 2: DOM virtualization / recycling (tile pool, ~bounding).
 * Step 3: PhotoSwipe v5 lightbox (dataSource) + "View the article" button.
 * Step 4: order menu (asc / desc / random) with reset; the criterion
 *         (date or manual folder order) is set on the admin side (data-field).
 * Block 4: article page mode (data-route) — source = a single article,
 *          upload order, and "View the article" button hidden (we are there).
 * Vanilla JS, no dependency except PhotoSwipe (dynamic ESM). */
(function () {
  'use strict';

  /* Canonical order: asc | desc | random | commented
     ('recent'/'oldest' = legacy aliases). */
  function normSort(s) {
    if (s === 'asc' || s === 'oldest') return 'asc';
    if (s === 'random') return 'random';
    if (s === 'commented') return 'commented';
    return 'desc';
  }

  /* ---- visitor texts (i18n) ----
     The server injects data-i18n = { lang, dicts } (classes/I18n.php).
     lang set = language declared in Grav, imposed. Otherwise the choice is
     made here from the browser: the page is cached, the server cannot do
     it. English as a last resort. Formats recognized in the texts: {name}
     and the ICU plural {n, plural, =0{…} one{…} other{…}} (# = the number).
     Shared with galinf-lightbox.js: window.galinfI18n. */
  function makeI18n(root) {
    var conf = {};
    try { conf = JSON.parse(root.dataset.i18n || '{}') || {}; } catch (e) {}
    var dicts = conf.dicts || {};
    var lang = conf.lang || '', locale = lang;
    if (!lang) {
      var prefs = (navigator.languages && navigator.languages.length)
        ? navigator.languages : [navigator.language || ''];
      for (var i = 0; i < prefs.length && !lang; i++) {
        var code = String(prefs[i] || '').toLowerCase().split('-')[0];
        if (dicts[code]) { lang = code; locale = prefs[i]; }
      }
    }
    if (!dicts[lang]) lang = dicts.en ? 'en' : (Object.keys(dicts)[0] || 'en');
    if (!locale) locale = lang;
    var dict = dicts[lang] || {};
    var rules = null, numFmt = null;
    try { rules = new Intl.PluralRules(locale); numFmt = new Intl.NumberFormat(locale); } catch (e) {}

    function num(n) { return numFmt ? numFmt.format(n) : String(n); }

    /* Index of the closing brace matching the one at position i. */
    function closing(s, i) {
      for (var d = 0, j = i; j < s.length; j++) {
        if (s.charAt(j) === '{') d++;
        else if (s.charAt(j) === '}' && --d === 0) return j;
      }
      return -1;
    }

    function plural(opts, n, params) {
      var cat = rules ? rules.select(n) : (n === 1 ? 'one' : 'other');
      var exact = null, byCat = null, other = null, re = /\s*(=\d+|[a-z]+)\s*\{/g, m;
      while ((m = re.exec(opts))) {
        var start = re.lastIndex - 1, end = closing(opts, start);
        if (end < 0) break;
        var txt = opts.slice(start + 1, end);
        if (m[1] === '=' + n) exact = txt;
        else if (m[1] === cat) byCat = txt;
        else if (m[1] === 'other') other = txt;
        re.lastIndex = end + 1;
      }
      var pick = exact !== null ? exact : byCat !== null ? byCat : (other || '');
      return format(pick.replace(/#/g, num(n)), params);
    }

    function format(s, params) {
      var out = '', i = 0;
      while (i < s.length) {
        var c = s.charAt(i);
        if (c !== '{') { out += c; i++; continue; }
        var end = closing(s, i);
        if (end < 0) { out += s.slice(i); break; }
        var body = s.slice(i + 1, end), parts = body.split(',');
        var name = parts[0].trim(), v = params ? params[name] : undefined;
        if (parts.length > 2 && parts[1].trim() === 'plural') {
          out += plural(body.slice(body.indexOf(',', body.indexOf(',') + 1) + 1), Number(v) || 0, params);
        } else if (v === undefined) {
          out += '{' + body + '}';
        } else {
          out += typeof v === 'number' ? num(v) : String(v);
        }
        i = end + 1;
      }
      return out;
    }

    return {
      lang: lang,
      locale: locale,
      num: num,
      t: function (key, params) {
        var s = dict[key];
        return typeof s === 'string' ? format(s, params) : key;
      }
    };
  }

  window.galinfI18n = function (root) {
    return root._galinfI18n || (root._galinfI18n = makeI18n(root));
  };

  function init(root) {
    var i18n = window.galinfI18n(root), t = i18n.t;
    var feed      = root.dataset.feed;
    var base      = root.dataset.base || '';
    var article   = root.dataset.route || null;   // article page mode if set
    // Order: asc | desc | random (old recent/oldest aliases tolerated).
    var sort      = normSort(root.dataset.sort);
    // Server criterion: date | folder (admin manual order).
    var field     = root.dataset.field === 'folder' ? 'folder' : 'date';
    var batch     = parseInt(root.dataset.batch, 10) || 80;
    var rowHeight = parseInt(root.dataset.rowHeight, 10) || 220;
    var gap       = parseInt(root.dataset.gap, 10) || 6;
    var MARGIN      = parseInt(root.dataset.margin, 10) || 500;
    var loop        = root.dataset.loop === '1';
    // Metadata buffer: number of items kept ahead beyond the last rendered
    // tile (0 = unlimited → the whole index in the background).
    var lookahead   = parseInt(root.dataset.lookahead, 10);
    if (isNaN(lookahead) || lookahead < 0) lookahead = 800;

    var runway     = root.querySelector('.galinf-runway');
    var status     = root.querySelector('.galinf-status');
    var sortSelect = root.querySelector('.galinf-sort');

    // Elements written by the server (sort menu): in the chosen language.
    Array.prototype.forEach.call(root.querySelectorAll('[data-galinf-t]'), function (el) {
      el.textContent = t(el.getAttribute('data-galinf-t'));
    });

    /* Dark page background? Walk up the ancestors to the first opaque
       background and measure its luminance: the galinf-dark class then
       adapts the rendering of the sort <select> (color-scheme). Texts simply
       inherit the theme's color. The color is converted to RGB through a
       1 px canvas: getComputedStyle can return any format (rgb, oklch from
       daisyUI/Tailwind, color()…), the canvas resolves them all. */
    (function () {
      var cv = document.createElement('canvas');
      cv.width = cv.height = 1;
      var ctx = cv.getContext('2d', { willReadFrequently: true });
      if (!ctx) return;
      var el = root, d;
      while (el && el.nodeType === 1) {
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = '#fff';   // resets the parser (safe color)
        ctx.fillStyle = getComputedStyle(el).backgroundColor;
        ctx.fillRect(0, 0, 1, 1);
        d = ctx.getImageData(0, 0, 1, 1).data;
        if (d[3] > 127) {         // first really opaque background
          if ((0.2126 * d[0] + 0.7152 * d[1] + 0.0722 * d[2]) / 255 < 0.5) {
            root.classList.add('galinf-dark');
          }
          return;
        }
        el = el.parentElement;
      }
      // No background set anywhere → browser default (white): nothing to do.
    })();

    var items = [];
    var rendered = new Map();
    var pool = [];
    var cursor = null, seed = null;
    var total = 0;             // server-side image total (progress)
    var lastBg = 0, bgTimer = null;   // spacing of background fills
    var lastFetch = 0;                 // global anti-burst floor (mod_evasive)
    var hasMore = true, loading = false;
    // Feed generation, incremented on every order change: a response sent
    // before the change is ignored WITHOUT touching `loading`, which then
    // describes the new order's request (otherwise a second request without
    // a cursor went out in parallel → duplicate batch).
    var gen = 0;
    var lastWidth = 0;
    var lightbox = null;

    /* ---- network ---- */
    function buildUrl(lim) {
      var u = new URL(feed, window.location.origin);
      u.searchParams.set('limit', lim || batch);
      if (article) {
        u.searchParams.set('route', article);     // images of this article
      } else {
        u.searchParams.set('sort', sort);
        u.searchParams.set('field', field);
        if (sort === 'random' && seed) u.searchParams.set('seed', seed);
      }
      if (cursor) u.searchParams.set('cursor', cursor);
      return u.toString();
    }

    var retryDelay = 1200;
    function loadNext(urgent) {
      if (loading || !hasMore) return;
      loading = true;
      lastFetch = Date.now();
      var reqGen = gen;
      // priority:'high': this small JSON drives the scroll — it goes ahead of
      // the images in the browser's queue (hint ignored if unsupported).
      // Background fill: bigger batches (200) → fewer requests to the same
      // URI (some anti-DoS such as mod_evasive block bursts).
      fetch(buildUrl(urgent ? batch : 200), { priority: 'high' })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function (d) {
          if (reqGen !== gen) return;   // order changed in the meantime
          (d.items || []).forEach(function (it) {
            items.push({ thumb: it.thumb_url, full: it.full_url, route: it.article_route,
                         title: it.article_title || '', rw: it.width, rh: it.height,
                         animated: !!it.animated, comments: it.comments | 0,
                         x: 0, y: 0, w: 0, h: 0 });
          });
          cursor  = d.next_cursor;
          if (d.seed) seed = d.seed;
          if (typeof d.total === 'number') total = d.total;
          hasMore = !!d.next_cursor;
          loading = false;
          retryDelay = 1200;   // success → backoff reset
          layout();
          update();
        })
        .catch(function (e) {
          if (reqGen !== gen) return;
          loading = false;
          if (window.console) console.error('[galinf]', e);
          // Retry automatically (without this, standing still at the wall,
          // nothing restarted the load), with a PROGRESSIVE delay: if the
          // server is temporarily blocking (anti-DoS), insisting every 1.2 s
          // keeps the block going — spacing out up to 15 s lets it expire.
          setTimeout(update, retryDelay);
          retryDelay = Math.min(retryDelay * 2, 15000);
        });
    }

    /* ---- justified layout ---- */
    function layout() {
      var W = runway.clientWidth;
      if (!W) return;
      lastWidth = W;
      var y = 0, i = 0, n = items.length;
      while (i < n) {
        var row = [], ratioSum = 0, j = i;
        while (j < n) {
          ratioSum += items[j].rw / items[j].rh;
          row.push(j); j++;
          if (ratioSum * rowHeight + gap * (row.length - 1) >= W) break;
        }
        var incomplete = (j >= n) && (ratioSum * rowHeight + gap * (row.length - 1)) < W;
        var h = incomplete ? rowHeight : (W - gap * (row.length - 1)) / ratioSum;
        var x = 0;
        for (var k = 0; k < row.length; k++) {
          var it = items[row[k]];
          var w = (it.rw / it.rh) * h;
          it.x = Math.round(x); it.y = Math.round(y);
          it.w = Math.round(w); it.h = Math.round(h);
          x += w + gap;
        }
        y += h + gap;
        i = j;
      }
      runway.style.height = Math.ceil(y) + 'px';
    }

    /* ---- virtualization ---- */
    function visibleRange() {
      var rect = runway.getBoundingClientRect();
      var top = -rect.top - MARGIN;
      var bottom = -rect.top + window.innerHeight + MARGIN;
      var n = items.length;
      if (n === 0) return [0, -1];
      var lo = 0, hi = n - 1, a = n;
      while (lo <= hi) {
        var mid = (lo + hi) >> 1;
        if (items[mid].y + items[mid].h >= top) { a = mid; hi = mid - 1; } else { lo = mid + 1; }
      }
      var b = a - 1;
      for (var k = a; k < n; k++) { if (items[k].y > bottom) break; b = k; }
      return [a, b];
    }

    function acquireTile() {
      var tile = pool.pop();
      if (!tile) {
        tile = document.createElement('a');
        tile.className = 'galinf-tile';
        var img = document.createElement('img');
        img.decoding = 'async';
        img.loading = 'lazy';
        tile.appendChild(img);
        // Comment count badge (hidden if 0).
        var badge = document.createElement('span');
        badge.className = 'galinf-count';
        badge.style.display = 'none';
        tile.appendChild(badge);
        runway.appendChild(tile);
      }
      tile.style.display = '';
      return tile;
    }

    function configure(tile, it, idx, onScreen) {
      tile.href = it.full;
      tile.dataset.index = idx;
      tile.setAttribute('data-route', it.route);
      tile.style.transform = 'translate(' + it.x + 'px,' + it.y + 'px)';
      tile.style.width = it.w + 'px';
      tile.style.height = it.h + 'px';
      var img = tile.firstChild;
      // Comments badge (corner bubble, only if > 0).
      var badge = tile.lastChild;
      if (it.comments > 0) {
        badge.textContent = it.comments;
        badge.style.display = '';
      } else {
        badge.style.display = 'none';
      }
      // Network priority: high on screen, low in the pre-render margin.
      img.fetchPriority = onScreen ? 'high' : 'low';
      if (tile._want !== it.thumb) {
        tile._want = it.thumb;
        img.alt = it.title;
        // Immediate visual state: never show an image already seen.
        img.onload = img.onerror = null;
        if (it.animated) {
          tile.classList.remove('galinf-loading');   // gif: no spinner
          img.removeAttribute('src');
        } else {
          tile.classList.add('galinf-loading');       // spinner
        }
        // Immediate load: an image in the browser cache (scrolling back up)
        // shows with no latency.
        startLoad(tile, img, it);
      }
    }

    function startLoad(tile, img, it) {
      if (it.animated) {
        // Gif: progressive fill, no spinner.
        img.onload = img.onerror = null;
        img.src = it.thumb;
        return;
      }
      img.onload = function () {
        if (tile._want === it.thumb) tile.classList.remove('galinf-loading');
      };
      img.onerror = function () {
        if (tile._want !== it.thumb) return;
        // Transient failure (unstable network): ONE retry after a pause.
        if (!it._retried) {
          it._retried = true;
          setTimeout(function () {
            if (tile._want === it.thumb) { img.removeAttribute('src'); img.src = it.thumb; }
          }, 1000);
        } else if (it.thumb !== it.full) {
          // Second failure: the thumbnail file is gone from disk
          // (galinf-thumbs/ folder emptied by hand, partial restore…) while
          // the index still references it. Fall back on the original,
          // remembered on the item: the strip and the lightbox follow it, and
          // the tile will not retry the thumbnail when back on screen.
          it.thumb = it.full;
          tile._want = it.full;
          img.src = it.full;
        } else {
          tile.classList.remove('galinf-loading');
        }
      };
      img.src = it.thumb;
    }

    function update() {
      var range = visibleRange();
      var a = range[0], b = range[1];
      rendered.forEach(function (tile, idx) {
        if (idx < a || idx > b) {
          tile._want = null;
          tile.style.display = 'none';
          pool.push(tile);
          rendered.delete(idx);
        }
      });
      // Bounds of the REAL viewport (without the pre-render margin): tiles
      // really on screen load with high priority, those in the margin with
      // low priority → visible slots fill first.
      var vRect = runway.getBoundingClientRect();
      var vTop = -vRect.top;
      var vBottom = -vRect.top + window.innerHeight;
      var lastSeen = -1;   // rank of the last image really on screen
      for (var k = a; k <= b; k++) {
        var tile = rendered.get(k);
        if (!tile) { tile = acquireTile(); rendered.set(k, tile); }
        var it = items[k];
        var onScreen = (it.y + it.h >= vTop) && (it.y <= vBottom);
        if (onScreen) lastSeen = k;
        configure(tile, it, k, onScreen);
      }
      renderStatus(lastSeen);

      if (hasMore && !loading) {
        // Metadata buffer, DECOUPLED from the scroll: `lookahead` items are
        // kept ahead beyond the last rendered tile. lookahead=0 → unlimited:
        // the whole index (light JSON) loads in the background.
        var ahead = items.length - 1 - b;
        if (lookahead === 0 || ahead < lookahead) {
          var wallRect = runway.getBoundingClientRect();
          var now0 = Date.now();
          if (wallRect.bottom < window.innerHeight + 600) {
            // Urgent (the user is waiting) but with a ~350 ms floor:
            // mod_evasive counts per URI without the query string (every
            // batch = the same "page") and blocks beyond a few hits/second.
            if (now0 - lastFetch >= 350) {
              loadNext(true);
            } else if (!bgTimer) {
              bgTimer = setTimeout(function () { bgTimer = null; update(); }, 400);
            }
          } else {
            // Background fill: spaced out (≥ 700 ms between requests) so as
            // not to hammer the same URI (triggers server anti-DoS).
            var now = Date.now();
            if (now - lastBg >= 700) {
              lastBg = now;
              loadNext(false);
            } else if (!bgTimer) {
              bgTimer = setTimeout(function () { bgTimer = null; update(); }, 750);
            }
          }
        }
      }
    }

    /* ---- bottom-of-page indicator (main gallery only): rendered on EVERY
       scroll frame, so visible as soon as the bottom of what is displayed is
       reached. It tells visitors WHERE THEY ARE — rank of the last image on
       screen out of the total returned by the feed — not how much metadata
       is loaded (the buffer is always hundreds of images ahead). At the end:
       full counter, full bar. Under an article gallery, nothing: its end is
       visible on its own. ---- */
    var statusKey = '';
    function renderStatus(lastSeen) {
      if (!status || article) return;
      var rect = runway.getBoundingClientRect();
      var atWall = rect.bottom < window.innerHeight + 300;
      // Waiting for a batch: the last known image is already on screen.
      var waiting = hasMore && lastSeen >= items.length - 1;
      var seen = hasMore ? lastSeen + 1 : items.length;
      var key = (!atWall || !items.length) ? '' : seen + ':' + total + ':' + (waiting ? 'w' : hasMore ? 'm' : 'e');
      if (key === statusKey) return;   // avoids rebuilding the DOM on every frame
      statusKey = key;
      if (key === '') { status.textContent = ''; return; }
      var all = total > 0 ? total : (hasMore ? 0 : items.length);
      if (!all) {
        // Total not known yet: indeterminate bar.
        setStatus(t('LOADING'), null);
        return;
      }
      seen = Math.min(seen, all);
      setStatus(t(waiting ? 'COUNT_WAITING' : 'COUNT', { seen: seen, total: all }),
        Math.min(100, Math.round(seen / all * 100)));
    }

    /* Text (never interpreted as HTML) + progress bar;
       pct null = indeterminate bar. */
    function setStatus(text, pct) {
      status.textContent = text;
      var bar = document.createElement('span');
      bar.className = 'galinf-bar';
      var fill = document.createElement('span');
      fill.className = 'galinf-bar-fill' + (pct === null ? ' galinf-bar-indet' : '');
      if (pct !== null) fill.style.width = pct + '%';
      bar.appendChild(fill);
      status.appendChild(bar);
    }

    /* ---- order change (gallery page only) ---- */
    function reset(newSort) {
      sort = normSort(newSort);
      gen++;
      rendered.forEach(function (tile) { tile.style.display = 'none'; pool.push(tile); });
      rendered.clear();
      items = [];
      cursor = null; seed = null; hasMore = true; loading = false;
      total = 0; statusKey = '';
      runway.style.height = '0px';
      if (status) status.textContent = '';
      window.scrollTo(0, 0);
      loadNext();
    }

    /* ---- PhotoSwipe ---- */
    function openLightbox(index) {
      if (!lightbox) return false;
      var dataSource = items.map(function (it) {
        return { src: it.full, msrc: it.thumb, width: it.rw, height: it.rh,
                 alt: it.title, _route: it.route };
      });
      lightbox.loadAndOpen(index, dataSource);
      return true;
    }

    function setupLightbox() {
      var lbUrl = root.dataset.pswpLightbox, coreUrl = root.dataset.pswpCore;
      if (!lbUrl || !coreUrl) return;
      import(lbUrl).then(function (mod) {
        var PhotoSwipeLightbox = mod.default;
        lightbox = new PhotoSwipeLightbox({
          pswpModule: function () { return import(coreUrl); },
          loop: loop,
          bgOpacity: 0.9,
          // No zoom button: clicking the image already zooms.
          zoom: false,
          closeTitle: t('CLOSE'),
          arrowPrevTitle: t('PREVIOUS'),
          arrowNextTitle: t('NEXT'),
          errorMsg: t('IMAGE_ERROR')
        });
        lightbox.addFilter('thumbBounds', function (thumbBounds, itemData, index) {
          var tile = rendered.get(index);
          if (tile) { var r = tile.getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width }; }
          return thumbBounds;
        });
        // All the additional UI (top bar, buttons, captions/sidebar…) lives
        // in the separate galinf-lightbox.js module, hooked in here.
        if (window.galinfLightboxUI) {
          window.galinfLightboxUI(lightbox, root);
        }
        lightbox.init();
      }).catch(function (e) { if (window.console) console.error('[galinf] PhotoSwipe:', e); });
    }

    runway.addEventListener('click', function (e) {
      var tile = e.target.closest ? e.target.closest('.galinf-tile') : null;
      if (!tile) return;
      var idx = parseInt(tile.dataset.index, 10);
      if (isNaN(idx)) return;
      if (openLightbox(idx)) e.preventDefault();
    });

    /* ---- events ---- */
    if (sortSelect && !article) {
      sortSelect.value = sort;
      sortSelect.addEventListener('change', function () { reset(sortSelect.value); });
    }

    var ticking = false;
    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; requestAnimationFrame(function () { ticking = false; update(); }); }
    }, { passive: true });

    var rT;
    window.addEventListener('resize', function () {
      clearTimeout(rT);
      rT = setTimeout(function () {
        if (runway.clientWidth !== lastWidth) { layout(); }
        update();
      }, 150);
    });

    setupLightbox();
    loadNext();
  }

  var roots = document.querySelectorAll('.galinf-gallery');
  for (var i = 0; i < roots.length; i++) init(roots[i]);
})();
