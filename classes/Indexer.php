<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Media;

/**
 * Indexing business logic: turns a Grav page into image rows ready for the
 * index, and delegates writing to IndexDatabase.
 *
 * Single source of truth shared by the event handlers AND the CLI.
 */
class Indexer
{
    private IndexDatabase $db;

    /** @var string[] Templates considered as indexable articles. */
    private array $templates;

    /** @var int Target width of generated thumbnails (px). */
    private int $thumbWidth;

    /** @var string[] "Root" routes: their descendant pages are indexed. */
    private array $roots;

    /** @var string[] Included image extensions (allow list, lowercase). */
    private array $extensions;

    /** @var string `.meta.yaml` tag hiding the image from its article's gallery (empty = none). */
    private string $excludeTagArticle;

    /** @var string `.meta.yaml` tag hiding the image from the global gallery (empty = none). */
    private string $excludeTagGlobal;

    /** Thumbnails: reuse what exists, create what is missing (default). */
    public const THUMBS_MISSING = 'missing';
    /** Thumbnails: create none (index only); missing → the original is served. */
    public const THUMBS_NONE = 'none';
    /** Thumbnails: recreate everything, even what exists. */
    public const THUMBS_FORCE = 'force';

    private string $thumbMode = self::THUMBS_MISSING;

    /** Thumbnails written to galinf-thumbs/ by this instance (reports). */
    private int $thumbsCreated = 0;

    /**
     * @param string[] $templates   indexed templates (empty = any template)
     * @param string[] $roots        page/folder routes whose descendants are
     *                              indexed (empty = the whole hierarchy)
     * @param string[] $extensions  included image extensions (without dot)
     * @param string $excludeTagArticle tag hiding the image from its
     *                              article's gallery (empty = feature off)
     * @param string $excludeTagGlobal  tag hiding the image from the global
     *                              gallery (empty = feature off)
     */
    public function __construct(
        IndexDatabase $db,
        array $templates,
        int $thumbWidth = 500,
        array $roots = [],
        array $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        string $excludeTagArticle = '',
        string $excludeTagGlobal = ''
    ) {
        $this->db = $db;
        $this->excludeTagArticle = mb_strtolower(trim($excludeTagArticle));
        $this->excludeTagGlobal  = mb_strtolower(trim($excludeTagGlobal));
        $this->templates = $templates;
        $this->thumbWidth = $thumbWidth;
        $this->roots = $roots;
        // Normalize: lowercase, no dot, no duplicate.
        $this->extensions = array_values(array_unique(array_filter(array_map(
            static fn($e) => strtolower(ltrim(trim((string) $e), '.')),
            $extensions
        ))));
        if ($this->extensions === []) {
            $this->extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        }
    }

    /**
     * Indexer set up from the plugin configuration. Single entry point: an
     * option added here applies to live indexing, reindexing and the CLI.
     */
    public static function fromConfig(IndexDatabase $db): self
    {
        $config = Grav::instance()['config'];

        return new self(
            $db,
            (array) $config->get('plugins.galinf.templates', ['item']),
            (int) $config->get('plugins.galinf.thumb_width', 500),
            array_filter((array) $config->get('plugins.galinf.roots', [])),
            (array) $config->get('plugins.galinf.extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif']),
            (string) $config->get('plugins.galinf.exclude_tag_article', 'nogal'),
            (string) $config->get('plugins.galinf.exclude_tag_global', 'nogalinf')
        );
    }

    public function setThumbMode(string $mode): void
    {
        $this->thumbMode = \in_array($mode, [self::THUMBS_NONE, self::THUMBS_FORCE], true)
            ? $mode
            : self::THUMBS_MISSING;
    }

    public function thumbsCreated(): int
    {
        return $this->thumbsCreated;
    }

    /**
     * Is a page an article to index?
     */
    /**
     * Has the site chosen which pages to index? With no template and no root
     * (the default after installing), nothing is indexed.
     */
    public function hasScope(): bool
    {
        return $this->templates !== [] || $this->roots !== [];
    }

    public function isIndexable(PageInterface $page): bool
    {
        if (!$this->hasScope()) {
            return false;
        }

        // Scheduled publishing: the article is indexed IN ADVANCE with its
        // dates, and the gallery only serves it during its publishing period
        // (IndexDatabase::live). Otherwise, Grav's published state.
        $schedule = self::schedule($page);
        if ($schedule === null) {
            if (!$page->published()) {
                return false;
            }
        } elseif ($schedule[1] !== null && $schedule[1] <= time()) {
            return false;   // publishing period already over
        }

        $route = (string) $page->route();
        if ($route === '') {
            return false;
        }

        // A page that cannot be reached by its address (routable: false, e.g.
        // a modular sub-page) has no place in a public gallery, and its
        // images would show up broken there.
        if (!$page->routable()) {
            return false;
        }

        // Only pages stored under user/pages/: plugins can register pages
        // from their own folder (e.g. the login plugin's /login), whose
        // files must never end up in a gallery.
        if (!self::underPagesFolder((string) $page->path())) {
            return false;
        }

        // Restricted page (`access:` rule on it or on an ancestor): never
        // indexed, with no exception or option. The gallery is public, and
        // thumbnails are COPIES served statically, outside any access
        // control: indexing a restricted page would expose its images to
        // those who are not allowed to see it.
        if (self::isProtected($page)) {
            return false;
        }

        // Template filter (empty list = any template, roots decide).
        if ($this->templates !== [] && !\in_array($page->template(), $this->templates, true)) {
            return false;
        }

        // Root filter (empty list = the whole site, templates decide).
        // Otherwise the page must be one of the roots or one of their
        // descendants.
        if ($this->roots !== [] && !$this->underRoots($route)) {
            return false;
        }

        return true;
    }

    private function underRoots(string $route): bool
    {
        foreach ($this->roots as $root) {
            $root = '/' . trim((string) $root, '/');
            if ($root === '/') {
                return true; // site root → everything
            }
            if ($route === $root || str_starts_with($route, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Visible NOW (info panel, comment posting): indexable and published at
     * this instant according to Grav. A scheduled article is indexed in
     * advance but must not expose anything before its date.
     */
    public function isServable(PageInterface $page): bool
    {
        return $this->isIndexable($page) && $page->published();
    }

    /**
     * Scheduled publishing dates [start, end] (timestamps or null), or null
     * if the dates do not apply. Same rule as Grav (Page::setPublishState):
     * `system.pages.publish_dates` setting on and no explicit `published:` in
     * the header — an explicit value takes precedence over the dates.
     *
     * @return array{0:?int, 1:?int}|null
     */
    public static function schedule(PageInterface $page): ?array
    {
        if (!Grav::instance()['config']->get('system.pages.publish_dates', true)) {
            return null;
        }
        $header = $page->header();
        $explicit = \is_object($header) ? isset($header->published) : isset($header['published']);
        if ($explicit) {
            return null;
        }
        $start = (int) $page->publishDate();
        $end   = (int) $page->unpublishDate();
        if ($start <= 0 && $end <= 0) {
            return null;
        }
        return [$start > 0 ? $start : null, $end > 0 ? $end : null];
    }

    /**
     * Does the page, or one of its ancestors, carry an access rule (`access:`
     * in its header, read by the login plugin)? Ancestors are checked in
     * every case, whether login's `parent_acl` option is on or not: when in
     * doubt, nothing is exposed.
     */
    /**
     * Is this folder inside the site's pages folder (`page://`, normally
     * user/pages/)? Compared on real paths, so neither `..` nor a symbolic
     * link pointing elsewhere can get around it.
     */
    private static function underPagesFolder(string $path): bool
    {
        static $root = null;
        if ($root === null) {
            $found = Grav::instance()['locator']->findResource('page://', true);
            $root  = \is_string($found) ? (string) realpath($found) : '';
        }
        $real = $path !== '' ? realpath($path) : false;

        return $root !== '' && \is_string($real)
            && ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR));
    }

    public static function isProtected(PageInterface $page): bool
    {
        for ($p = $page, $guard = 0; $p !== null && $guard < 50; $p = $p->parent(), $guard++) {
            $header = $p->header();
            $access = \is_object($header) ? ($header->access ?? null) : ($header['access'] ?? null);
            if (!empty($access)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Removes an article from the index AND deletes its thumbnails from disk.
     * A page that became restricted or unpublished must not leave behind
     * thumbnails still reachable by their address.
     */
    public function forget(string $route): void
    {
        $this->unlinkThumbs($this->db->thumbPathsForRoute($route));
        $this->db->deleteArticle($route);
    }

    /**
     * Like forget(), for a page AND all its descendants. An admin operation
     * on a parent page (deletion, move, `access:` rule) only emits one event,
     * for that page alone: its articles must follow it.
     *
     * The site root has no "descendants" in terms of routes (the home page's
     * children are not under `/`): only the page itself is handled then,
     * rather than emptying the whole index.
     */
    public function forgetSubtree(string $route): void
    {
        if (trim($route, '/') === '') {
            $this->forget($route);
            return;
        }
        $this->unlinkThumbs($this->db->thumbPathsForRoute($route, true));
        $this->db->deleteSubtree($route);
    }

    /**
     * @param string[] $paths paths relative to the site root
     */
    private function unlinkThumbs(array $paths): void
    {
        $root = defined('GRAV_ROOT') ? GRAV_ROOT : getcwd();
        foreach ($paths as $rel) {
            if (str_starts_with($rel, 'galinf-thumbs/') && !str_contains($rel, '..')) {
                @unlink($root . '/' . $rel);
            }
        }
    }

    /**
     * (Re)indexes a page: reads its image media and replaces its rows.
     *
     * @return int Number of images indexed for this article.
     */
    public function reindexPage(PageInterface $page): int
    {
        $route = $page->route();
        if ($route === null) {
            return 0;
        }

        $rows = $this->buildRows($page);
        $this->db->replaceArticle($route, $rows);

        return \count($rows);
    }

    /**
     * Builds a page's image rows from Grav's media API. Dimensions are read
     * through the medium (getimagesize cached by Grav), never recomputed on
     * the fly on the gallery side.
     *
     * @return array[]
     */
    private function buildRows(PageInterface $page): array
    {
        $route        = $page->route();
        $title        = (string) $page->title();
        $publishedAt  = (int) $page->date();
        $folderSort   = self::folderSortKey($page);
        [$publishAt, $unpublishAt] = self::schedule($page) ?? [null, null];
        $rows         = [];
        $sort         = 0;

        // Media are read through a fresh Media rather than $page->media():
        // the latter goes through a persistent cache that is not invalidated
        // when an image is added through the admin → the live path
        // (onAdminAfterSave) would see a stale list. new Media() scans the
        // folder directly.
        $media  = new Media($page->path());
        $config = Grav::instance()['config'];
        $dir    = $page->path();

        // Comment cache recomputed from the Flex directory (a single index
        // read per article, no object loaded).
        $commentStats = Comments::statsByFile($route);

        // ->all() (not ->images()) because ->images() drops the formats Grav
        // does not process (gif); filtering on the allow list is done here.
        foreach ($media->all() as $filename => $medium) {
            $ext = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));
            if (!\in_array($ext, $this->extensions, true)) {
                continue; // not on the allow list (other media, svg, videos…)
            }

            // Grav type: 'image' (resizable) | 'animated' (gif) | 'vector'…
            $animated = ((string) $config->get('media.types.' . $ext . '.type', '') === 'animated');

            // Dimensions: through the medium, otherwise fallback to
            // getimagesize (gif case, whose dimensions Grav does not expose).
            $width  = (int) $medium->get('width');
            $height = (int) $medium->get('height');
            if ($width <= 0 || $height <= 0) {
                $sz = @getimagesize($dir . '/' . $filename);
                if ($sz !== false) {
                    $width  = (int) $sz[0];
                    $height = (int) $sz[1];
                }
            }
            if ($width <= 0 || $height <= 0) {
                continue; // dimensions not found → skipped
            }

            $imagePath = ltrim($route, '/') . '/' . $filename;

            [$commentsCount, $lastCommentAt] = $commentStats[(string) $filename] ?? [0, 0];
            [$hideArticle, $hideGlobal] = $this->hiddenFlags($medium);

            $rows[] = [
                'image_path'      => $imagePath,
                // Animated formats (gif): NO thumbnail (Grav does not resize
                // them) → the feed serves the original (which animates).
                // Others: pre-generated persistent thumbnail.
                'thumb_path'      => $animated ? null : $this->buildThumb($medium, $imagePath),
                'width'           => $width,
                'height'          => $height,
                'article_title'   => $title,
                'published_at'    => $publishedAt,
                'sort_in_article' => $sort++,
                'animated'        => $animated,
                'folder_sort'     => $folderSort,
                'comments_count'  => $commentsCount,
                'last_comment_at' => $lastCommentAt,
                'hide_article'    => $hideArticle,
                'hide_global'     => $hideGlobal,
                'publish_at'      => $publishAt,
                'unpublish_at'    => $unpublishAt,
            ];
        }

        return $rows;
    }

    /**
     * Hiding derived from the TAGS of the image's `.meta.yaml` (`tags` key,
     * the one the admin's metadata editor writes with a "Tags" field).
     * Matched on the exact tag value, ignoring case and extra spaces —
     * `nogal` therefore cannot trigger `nogalinf`.
     *
     * No file read here: Grav already loads the sidecar when initializing
     * Media (addMetaFile), and the Indexer precisely builds a fresh Media per
     * article.
     *
     * @return array{0:int, 1:int} [hide from article, hide from global]
     */
    public function hiddenFlags($medium): array
    {
        if ($this->excludeTagArticle === '' && $this->excludeTagGlobal === '') {
            return [0, 0];
        }

        try {
            $meta = (array) $medium->metadata();
        } catch (\Throwable $e) {
            return [0, 0];   // unreadable sidecar: image visible (as before)
        }

        $tags = $meta['tags'] ?? null;
        if (!is_array($tags)) {
            // Also tolerates a manual "tags: a, b" entry (string).
            $tags = is_string($tags) && $tags !== '' ? explode(',', $tags) : [];
        }

        $set = [];
        foreach ($tags as $tag) {
            if (is_scalar($tag)) {
                $set[mb_strtolower(trim((string) $tag))] = true;
            }
        }

        return [
            ($this->excludeTagArticle !== '' && isset($set[$this->excludeTagArticle])) ? 1 : 0,
            ($this->excludeTagGlobal !== '' && isset($set[$this->excludeTagGlobal])) ? 1 : 0,
        ];
    }

    /**
     * "Admin manual order" sort key: the page's real folder path (WITH the
     * numeric prefixes `01.`, `02.`… that the route does not contain), made
     * comparable as TEXT by emulating Grav's NATURAL sort (`by: folder` =
     * SORT_NATURAL, or Collator NUMERIC_COLLATION with intl): EVERY digit
     * sequence of the name is compared by VALUE, whether it is an order
     * prefix (`2.x` before `10.x`) or not (`08` before `019` before `0124`) —
     * it is replaced by its value padded to a fixed width. A text sort on
     * this key thus reproduces the page tree order (depth-first), identical
     * to a blog sorted `by: folder`.
     */
    private static function folderSortKey(PageInterface $page): string
    {
        $pagesDir = (string) Grav::instance()['locator']->findResource('page://', true);
        $path     = (string) $page->path();

        $rel = ($pagesDir !== '' && str_starts_with($path, $pagesDir))
            ? trim(substr($path, \strlen($pagesDir)), '/')
            : trim($path, '/');

        $parts = [];
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '') {
                continue;
            }
            $parts[] = preg_replace_callback(
                '/\d+/',
                static fn(array $m): string => str_pad(ltrim($m[0], '0') ?: '0', 20, '0', STR_PAD_LEFT),
                mb_strtolower($seg)
            );
        }

        return implode('/', $parts);
    }

    /**
     * Places (once) a long-cache .htaccess in galinf-thumbs/: file names are
     * hashes → immutable content for a given name, the browser can keep them
     * a year without revalidating. Without these headers, Apache only sends
     * ETag/Last-Modified and the browser requests the thumbnails again on
     * every visit (costly on a slow connection). Also called at the end of
     * an orphan purge, which puts it back if it is missing.
     */
    public static function ensureThumbsHtaccess(string $root): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $dir = $root . '/galinf-thumbs';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . '/.htaccess';
        if (is_file($file)) {
            return;
        }

        @file_put_contents($file, <<<'HT'
# galinf thumbnails: hash-based names -> immutable content for a given name.
# Long browser cache, no revalidation.
<IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=31536000, immutable"
</IfModule>
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresDefault "access plus 1 year"
</IfModule>
HT);
    }

    /**
     * Generates (write-time) the thumbnail and stores it in a PERSISTENT
     * folder of the site (`galinf-thumbs/`, outside Grav's cache) → it
     * survives any cache clear (Grav update, "Clear all"), with no reindex.
     *
     * Stable name = md5(image_path | width), sharded. If the thumbnail
     * already exists AND is not older than the source image, it is reused
     * (fast reindex). Otherwise it is (re)generated through Grav's media
     * pipeline (quality, EXIF orientation) then copied into
     * `galinf-thumbs/`. Returns the path relative to the root (without
     * base_url), or null on failure (the feed then falls back on the full
     * image).
     */
    private function buildThumb($medium, string $imagePath): ?string
    {
        $root = defined('GRAV_ROOT') ? GRAV_ROOT : getcwd();

        $ext  = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION)) ?: 'jpg';
        $hash = md5($imagePath . '|' . $this->thumbWidth);
        $relDir   = 'galinf-thumbs/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $thumbRel = $relDir . '/' . $hash . '.' . $ext;
        $thumbAbs = $root . '/' . $thumbRel;

        self::ensureThumbsHtaccess($root);

        // Already generated (persistent) → reused, UNLESS the source image
        // has been replaced since (same name, different content: the hash key
        // only depends on the path and the width, so it does not change).
        // Without this date comparison, the gallery would serve the old
        // thumbnail forever with the new dimensions — wrong and distorted
        // image. Grav core fixed the same pitfall in its own derivative cache
        // in 2.0.8 (mtime + size in the key): the regeneration below does
        // get the new image.
        if ($this->thumbMode !== self::THUMBS_FORCE && is_file($thumbAbs)) {
            $srcAbs  = method_exists($medium, 'path') ? (string) $medium->path() : '';
            $srcTime = ($srcAbs !== '' && is_file($srcAbs)) ? (int) filemtime($srcAbs) : 0;
            if ($srcTime === 0 || (int) filemtime($thumbAbs) >= $srcTime) {
                return self::thumbUrl($thumbRel, $thumbAbs);
            }
        }

        // Index only: nothing is created. A missing (or outdated, hence
        // wrong) thumbnail lets the feed serve the original meanwhile.
        if ($this->thumbMode === self::THUMBS_NONE) {
            return null;
        }

        // Generation through Grav (cached under images/), then persistent
        // copy.
        try {
            $url = $medium->resize($this->thumbWidth)->url();
        } catch (\Throwable $e) {
            return null;
        }
        $pos = strpos($url, 'images/');
        if ($pos === false) {
            return null;
        }
        $cacheAbs = $root . '/' . ltrim(substr($url, $pos), '/');
        if (!is_file($cacheAbs)) {
            return null;
        }

        $absDir = $root . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            return null;
        }
        if (!@copy($cacheAbs, $thumbAbs)) {
            return null;
        }
        clearstatcache(true, $thumbAbs);
        $this->thumbsCreated++;

        return self::thumbUrl($thumbRel, $thumbAbs);
    }

    /**
     * Thumbnail path suffixed with a version token (`?v=<mtime>`).
     *
     * The file NAME only depends on the path and the width: an image replaced
     * under the same name therefore reuses the same thumbnail — yet
     * `galinf-thumbs/` is served with an "immutable" one-year browser cache
     * (see ensureThumbsHtaccess). Without this token, a visitor who had
     * already seen the old thumbnail would keep it for up to a year after the
     * replacement, without even revalidating. Feed::url() splits off and
     * keeps the query string, and IndexDatabase::allThumbPaths() strips it
     * before comparing with the disk.
     */
    private static function thumbUrl(string $rel, string $abs): string
    {
        $ts = @filemtime($abs);

        return $ts ? $rel . '?v=' . $ts : $rel;
    }
}
