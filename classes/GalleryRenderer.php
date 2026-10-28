<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;

/**
 * Renders the gallery container (HTML + data attributes) and registers the
 * assets. SINGLE logic shared by the [galinf] / [galinf-article] shortcodes
 * and the galinf() / galinf_article() Twig functions.
 */
class GalleryRenderer
{
    private const CSS_PSWP = 'plugins://galinf/assets/photoswipe/photoswipe.css';
    private const CSS_MAIN = 'plugins://galinf/assets/galinf-gallery.css';
    private const CSS_LB   = 'plugins://galinf/assets/galinf-lightbox.css';
    private const JS_MAIN  = 'plugins://galinf/assets/galinf-gallery.js';
    private const JS_LB    = 'plugins://galinf/assets/galinf-lightbox.js';

    /**
     * @param array $opts  src: ''/'all' (global) | 'article'/'self'/'page'
     *                     (current page) | '/a/route' (that article);
     *                     sort: asc|desc|random (recent/oldest aliases accepted)
     *                     and field: date|folder — global mode only
     * @param PageInterface|null $page  page designated by src="article"
     *                     (default: the displayed page, fine for a template)
     */
    public static function render(array $opts = [], ?PageInterface $page = null): string
    {
        $grav   = Grav::instance();
        $config = $grav['config'];

        // Added straight to the pipeline (covers template rendering +
        // shortcode on a cache miss). The shortcode also adds the meta (see
        // registerCachedAssets).
        $assets = $grav['assets'];
        $assets->addCss(self::CSS_PSWP, 91);
        $assets->addCss(self::CSS_MAIN, 90);
        $assets->addCss(self::CSS_LB, 89);
        $assets->addJs(self::JS_MAIN, ['priority' => 10, 'loading' => 'defer']);
        // Lightbox UI module (top bar, buttons, captions): loaded as well, the
        // grid hooks into it through window.galinfLightboxUI (order does not
        // matter: the hook happens after PhotoSwipe's async import).
        $assets->addJs(self::JS_LB, ['priority' => 10, 'loading' => 'defer']);

        $base      = rtrim((string) $grav['base_url'], '/');
        $assetsRel = trim((string) $grav['locator']->findResource('plugins://galinf/assets', false), '/');
        $assetsUrl = $base . '/' . $assetsRel;
        $feedUrl   = $base . (string) $config->get('plugins.galinf.feed_route', '/gallery-feed.json');

        $cfg = static fn(string $k, $d) => $config->get('plugins.galinf.' . $k, $d);

        $route = self::resolveRoute($page ?? ($grav['page'] ?? null), (string) ($opts['src'] ?? ''));

        $attrs = [
            'data-feed'          => $feedUrl,
            'data-base'          => $base,
            'data-pswp-lightbox' => $assetsUrl . '/photoswipe/photoswipe-lightbox.esm.min.js',
            'data-pswp-core'     => $assetsUrl . '/photoswipe/photoswipe.esm.min.js',
            'data-batch'         => (int) $cfg('batch_size', 80),
            'data-row-height'    => (int) $cfg('target_row_height', 220),
            'data-gap'           => (int) $cfg('gap', 6),
            'data-margin'        => (int) $cfg('preload_margin', 500),
            'data-lookahead'     => (int) $cfg('metadata_lookahead', 800),
            'data-loop'          => $cfg('loop', false) ? '1' : '0',
            // Lightbox display defaults (the visitor's remembered choice —
            // localStorage — then takes precedence).
            'data-sb-open'       => $cfg('sidebar_default_open', false) ? '1' : '0',
            'data-strip-open'    => $cfg('strip_default_open', true) ? '1' : '0',
            // Top bar buttons shown (allow list, comma-separated).
            'data-buttons'       => implode(',', array_filter([
                $cfg('btn_slideshow', true) ? 'slideshow' : '',
                $cfg('btn_rotate', true) ? 'rotate' : '',
                $cfg('btn_download', true) ? 'download' : '',
                $cfg('btn_fullscreen', true) ? 'fullscreen' : '',
                $cfg('btn_strip', true) ? 'strip' : '',
                $cfg('btn_info', true) ? 'info' : '',
            ])),
        ];

        // Info panel: metadata endpoint read on demand by the lightbox
        // (absent if the panel is disabled).
        if ($cfg('show_info_sidebar', true)) {
            $attrs['data-meta'] = $base . (string) $cfg('meta_route', '/gallery-meta.json');
            // Comment posting endpoint (if enabled).
            if ($cfg('comments_enabled', false)) {
                $attrs['data-comment'] = $base . (string) $cfg('comment_route', '/gallery-comment.json');
            }
        }

        // Visitor texts: dictionary/dictionaries read by the JS (see I18n).
        $i18n = I18n::frontPayload();
        $attrs['data-i18n'] = json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($route !== '') {
            // "Page" mode: the JS filters through ?route= and hides "View
            // the article".
            $attrs['data-route'] = $route;
        } else {
            // Global mode: criterion (admin) + initial order, normalized (the
            // shortcodes' old sort="recent"/"oldest" values remain valid).
            $sort  = (string) ($opts['sort'] ?? '');
            $field = (string) ($opts['field'] ?? '');
            $attrs['data-sort']  = Feed::normalizeSort($sort !== '' ? $sort : (string) $cfg('default_sort', 'desc'));
            $attrs['data-field'] = Feed::normalizeField($field !== '' ? $field : (string) $cfg('sort_field', 'date'));
        }

        // `not-prose`: neutralizes Tailwind Typography's styles if the host
        // theme wraps the content in `.prose` (otherwise its
        // `img { margin: 2em }` margins shift the tiles).
        // Encoded braces: a page whose content goes through Twig would read
        // `{#` (data-i18n's ICU plural) as an unclosed comment and crash. The
        // browser restores the braces.
        $html = '<div class="galinf-gallery not-prose"';
        foreach ($attrs as $k => $v) {
            $html .= ' ' . $k . '="' . strtr(htmlspecialchars((string) $v, ENT_QUOTES), ['{' => '&#123;', '}' => '&#125;']) . '"';
        }
        $html .= '>';

        // Sort control: global mode only, and if enabled in the config.
        // Written in the language imposed by Grav (otherwise English); the JS
        // rewrites every element marked data-galinf-t in the chosen language.
        if ($route === '' && $cfg('show_sort_control', true)) {
            $dict = $i18n['lang'] !== null ? $i18n['dicts'][$i18n['lang']] : I18n::dict(I18n::FALLBACK);
            $txt = static fn(string $key): string => '<span data-galinf-t="' . $key . '">'
                . htmlspecialchars($dict[$key] ?? $key, ENT_QUOTES) . '</span>';
            $opt = static fn(string $value, string $key): string => '<option value="' . $value . '" data-galinf-t="' . $key . '">'
                . htmlspecialchars($dict[$key] ?? $key, ENT_QUOTES) . '</option>';
            $html .= '<div class="galinf-controls"><label class="galinf-sort-label">' . $txt('SORT_LABEL') . ' '
                . '<select class="galinf-sort">'
                . $opt('desc', 'SORT_DESC')
                . $opt('asc', 'SORT_ASC')
                . $opt('random', 'SORT_RANDOM')
                . ($cfg('comments_enabled', false) ? $opt('commented', 'SORT_COMMENTED') : '')
                . '</select></label></div>';
        }

        $html .= '<div class="galinf-runway"></div>'
            . '<p class="galinf-status" role="status"></p>'
            . '</div>';

        return $html;
    }

    /**
     * Resolves the `src` parameter into a Grav route:
     *  - ''/'all'                  → '' (global gallery);
     *  - 'article'/'self'/'page'   → route of $page;
     *  - '/some/thing'             → that route as is.
     */
    private static function resolveRoute(?PageInterface $page, string $source): string
    {
        $source = trim($source);
        if ($source === '' || strtolower($source) === 'all') {
            return '';
        }
        if (in_array(strtolower($source), ['article', 'self', 'page'], true)) {
            return $page ? (string) $page->route() : '';
        }
        return '/' . ltrim($source, '/');
    }

    /**
     * Registers the assets in shortcode-core's page meta, so that they are
     * replayed on a cache HIT (gallery placed in a page's CONTENT). Only
     * called from the shortcode.
     */
    public static function registerCachedAssets($shortcodeManager): void
    {
        $shortcodeManager->addAssets('css', self::CSS_PSWP);
        $shortcodeManager->addAssets('css', self::CSS_MAIN);
        $shortcodeManager->addAssets('css', self::CSS_LB);
        $shortcodeManager->addAssets('js', [self::JS_MAIN, ['loading' => 'defer']]);
        $shortcodeManager->addAssets('js', [self::JS_LB, ['loading' => 'defer']]);
    }
}
