<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use Grav\Events\FlexRegisterEvent;
use Grav\Framework\Flex\Interfaces\FlexObjectInterface;
use Grav\Plugin\Galinf\Comments;
use Grav\Plugin\Galinf\Feed;
use Grav\Plugin\Galinf\FormCaptcha;
use Grav\Plugin\Galinf\IndexDatabase;
use Grav\Plugin\Galinf\Indexer;
use RocketTheme\Toolbox\Event\Event;

/**
 * Galinf plugin — media index for the infinite gallery.
 *
 * Hooks into the save lifecycle of Flex pages (Admin2):
 *   - onAdminAfterSave   → (re)indexes the article (deferred to onShutdown)
 *   - onAdminAfterDelete → purges the article from the index
 *
 * NB: onPageProcessed (read/render) is NOT used: it fires on every visitor
 * page view, not on save. Edits made outside the admin (direct file, CLI)
 * fire no event → covered by `bin/plugin galinf reindex`.
 */
class GalinfPlugin extends Plugin
{
    /**
     * Flags for ALL the plugin's JSON responses. JSON_INVALID_UTF8_SUBSTITUTE
     * is vital: without it, a single non-UTF-8 byte (binary EXIF such as
     * MakerNote in a .meta.yaml) makes json_encode return false → silently
     * empty body.
     */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Comment submission refusals: code → fallback English text. */
    private const REFUSALS = [
        'invalid'             => 'Invalid request.',
        'login_required'      => 'You must be logged in to comment.',
        'empty'               => 'The comment is empty.',
        'too_long'            => 'The comment is too long.',
        'name_required'       => 'A nickname is required.',
        'captcha'             => 'Wrong anti-bot answer — please try again.',
        'captcha_expired'     => 'This question has expired — answer the new one.',
        'captcha_failed'      => 'The anti-bot check failed — please try again.',
        'captcha_unavailable' => 'Comments are temporarily unavailable.',
        'unknown_page'        => 'Unknown page.',
        'unknown_image'       => 'Unknown image.',
        'rate_limited'        => 'Too many comments in a row — try again in a few minutes.',
        'daily_limit'         => 'The site has received all the comments it accepts today — try again tomorrow.',
        'server'              => 'The comment could not be saved on the server.',
    ];

    /**
     * TECHNICAL keys of .meta.yaml files (case-insensitive match), driven by
     * the sidebar's "EXIF data" option. Covers the mapped keys written by
     * Grav (php-exif: camera, aperture, focalLength…) AND the raw
     * exif_read_data tags (Make, ExposureTime, MakerNote…). Editorial keys,
     * even of EXIF origin (title, caption, author, keywords, copyright…),
     * follow the ".meta.yaml info" option.
     */
    private const EXIF_KEYS = [
        // php-exif (Grav's auto_metadata_exif)
        'aperture' => 1, 'camera' => 1, 'colorspace' => 1, 'creationdate' => 1,
        'exposure' => 1, 'filesize' => 1, 'focallength' => 1, 'focaldistance' => 1,
        'horizontalresolution' => 1, 'verticalresolution' => 1, 'iso' => 1,
        'mimetype' => 1, 'orientation' => 1, 'software' => 1, 'gps' => 1,
        // Raw exif_read_data / exiftool tags
        'make' => 1, 'model' => 1, 'exposuretime' => 1, 'fnumber' => 1,
        'exposureprogram' => 1, 'isospeedratings' => 1, 'exifversion' => 1,
        'datetime' => 1, 'datetimeoriginal' => 1, 'datetimedigitized' => 1,
        'componentsconfiguration' => 1, 'compressedbitsperpixel' => 1,
        'shutterspeedvalue' => 1, 'aperturevalue' => 1, 'brightnessvalue' => 1,
        'exposurebiasvalue' => 1, 'maxaperturevalue' => 1, 'subjectdistance' => 1,
        'meteringmode' => 1, 'lightsource' => 1, 'flash' => 1, 'makernote' => 1,
        'usercomment' => 1, 'flashpixversion' => 1, 'exifimagewidth' => 1,
        'exifimagelength' => 1, 'exifimageheight' => 1, 'interoperabilityindex' => 1,
        'filesource' => 1, 'scenetype' => 1, 'customrendered' => 1,
        'exposuremode' => 1, 'whitebalance' => 1, 'digitalzoomratio' => 1,
        'focallengthin35mmfilm' => 1, 'scenecapturetype' => 1, 'gaincontrol' => 1,
        'contrast' => 1, 'saturation' => 1, 'sharpness' => 1,
        'subjectdistancerange' => 1, 'xresolution' => 1, 'yresolution' => 1,
        'resolutionunit' => 1, 'ycbcrpositioning' => 1, 'sectionsfound' => 1,
        'computed' => 1, 'imagedescription' => 1, 'padding' => 1, 'offsetschema' => 1,
        'gpslatitude' => 1, 'gpslatituderef' => 1, 'gpslongitude' => 1,
        'gpslongituderef' => 1, 'gpsaltitude' => 1, 'gpsaltituderef' => 1,
        'gpsversion' => 1, 'gpsversionid' => 1, 'thumbnail' => 1,
    ];

    /** @var array<string,PageInterface> Articles flagged "to reindex" during the request. */
    private array $pendingReindex = [];

    /** @var string[] Routes flagged "to purge". */
    private array $pendingDelete = [];

    /** @var array<string,true> Subtrees to reindex (admin reordering). */
    private array $pendingSubtreeReindex = [];

    /** @var array<string,true> Subtrees to purge, thumbnails included (parent page deleted, moved or restricted). */
    private array $pendingSubtreeDelete = [];

    /** @var array<string,string> Comments to re-route (old route => new one). */
    private array $pendingCommentMove = [];

    /** @var array<string,string> Routes whose comments must be purged (deleted pages). */
    private array $pendingCommentDelete = [];

    /** @var array<string,array{0:string,1:string}> Images whose SQLite counter must be resynced. */
    private array $pendingCommentRecount = [];

    private ?IndexDatabase $db = null;
    private ?Indexer $indexer = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized'  => [['autoload', 100000], ['onPluginsInitialized', 0]],
            // Flex directory of comments (one object = one comment), declared
            // in Grav CORE; the flex-objects plugin (shipped with Grav 2) then
            // provides the list + CRUD in Admin Next.
            FlexRegisterEvent::class => [['onRegisterFlex', 0]],
            // Every write/deletion of a comment — ours (public POST) as well
            // as the admin's (moderation) — goes through Flex: the SINGLE
            // point where the SQLite counters are resynced.
            'onFlexObjectAfterSave'   => ['onFlexCommentChanged', 0],
            'onFlexObjectAfterDelete' => ['onFlexCommentChanged', 0],
            // Metadata endpoint (lightbox captions): read through Grav's
            // NATIVE media API (image.jpg.meta.yaml files), on demand, per
            // article — nothing is stored in the index. Intercepted here (and
            // not in onPluginsInitialized like the feed) because it needs the
            // pages.
            'onPagesInitialized'    => ['onPagesInitialized', 0],
            'onAdminAfterSave'      => ['onAdminAfterSave', 0],
            'onAdminAfterDelete'    => ['onAdminAfterDelete', 0],
            // Image added/removed WITHOUT a page save (Admin2 uploads through
            // a dedicated media endpoint) → these events cover the "images
            // added/removed" case of spec §5.
            'onAdminAfterAddMedia'  => ['onAdminMediaChanged', 0],
            'onAdminAfterDelMedia'  => ['onAdminMediaChanged', 0],
            // Editing an image's metadata in the admin (the api plugin's
            // `.meta.yaml` sidecar editor): hiding tags (see
            // Indexer::hiddenFlags) are resolved at INDEXING time, so without
            // this a tag added or removed would have no effect until a manual
            // reindex. The event carries the page for page media; for site
            // media, the handler simply returns.
            'onApiMediaMetadataUpdated' => ['onAdminMediaChanged', 0],
            'onApiMediaMetadataDeleted' => ['onAdminMediaChanged', 0],
            // Moving/renaming a page (Admin Next): neither onAdminAfterSave
            // nor onAdminAfterDelete covers this case → orphans otherwise.
            'onApiPageMoved'        => ['onApiPageMoved', 0],
            // Manual reordering (drag and drop in the admin): Grav renames the
            // folder PREFIXES (01., 02.…) without changing the routes → only
            // folder_sort ("manual order" sort) is affected. The subtree
            // concerned is reindexed, deferred.
            'onApiPagesReordered'   => ['onApiPagesReordered', 0],
            'onApiPagesReorganized' => ['onApiPagesReorganized', 0],
            'onShutdown'            => ['onShutdown', 0],
            // Admin: maintenance and stats endpoints (called by the custom
            // fields of the options' Maintenance tab).
            'onApiRegisterRoutes'   => ['onApiRegisterRoutes', 0],
            // [galinf] / [galinf-article] shortcodes: render the gallery in any theme,
            // assets served from the plugin (standalone).
            'onShortcodeHandlers'   => ['onShortcodeHandlers', 0],
            // galinf() / galinf_article() Twig functions (native
            // alternative to the shortcode in a template).
            'onTwigInitialized'     => ['onTwigInitialized', 0],
        ];
    }

    /**
     * Registers the plugin's PSR-4 autoloader (classes/).
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Declares the Flex directory of comments (bundled blueprint). Always
     * registered (even with comments disabled): the admin must be able to
     * browse/moderate what exists.
     */
    public function onRegisterFlex(FlexRegisterEvent $event): void
    {
        // NB: the blueprint's folder MUST stay a stream/relative path — the
        // core's resolvePath() prefixes GRAV_ROOT to non-stream paths, so an
        // absolute path would be doubled when reading objects. The storage
        // scan, on the other hand, is relative to the cwd: see the chdir() in
        // onShutdown.
        $event->flex->addDirectoryType(
            Comments::FLEX_TYPE,
            __DIR__ . '/blueprints/flex-objects/galinf-comments.yaml'
        );
    }

    /**
     * A comment was just created/edited/deleted (by the public POST or by
     * moderation in Admin Next): its image is flagged so the SQLite cache
     * (badge, "Commented" sort) gets resynced.
     */
    public function onFlexCommentChanged(Event $event): void
    {
        $object = $event['object'] ?? null;
        if (!$object instanceof FlexObjectInterface || $object->getFlexType() !== Comments::FLEX_TYPE) {
            return;
        }
        $route = (string) $object->getProperty('route');
        $file  = (string) $object->getProperty('file');
        if ($route !== '' && $file !== '') {
            $this->pendingCommentRecount[$route . '|' . $file] = [$route, $file];
        }
    }

    /**
     * Recomputes the SQLite counters of the flagged images, from the
     * refreshed Flex index (no object loaded). Called in onShutdown (admin)
     * and right before the public POST's response (which ends with exit).
     */
    private function flushCommentRecounts(): void
    {
        if (empty($this->pendingCommentRecount)) {
            return;
        }
        Comments::refresh();
        $db = $this->getDb();
        foreach ($this->pendingCommentRecount as [$route, $file]) {
            [$count, $last] = Comments::statsFor($route, $file);
            $db->updateCommentStats(ltrim($route, '/') . '/' . $file, $count, $last);
        }
        $this->pendingCommentRecount = [];
    }

    public function onPluginsInitialized(): void
    {
        // Public feed: the dedicated route is intercepted as early as
        // possible, outside the API auth stack, for the shortest path
        // (spec §6).
        $feedRoute = (string) $this->config->get('plugins.galinf.feed_route', '/gallery-feed.json');
        if ($this->matchesRoute($this->grav['uri'], $feedRoute)) {
            $this->serveFeed();
        }
    }

    /**
     * Serves the public endpoints that need the pages: an article's metadata
     * and comments (GET, info panel), posting a comment (POST).
     */
    public function onPagesInitialized(): void
    {
        $uri = $this->grav['uri'];

        // Posting a comment (POST, if enabled).
        if ($this->config->get('plugins.galinf.comments_enabled', false)
            && $this->matchesRoute($uri, (string) $this->config->get('plugins.galinf.comment_route', '/gallery-comment.json'))
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->serveCommentAction();
        }

        // Metadata + comments (GET, if the panel is enabled).
        if ($this->config->get('plugins.galinf.show_info_sidebar', true)
            && $this->matchesRoute($uri, (string) $this->config->get('plugins.galinf.meta_route', '/gallery-meta.json'))) {
            $this->serveMeta();
        }
    }

    /**
     * Does the request target this route (Grav splits the path from the
     * extension)?
     */
    private function matchesRoute($uri, string $route): bool
    {
        if ($route === '') {
            return false;
        }
        $ext       = (string) pathinfo($route, PATHINFO_EXTENSION);
        $routeOnly = $ext !== '' ? substr($route, 0, -(\strlen($ext) + 1)) : $route;

        return $uri->path() === $routeOnly && (string) $uri->extension() === $ext;
    }

    /**
     * Metadata of ONE article's images, through Grav's native functions
     * (`$medium->metadata()` = content of the image.jpg.meta.yaml sidecar
     * file). Response: { "img.jpg": { key: value, … }, … } — the keys with a
     * non-empty scalar value, in YAML file order (nested structures are
     * ignored), filtered by the ".meta.yaml info" (editorial keys) and "EXIF
     * data" (technical keys, see EXIF_KEYS) options. Scope: indexable pages
     * only.
     */
    private function serveMeta(): void
    {
        $routeParam = $this->grav['uri']->query('route');
        $route = is_string($routeParam) ? '/' . ltrim($routeParam, '/') : '';

        $enabled  = (bool) $this->config->get('plugins.galinf.comments_enabled', false);
        $moderate = $this->isModerator();
        $showMeta = (bool) $this->config->get('plugins.galinf.sidebar_show_meta', true);
        $showExif = (bool) $this->config->get('plugins.galinf.sidebar_show_exif', false);

        // Hiding tags (internal markers) never to expose: same values as the
        // ones read by the Indexer, normalized the same way.
        $hiddenTags = [];
        foreach ([
            $this->config->get('plugins.galinf.exclude_tag_article', 'nogal'),
            $this->config->get('plugins.galinf.exclude_tag_global', 'nogalinf'),
        ] as $tag) {
            $tag = mb_strtolower(trim((string) $tag));
            if ($tag !== '') {
                $hiddenTags[$tag] = true;
            }
        }

        $meta     = [];
        $comments = [];
        $page = $route !== '/' ? $this->grav['pages']->find($route) : null;

        if ($page instanceof PageInterface && $this->getIndexer()->isServable($page)) {
            // Fresh Media (same reason as in the Indexer: the page's media
            // cache can be stale after an image is added). No need to read the
            // media if both kinds of info are hidden.
            $items = [];
            if ($showMeta || $showExif) {
                try {
                    $media = new \Grav\Common\Page\Media($page->path());
                    $items = $media->all();
                } catch (\Throwable $e) {
                    // An unreadable .meta.yaml (raw non-UTF-8 bytes…) must not
                    // kill the endpoint: answer without meta rather than a 500.
                    $this->grav['log']->warning('galinf: unreadable metadata for ' . $route . ' — ' . $e->getMessage());
                }
            }
            $extensions = array_map('strtolower', (array) $this->config->get('plugins.galinf.extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif']));
            foreach ($items as $filename => $medium) {
                // Only what the gallery can show: neither the folder's other
                // files (pdf, video…), nor an image hidden by tag in BOTH
                // galleries.
                if (!\in_array(strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION)), $extensions, true)) {
                    continue;
                }
                if ($this->getIndexer()->hiddenFlags($medium) === [1, 1]) {
                    continue;
                }
                try {
                    $m = (array) $medium->metadata();
                } catch (\Throwable $e) {
                    $this->grav['log']->warning('galinf: unreadable meta.yaml (' . $filename . ') — ' . $e->getMessage());
                    continue;
                }
                $entry = [];
                foreach ($m as $k => $v) {
                    $key = (string) $k;
                    $isExif = isset(self::EXIF_KEYS[strtolower($key)]);
                    if ($isExif ? !$showExif : !$showMeta) {
                        continue;
                    }
                    // Hiding tags are INTERNAL markers
                    // (Indexer::hiddenFlags): they drive the gallery, they have
                    // no business in front of the visitor. They are stripped
                    // from the `tags` key, the only one the Indexer reads.
                    $strip = ($key === 'tags') ? $hiddenTags : [];
                    if (is_scalar($v)) {
                        $v = trim((string) $v);
                        if ($strip !== [] && $v !== '') {
                            // Tolerates "tags: a, nogal" typed by hand.
                            $v = implode(', ', array_filter(
                                array_map('trim', explode(',', $v)),
                                static fn(string $p): bool => $p !== '' && !isset($strip[mb_strtolower($p)])
                            ));
                        }
                        if ($v !== '') {
                            $entry[$key] = $v;
                        }
                        continue;
                    }
                    // List (e.g. `prompts:` in YAML): kept as an array of
                    // lines, rendered over several lines in the sidebar.
                    // Non-scalar items (nested list, map) are ignored —
                    // structure not meant for display.
                    if (is_array($v)) {
                        $lines = [];
                        foreach ($v as $item) {
                            if (!is_scalar($item)) {
                                continue;
                            }
                            $item = trim((string) $item);
                            if ($item === '' || isset($strip[mb_strtolower($item)])) {
                                continue;
                            }
                            $lines[] = $item;
                        }
                        if ($lines !== []) {
                            $entry[$key] = $lines;
                        }
                    }
                }
                if ($entry !== []) {
                    $meta[(string) $filename] = $entry;
                }
            }

            // Comments: from the Flex directory (approved ones only —
            // moderation happens in Admin Next, no longer on the front end).
            if ($enabled) {
                $comments = Comments::forRoute((string) $page->route(), true);
            }
        }

        $authed = $this->isAuthenticated();
        $who    = (string) $this->config->get('plugins.galinf.comments_who', 'anonymous');
        $canComment = $enabled && (
            $who === 'anonymous'
            || ($who === 'members' && $authed)
            || ($who === 'admins' && $moderate)
        );
        // Captcha: only for a visitor who is not logged in.
        $needCaptcha = $canComment && !$authed;

        $payload = [
            'meta'             => $meta,
            'comments'         => $comments,
            'comments_enabled' => $enabled,
            'can_comment'      => $canComment,
            'need_name'        => (bool) $this->config->get('plugins.galinf.comments_require_name', false),
            'authed'           => $authed,
            'captcha'          => $needCaptcha ? $this->captchaPayload() : null,
            'max_len'          => Comments::maxLength(),
            'counter_always'   => (bool) $this->config->get('plugins.galinf.comments_counter_always', false),
        ];

        header('Content-Type: application/json; charset=utf-8');
        // With comments/session the response depends on the user → no shared
        // cache; otherwise a short public cache.
        header($enabled || $moderate
            ? 'Cache-Control: private, no-store'
            : 'Cache-Control: public, max-age=60');
        echo json_encode($payload, self::JSON_FLAGS);
        exit;
    }

    /**
     * POST /gallery-comment.json — adds a comment (public depending on the
     * options). Moderation (approval/deletion) happens in Admin Next through
     * the Flex directory — no admin action on the front end any more.
     * Light anti-CSRF: the custom X-Galinf header forces a CORS preflight on
     * any cross-origin attempt (refused by default).
     */
    private function serveCommentAction(): void
    {
        if (($_SERVER['HTTP_X_GALINF'] ?? '') !== '1') {
            $this->refuse('invalid', 400);
        }
        // Second barrier: if the browser declares the request's origin (it
        // does for every POST), it must be this site. Behind a proxy, the
        // public host may only appear in X-Forwarded-Host: accepting it is
        // safe here, since a third-party page cannot make its victim's
        // browser send that header.
        if (!$this->sameOrigin()) {
            $this->refuse('invalid', 403);
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!\is_array($body)) {
            $this->refuse('invalid', 400);
        }

        // ORDER OF CHECKS: from cheapest to most expensive. Nothing touching
        // the pages or the disk (page lookup, reading its media folder) is
        // done before the captcha is validated: a burst of invalid requests
        // costs the server almost nothing.

        // Honeypot: hidden field filled in = bot → answer "success" without
        // saving anything (the bot thinks it succeeded and moves on).
        if (trim((string) ($body['website'] ?? '')) !== '') {
            $this->jsonOut(['ok' => true, 'pending' => false]);
        }

        $moderate = $this->isModerator();
        $authed   = $this->isAuthenticated();
        $who    = (string) $this->config->get('plugins.galinf.comments_who', 'anonymous');
        $can = $who === 'anonymous' || ($who === 'members' && $authed) || ($who === 'admins' && $moderate);
        if (!$can) {
            $this->refuse('login_required', 403);
        }

        // Text and nickname BEFORE the captcha: fixing an empty field must
        // not cost the visitor their token.
        $text = trim((string) ($body['text'] ?? ''));
        if ($text === '') {
            $this->refuse('empty', 400);
        }
        if (mb_strlen($text) > Comments::maxLength()) {
            $this->refuse('too_long', 400);
        }

        // Nickname: the account's for logged-in users; otherwise a free field
        // (required depending on the option).
        if ($authed) {
            $user = $this->grav['user'];
            $name = (string) ($user->fullname ?: $user->username);
        } else {
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '' && $this->config->get('plugins.galinf.comments_require_name', false)) {
                $this->refuse('name_required', 400);
            }
        }

        // Daily cap for the whole site (option), before any costly step.
        // Moderators are neither limited nor counted.
        $dailyMax = $moderate ? 0 : Comments::dailyMax();
        if ($dailyMax > 0 && $this->getDb()->commentsToday() >= $dailyMax) {
            $this->refuse('daily_limit', 429);
        }

        // Per-IP safeguard (hashed — no plain IP stored).
        // Address provided by Grav: behind a proxy or a CDN, it follows the
        // site's `system.http_x_forwarded` setting (proxy headers read only
        // if enabled there); without that setting, it is the address seen by
        // the server, as before. Reading those headers ourselves would let
        // anyone change address on every submission.
        $ipHash = hash('sha256', \Grav\Common\Uri::ip() . $this->captchaSalt());
        $rateCounted = false;

        // Captcha (visitors who are not logged in, unless set to none). The
        // token is consumed by the check, whatever the outcome: with the sum
        // question, every refusal from here on therefore returns a NEW
        // question ($retry), which the form shows instead of the old one;
        // the widgets (Cap, Turnstile, reCAPTCHA) get a new token in the
        // browser after each attempt.
        $retry = [];
        $provider = FormCaptcha::provider();
        if (!$authed && $provider !== 'none') {
            if ($provider === 'galinf') {
                $refusal = $this->captchaVerify((string) ($body['captcha'] ?? ''), (string) ($body['token'] ?? ''));
                $retry = ['captcha' => $this->captchaChallenge()];
                if ($refusal !== null) {
                    $this->refuse($refusal, 400, $retry);
                }
            } else {
                $issue = FormCaptcha::issue();
                if ($issue !== null) {
                    $this->grav['log']->error("galinf: comment captcha '{$provider}' unavailable ({$issue})");
                    $this->refuse('captcha_unavailable', 503);
                }
                // Turnstile and reCAPTCHA are checked over the network: the
                // attempt counts against the per-IP limit BEFORE that call, so
                // a flood of fake tokens cannot keep the server busy with
                // remote checks.
                if ($provider !== 'cap') {
                    if (!$this->getDb()->rateLimitAllow($ipHash)) {
                        $this->refuse('rate_limited', 429);
                    }
                    $rateCounted = true;
                }
                if (!FormCaptcha::verify($provider, (string) ($body['captcha'] ?? ''))) {
                    $this->refuse('captcha_failed', 400);
                }
            }
        }

        $route = '/' . ltrim((string) ($body['route'] ?? ''), '/');
        $file  = basename((string) ($body['file'] ?? ''));   // never a path

        $page = $route !== '/' ? $this->grav['pages']->find($route) : null;
        if (!$page instanceof PageInterface || !$this->getIndexer()->isServable($page)) {
            $this->refuse('unknown_page', 404, $retry);
        }

        // The file must be an image medium of the page (allow list).
        $extensions = (array) $this->config->get('plugins.galinf.extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $media = new \Grav\Common\Page\Media($page->path());
        if ($file === '' || !\in_array($ext, array_map('strtolower', $extensions), true)
            || !\array_key_exists($file, $media->all())) {
            $this->refuse('unknown_image', 404, $retry);
        }

        if (!$rateCounted && !$this->getDb()->rateLimitAllow($ipHash)) {
            $this->refuse('rate_limited', 429, $retry);
        }

        $approved = !$this->config->get('plugins.galinf.comments_moderation', false) || $moderate;

        try {
            // Writes into the Flex directory; the onFlexObjectAfterSave event
            // flags the image, and the flush below resyncs the SQLite counter
            // BEFORE the response (jsonOut ends with exit, onShutdown would not
            // run).
            Comments::add((string) $page->route(), $file, $name, $text, $approved);
            if (!$moderate) {
                $this->getDb()->countCommentToday();
            }
            $this->flushCommentRecounts();
        } catch (\Throwable $e) {
            $this->grav['log']->error('galinf: could not add comment — ' . $e->getMessage());
            $this->refuse('server', 500, $retry);
        }

        $this->jsonOut(['ok' => true, 'pending' => !$approved]);
    }

    /* ---- comment helpers ---- */

    private function isAuthenticated(): bool
    {
        $user = $this->grav['user'] ?? null;
        return $user && !empty($user->authenticated) && !empty($user->authorized);
    }

    /**
     * A user logged in ON THE SITE (Admin Next's login does not open a site
     * session) who may moderate: Grav 2 administrator (`api.super`), allowed
     * to edit gallery comments, or Grav 1.7 admin permissions (migrated
     * accounts).
     */
    private function isModerator(): bool
    {
        $user = $this->grav['user'] ?? null;
        return $user && !empty($user->authenticated) && !empty($user->authorized)
            && ($user->authorize('api.super') || $user->authorize('admin.galinf-comments.update')
                || $user->authorize('admin.super') || $user->authorize('admin.login'));
    }

    /**
     * Does the Origin header, if present, designate this site? Absent
     * (non-browser client) → accepted: the X-Galinf header is still required.
     */
    private function sameOrigin(): bool
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return false;   // "null" or unreadable value
        }
        $port = parse_url($origin, PHP_URL_PORT);
        $candidates = [strtolower($host), strtolower($host . ($port ? ':' . $port : ''))];

        foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST'] as $key) {
            foreach (explode(',', (string) ($_SERVER[$key] ?? '')) as $own) {
                $own = strtolower(trim($own));
                if ($own !== '' && (\in_array($own, $candidates, true) || explode(':', $own)[0] === $candidates[0])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Secret key of the captcha and of the IP address fingerprint.
     *
     * Grav 2 no longer keeps its secret in the configuration (`security.salt`
     * is always empty there since v2.0, GHSA-3f29-pqwf-v4j4): it lives in
     * `user/config/security-private.php`, read by `Security::getNonceKey()`.
     * A plugin-specific key is DERIVED from it (HMAC) rather than reusing
     * Grav's secret as is.
     *
     * The old code read `security.salt` and, for lack of a value, fell back on
     * md5(installation path): a guessable key, which made it possible to forge
     * valid captcha tokens without solving the question.
     */
    private function captchaSalt(): string
    {
        static $key = null;
        if ($key === null) {
            $secret = method_exists(\Grav\Common\Security::class, 'getNonceKey')
                ? \Grav\Common\Security::getNonceKey()
                : (string) $this->config->get('security.salt', '');
            if ($secret === '') {
                // Neither one (very old Grav): random plugin-specific secret,
                // created once and kept outside the web root.
                $secret = $this->pluginSecret();
            }
            $key = hash_hmac('sha256', 'galinf-captcha', $secret);
        }
        return $key;
    }

    /**
     * Fallback secret, generated once in user/data/galinf/ (a folder Grav
     * denies web access to).
     */
    private function pluginSecret(): string
    {
        $dir  = (string) $this->grav['locator']->findResource('user-data://', true) . '/galinf';
        $file = $dir . '/secret.key';
        $value = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ($value === '') {
            $value = bin2hex(random_bytes(32));
            @mkdir($dir, 0775, true);
            @file_put_contents($file, $value);
            @chmod($file, 0600);
        }
        return $value;
    }

    /**
     * Captcha part of the meta payload, for the provider chosen in the
     * options; 'unavailable' when it cannot work (the form is then replaced
     * by a notice); null with no check at all.
     */
    private function captchaPayload(): ?array
    {
        $provider = FormCaptcha::provider();
        if ($provider === 'none') {
            return null;
        }
        if ($provider === 'galinf') {
            return $this->captchaChallenge();
        }
        return FormCaptcha::issue() === null ? FormCaptcha::client($provider) : ['provider' => 'unavailable'];
    }

    /**
     * Arithmetic question + signed token (HMAC of the answer and of the
     * expiry), valid for 15 minutes and ONCE only: the server only remembers
     * the tokens already presented (see captchaVerify).
     */
    private function captchaChallenge(): array
    {
        $a = random_int(2, 15);
        $b = random_int(2, 15);
        $exp = time() + 900;   // valid for 15 min
        $sig = hash_hmac('sha256', ($a + $b) . '|' . $exp, $this->captchaSalt());

        // a and b: the browser words the question in the visitor's language;
        // q = English version for a client without translations.
        return ['provider' => 'galinf', 'a' => $a, 'b' => $b, 'q' => "What is {$a} + {$b}?", 'token' => $exp . '.' . $sig];
    }

    /**
     * @return string|null refusal code (see refuse()); null = passed
     */
    private function captchaVerify(string $answer, string $token): ?string
    {
        $wrong = 'captcha';
        $answer = trim($answer);
        if ($answer === '' || !preg_match('/^\d+$/', $answer) || !str_contains($token, '.')) {
            return $wrong;
        }
        [$exp, $sig] = explode('.', $token, 2);
        if (!ctype_digit($exp)) {
            return $wrong;
        }
        $exp = (int) $exp;
        $now = time();
        if ($exp < $now) {
            // Question left on screen for more than 15 minutes (the info panel
            // keeps it in memory for the whole visit): not the visitor's
            // mistake, so tell them.
            return 'captcha_expired';
        }
        if ($exp > $now + 900) {
            return $wrong;   // forged
        }
        if ($exp - $now > 897) {
            return $wrong;   // time trap: submitted less than ~3 s after issue
        }
        // Single use, consumed BEFORE comparing the answer: a token already
        // presented is refused (no replay of a solved captcha), and a wrong
        // answer burns it too (impossible to try the 27 possible sums one by
        // one with the same token). Only a signature in the expected format is
        // remembered, so as not to store just anything.
        if (!preg_match('/^[0-9a-f]{64}$/', $sig) || !$this->getDb()->captchaConsume($sig, $exp)) {
            return $wrong;
        }
        return hash_equals(hash_hmac('sha256', ((int) $answer) . '|' . $exp, $this->captchaSalt()), $sig)
            ? null
            : $wrong;
    }

    /**
     * Refuses a comment submission. The browser shows the text of `code` in
     * the visitor's language (FRONT.ERR_<CODE> key); `error` is only the
     * English version, for a client without translations.
     */
    private function refuse(string $code, int $status, array $extra = []): void
    {
        $this->jsonOut(['ok' => false, 'code' => $code, 'error' => self::REFUSALS[$code] ?? 'Request refused.'] + $extra, $status);
    }

    private function jsonOut(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode($payload, self::JSON_FLAGS);
        exit;
    }

    /**
     * Registers the [galinf] / [galinf-article] shortcodes (if shortcode-core
     * is active).
     */
    public function onShortcodeHandlers(): void
    {
        $this->grav['shortcode']->registerAllShortcodes(__DIR__ . '/classes/shortcodes');
    }

    /**
     * Twig functions to insert the gallery in a template, without a
     * shortcode:
     *   {{ galinf() }}                  global gallery
     *   {{ galinf('/blog/my-page') }}   media of a given page
     *   {{ galinf({sort: 'asc'}) }}     global, initial order imposed
     *   {{ galinf_article() }}          media of the current page
     */
    public function onTwigInitialized(): void
    {
        $twig = $this->grav['twig']->twig();

        $twig->addFunction(new \Twig\TwigFunction('galinf', static function ($arg = null): string {
            if (is_array($arg)) {
                $opts = $arg;                       // {src: …, sort: …}
            } elseif (is_string($arg) && $arg !== '') {
                $opts = ['src' => $arg];            // shortcut: a route/keyword
            } else {
                $opts = [];
            }
            return \Grav\Plugin\Galinf\GalleryRenderer::render($opts);
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new \Twig\TwigFunction('galinf_article', static function (): string {
            return \Grav\Plugin\Galinf\GalleryRenderer::render(['src' => 'article']);
        }, ['is_safe' => ['html']]));
    }

    /**
     * Registers the admin endpoints (they reuse ReindexService).
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = \Grav\Plugin\Galinf\Api\GalinfApiController::class;
        // Maintenance block: batched reindexing (progress) and purge.
        $routes->post('/galinf/reindex-batch', [$controller, 'reindexBatch']);
        $routes->post('/galinf/purge-thumbs', [$controller, 'purgeThumbs']);
        $routes->post('/galinf/remove-data', [$controller, 'removeData']);
        $routes->get('/galinf/stats', [$controller, 'stats']);
        // Images too large for Grav to resize (> max_pixels).
        $routes->get('/galinf/missing-thumbs', [$controller, 'missingThumbs']);
        $routes->post('/galinf/shrink', [$controller, 'shrink']);
        // Image preview for a comment's edit form.
        $routes->get('/galinf/comment-preview/{key}', [$controller, 'commentPreview']);
    }

    /**
     * Builds and emits the feed JSON, then ends the request.
     */
    private function serveFeed(): void
    {
        $uri    = $this->grav['uri'];
        $config = $this->config;

        $sort   = (string) ($uri->query('sort') ?: $config->get('plugins.galinf.default_sort', 'desc'));
        // Sort criterion: date | folder. Admin setting, overridable per
        // request (the JS sends back the one set by the renderer).
        $field  = (string) ($uri->query('field') ?: $config->get('plugins.galinf.sort_field', 'date'));
        $cursor = $uri->query('cursor') ?: null;
        $limit  = (int) ($uri->query('limit') ?: $config->get('plugins.galinf.batch_size', 80));
        $max    = (int) $config->get('plugins.galinf.max_batch_size', 200);

        // Seed (random sort): positive integer provided by the client,
        // otherwise null → Feed generates one and returns it so the client
        // can replay it.
        $seedParam = $uri->query('seed');
        $seed = (is_string($seedParam) && ctype_digit($seedParam)) ? (int) $seedParam : null;

        // route: if provided, the images of THAT article are served (article
        // page).
        $routeParam = $uri->query('route');
        $route = (is_string($routeParam) && $routeParam !== '') ? $routeParam : null;

        $feed = new Feed($this->getDb(), (string) $this->grav['base_url'], $max);
        $payload = $feed->build($sort, $cursor !== null ? (string) $cursor : null, $limit, $seed, $route, $field);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=0');
        echo json_encode($payload, self::JSON_FLAGS);
        exit;
    }

    /**
     * A page saved through Admin2: the article is flagged, not indexed right
     * away (heavy work deferred to onShutdown).
     */
    public function onAdminAfterSave(Event $event): void
    {
        $object = $event['object'] ?? null;
        if (!$object instanceof PageInterface) {
            return; // the event is also fired by UserObject: filter it out.
        }

        $route = $object->route();
        if ($route === null) {
            return;
        }

        if ($this->getIndexer()->isIndexable($object)) {
            $this->pendingReindex[$route] = $object;
        } elseif (Indexer::isProtected($object)) {
            // `access:` rule on the page or an ancestor: its descendant
            // articles are restricted too, but the admin only emits an event
            // for the saved page.
            $this->pendingSubtreeDelete[$route] = true;
        } else {
            // Article unpublished or template changed → removed from the
            // index.
            $this->pendingDelete[$route] = $route;
        }
    }

    /**
     * An image added to/removed from a page (Admin2/API media endpoint).
     * Does NOT fire onAdminAfterSave → the article is reindexed here.
     */
    public function onAdminMediaChanged(Event $event): void
    {
        $object = $event['page'] ?? $event['object'] ?? null;
        if (!$object instanceof PageInterface) {
            return;
        }

        $route = $object->route();
        if ($route === null) {
            return;
        }

        if ($this->getIndexer()->isIndexable($object)) {
            $this->pendingReindex[$route] = $object;
        } else {
            $this->pendingDelete[$route] = $route;
        }
    }

    /**
     * A page moved/renamed (Admin Next). A single event is emitted, for the
     * moved page: its descendant articles follow it here (old route purged,
     * subtree reindexed at its new place).
     */
    public function onApiPageMoved(Event $event): void
    {
        // Since api 1.0.4x, a reorganization ALSO emits this event for each
        // moved page (method = reorganize). onApiPagesReorganized already
        // handles the whole subtree: without this filter, the page would be
        // reindexed twice.
        if (($event['method'] ?? '') === 'reorganize') {
            return;
        }

        $oldRoute = \is_string($event['old_route'] ?? null) ? $event['old_route'] : '';
        $page     = $event['page'] ?? null;
        $newRoute = $page instanceof PageInterface
            ? (string) $page->route()
            : (string) ($event['new_route'] ?? '');

        // Route changed: the old one no longer exists, neither for the page
        // nor for its descendants. Only the position changed (same route):
        // nothing to purge, the reindex replaces the rows and the thumbnails
        // remain valid.
        if ($oldRoute !== '' && $oldRoute !== $newRoute) {
            $this->pendingSubtreeDelete[$oldRoute] = true;
            // Comments follow the page (and its descendants).
            if ($newRoute !== '') {
                $this->pendingCommentMove[$oldRoute] = $newRoute;
            }
        }

        // The whole subtree must be reindexed: the descendants' routes
        // changed along with the page's, and so did their "manual order" sort
        // key (it contains the parent folders' prefixes).
        if ($newRoute !== '') {
            $this->pendingSubtreeReindex[$newRoute] = true;
        }
    }

    /**
     * A parent's children reordered (Admin Next drag and drop). Routes do not
     * change, but folder prefixes do → the folder_sort key of every article
     * in the subtree must be recomputed.
     */
    public function onApiPagesReordered(Event $event): void
    {
        $parent = $event['parent'] ?? null;
        if ($parent instanceof PageInterface) {
            $route = (string) $parent->route();
            $this->pendingSubtreeReindex[$route !== '' ? $route : '/'] = true;
        }
    }

    /**
     * Grouped reorganization (multiple moves and/or reorderings). For each
     * operation that really renames on disk: purge of the old route (if the
     * parent changes, the route changes) and reindex of both parents'
     * subtrees (their children are renumbered).
     */
    public function onApiPagesReorganized(Event $event): void
    {
        foreach ((array) ($event['operations'] ?? []) as $op) {
            if (empty($op['actuallyMoves'])) {
                continue;
            }

            $oldRoute  = (string) ($op['route'] ?? '');
            $oldParent = (string) ($op['currentParentRoute'] ?? '');
            $newParent = (string) ($op['newParentRoute'] ?? '');

            if ($oldRoute !== '' && $newParent !== $oldParent) {
                $this->pendingSubtreeDelete[$oldRoute] = true;
                // New route = same slug under the new parent → the subtree's
                // comments move along.
                $slug = substr($oldRoute, (int) strrpos($oldRoute, '/'));
                $this->pendingCommentMove[$oldRoute] = rtrim($newParent, '/') . $slug;
            }
            $this->pendingSubtreeReindex[$oldParent !== '' ? $oldParent : '/'] = true;
            $this->pendingSubtreeReindex[$newParent !== '' ? $newParent : '/'] = true;
        }
    }

    /**
     * A page deleted: purge flagged for onShutdown.
     */
    public function onAdminAfterDelete(Event $event): void
    {
        $object = $event['object'] ?? null;
        if ($object instanceof PageInterface && ($route = $object->route()) !== null) {
            // The whole folder is gone, subpages included, but the admin only
            // emits an event for the deleted page: its descendant articles
            // leave the index with it. Folder still there = deletion of a
            // single translation: the page alone, as before.
            if (is_dir((string) $object->path())) {
                $this->pendingDelete[$route] = $route;
            } else {
                $this->pendingSubtreeDelete[$route] = true;
            }
            // Page deleted → its comments (and its descendants') have nothing
            // left to refer to: purge from the Flex directory.
            $this->pendingCommentDelete[$route] = $route;
        }
    }

    /**
     * Heavy work (getimagesize × N + SQLite writes) run after the response
     * is sent → does not block Admin2's "Save" UI.
     */
    public function onShutdown(): void
    {
        if (empty($this->pendingReindex) && empty($this->pendingDelete)
            && empty($this->pendingSubtreeReindex) && empty($this->pendingSubtreeDelete)
            && empty($this->pendingCommentMove) && empty($this->pendingCommentDelete)
            && empty($this->pendingCommentRecount)) {
            return;
        }

        // The work runs AFTER the HTTP response. On a large article
        // (generating hundreds of thumbnails), PHP's max_execution_time would
        // cut the indexing short → missing thumbnails. The limit is lifted.
        @set_time_limit(0);

        // Under Apache mod_php, the CURRENT DIRECTORY changes in shutdown
        // functions (documented PHP gotcha): any relative path (Flex
        // storage…) would break. Move back to Grav's root.
        if (\defined('GRAV_ROOT')) {
            @chdir(GRAV_ROOT);
        }

        $indexer = $this->getIndexer();

        // Comments first: re-routing (moves) and purges (deleted pages), then
        // the Flex index is refreshed so that the reindexes below
        // (statsByFile) see the up-to-date routes.
        foreach ($this->pendingCommentMove as $old => $new) {
            Comments::changeRoute($old, $new);
        }
        foreach ($this->pendingCommentDelete as $route) {
            Comments::deleteForRoute($route);
        }
        if ($this->pendingCommentMove || $this->pendingCommentDelete) {
            Comments::refresh();
        }
        $this->pendingCommentMove = [];
        $this->pendingCommentDelete = [];

        foreach ($this->pendingDelete as $route) {
            $indexer->forget($route);   // index rows + thumbnails
        }

        foreach ($this->pendingSubtreeDelete as $route => $_) {
            $indexer->forgetSubtree($route);   // rows + thumbnails, descendants included
        }

        foreach ($this->pendingReindex as $page) {
            $indexer->reindexPage($page);
        }

        $this->reindexSubtrees($indexer);

        // SQLite counters of the images touched (admin moderation,
        // re-routings).
        $this->flushCommentRecounts();

        $this->pendingReindex = [];
        $this->pendingDelete = [];
        $this->pendingSubtreeReindex = [];
        $this->pendingSubtreeDelete = [];
    }

    /**
     * Reindexes every indexable article under the flagged subtrees (admin
     * reordering/reorganization). The thumbnails already exist (stable key)
     * → actual cost: media re-scan + SQLite writes.
     */
    private function reindexSubtrees(Indexer $indexer): void
    {
        if (empty($this->pendingSubtreeReindex)) {
            return;
        }

        // The folders were just RENAMED on disk: the request's Pages instance
        // still knows the old paths → reset() forces a re-scan before the
        // folder_sort keys are recomputed.
        $pages = $this->grav['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }
        $pages->reset();

        $roots = array_keys($this->pendingSubtreeReindex);

        foreach ($pages->instances() as $page) {
            if (!$page instanceof PageInterface) {
                continue;
            }
            $route = (string) $page->route();
            if ($route === '' || !self::underAny($roots, $route)) {
                continue;
            }
            if ($indexer->isIndexable($page)) {
                $indexer->reindexPage($page);
            }
        }
    }

    /**
     * Is the route one of the roots or one of their descendants?
     */
    private static function underAny(array $roots, string $route): bool
    {
        foreach ($roots as $root) {
            if ($root === '/' || $route === $root || str_starts_with($route, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    private function getDb(): IndexDatabase
    {
        return $this->db ??= IndexDatabase::fromConfig();
    }

    private function getIndexer(): Indexer
    {
        return $this->indexer ??= Indexer::fromConfig($this->getDb());
    }
}
