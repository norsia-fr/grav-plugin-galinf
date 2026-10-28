<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;

/**
 * Orchestration of the gallery's full reindex.
 *
 * Single source shared by the CLI commands (`bin/plugin galinf …`) and the
 * admin endpoints of the Maintenance block.
 */
class ReindexService
{
    /** Prefix of the meta keys of the last runs log. */
    private const LAST_RUN = 'last_run_';

    /**
     * Rebuilds the index from every indexable page, in one go (CLI; the
     * admin goes through runBatch, in batches).
     *
     * @param bool          $clear     true → starts from an empty index
     * @param callable|null $progress  called after each article:
     *        fn(int $done, int $total, string $route, int $images, int $imagesTotal)
     *        (CLI: progress bar; API/button: null = silent)
     * @param string        $thumbs    Indexer::THUMBS_* (missing | none | all)
     * @return array{articles:int, images:int, thumbs_created:int, pruned:int, total:int, last_indexed_at:?int, missing_thumbs:array}
     */
    public static function run(bool $clear = false, ?callable $progress = null, string $thumbs = Indexer::THUMBS_MISSING): array
    {
        // Full rebuild = generation of every thumbnail (~100 ms per image).
        // In a web context (admin endpoint), max_execution_time would cut the
        // operation short on a large volume. The limit is lifted here too.
        @set_time_limit(0);

        $db = IndexDatabase::fromConfig();
        $indexer = Indexer::fromConfig($db);
        $indexer->setThumbMode($thumbs);

        if ($clear) {
            $db->reset();
        } else {
            $db->migrate();
        }

        // Indexable pages are listed first to know the total (needed for a
        // progress bar).
        $targets = self::targets($indexer);
        $total = \count($targets);

        $articles = 0;
        $images   = 0;
        foreach ($targets as $page) {
            $n = $indexer->reindexPage($page);
            $images += $n;
            $articles++;
            if ($progress !== null) {
                $progress($articles, $total, (string) $page->route(), $n, $images);
            }
        }

        return [
            'articles'        => $articles,
            'images'          => $images,
            'thumbs_created'  => $indexer->thumbsCreated(),
            'pruned'          => self::pruneVanished($indexer, $db, $targets),
            'total'           => $db->countAllImages(),
            'last_indexed_at' => $db->lastIndexedAt(),
            'missing_thumbs'  => self::missingThumbs($db),
        ];
    }

    /**
     * Same work as run(), but in time-bounded BATCHES: the admin chains the
     * calls and shows progress, instead of a single request lasting tens of
     * minutes with no feedback. The short budget (2 s) also bounds how long
     * the "Stop" button takes to be honored.
     *
     * Articles are taken in route order from $offset; at least one is
     * processed per call, then processing stops as soon as the time budget
     * is exceeded. Since each article is replaced in one transaction, an
     * interruption between two calls leaves a consistent index.
     *
     * @param bool   $reset  empties the index before starting (only honored at offset 0)
     * @param string $action identifier of the Maintenance block action: its full run
     *                       is logged (date, duration, volumes) for the admin
     * @return array{offset:int, total:int, done:bool, articles:int, images:int, thumbs_created:int, route:string, ...}
     *         + pruned/total_images/last_indexed_at/missing_thumbs when done
     */
    public static function runBatch(string $thumbs, int $offset = 0, bool $reset = false, string $action = '', float $budget = 2.0): array
    {
        @set_time_limit(0);
        $started = microtime(true);

        $db = IndexDatabase::fromConfig();
        $indexer = Indexer::fromConfig($db);
        $indexer->setThumbMode($thumbs);

        if ($reset && $offset === 0) {
            $db->reset();
        } else {
            $db->migrate();
        }

        $targets = self::targets($indexer);
        $total   = \count($targets);
        $offset  = max(0, $offset);
        $first   = $offset === 0;

        $articles = 0;
        $images   = 0;
        $route    = '';
        while ($offset < $total) {
            $page   = $targets[$offset++];
            $route  = (string) $page->route();
            $images += $indexer->reindexPage($page);
            $articles++;
            if (microtime(true) - $started >= $budget) {
                break;
            }
        }

        $result = [
            'offset'         => $offset,
            'total'          => $total,
            'done'           => $offset >= $total,
            'articles'       => $articles,
            'images'         => $images,
            'thumbs_created' => $indexer->thumbsCreated(),
            'route'          => $route,
        ];
        if ($action !== '') {
            // Accumulated from one batch to the next; duration = time really
            // elapsed for the user, waits between requests included.
            $key   = 'run_progress_' . $action;
            $state = $first ? null : json_decode((string) $db->getMeta($key), true);
            $state = \is_array($state) ? $state : ['started' => $started, 'images' => 0, 'thumbs' => 0];
            $state['images'] += $images;
            $state['thumbs'] += $indexer->thumbsCreated();
            if ($result['done']) {
                self::recordRun($db, $action, [
                    'seconds' => round(microtime(true) - $state['started'], 1),
                    'images'  => $state['images'],
                    'thumbs'  => $state['thumbs'],
                ]);
                $db->deleteMeta($key);
            } else {
                $db->setMeta($key, json_encode($state));
            }
        }
        if ($result['done']) {
            $result += [
                'pruned'          => self::pruneVanished($indexer, $db, $targets),
                'total_images'    => $db->countAllImages(),
                'last_indexed_at' => $db->lastIndexedAt(),
                'missing_thumbs'  => self::missingThumbs($db),
            ];
        }

        return $result;
    }

    /**
     * Logs the last FULL run of a Maintenance block action (meta table),
     * shown under its button in the admin.
     */
    private static function recordRun(IndexDatabase $db, string $action, array $data): void
    {
        $db->setMeta(self::LAST_RUN . $action, json_encode(['at' => time()] + $data));
    }

    /**
     * @return array<string, array> per action identifier: {at, seconds, ...}
     */
    private static function lastRuns(IndexDatabase $db): array
    {
        $runs = [];
        foreach ($db->metaByPrefix(self::LAST_RUN) as $key => $value) {
            $data = json_decode($value, true);
            if (\is_array($data)) {
                $runs[substr($key, \strlen(self::LAST_RUN))] = $data;
            }
        }
        return $runs;
    }

    /**
     * Indexable pages, sorted by route: a stable order from one call to the
     * next, essential for batch processing (offset).
     *
     * @return \Grav\Common\Page\Interfaces\PageInterface[]
     */
    private static function targets(Indexer $indexer): array
    {
        $targets = [];
        foreach (self::pages()->instances() as $page) {
            if ($indexer->isIndexable($page)) {
                $targets[(string) $page->route()] = $page;
            }
        }
        ksort($targets, SORT_STRING);

        return array_values($targets);
    }

    /**
     * Removes from the index the articles that no longer exist or are no
     * longer indexable (deleted outside the admin, unpublished, now
     * restricted, out of the `roots`…): no event purged them. Returns the
     * number of articles removed.
     *
     * Safeguard: if NO indexable page was found, nothing is purged. A failed
     * page load would otherwise empty the whole gallery.
     */
    private static function pruneVanished(Indexer $indexer, IndexDatabase $db, array $targets): int
    {
        if (!$targets) {
            return 0;
        }
        $alive = [];
        foreach ($targets as $page) {
            $alive[(string) $page->route()] = true;
        }

        $pruned = 0;
        foreach ($db->articleRoutes() as $route) {
            if (!isset($alive[$route])) {
                // Thumbnails included: an article that became restricted
                // leaves nothing reachable behind it.
                $indexer->forget($route);
                $pruned++;
            }
        }
        return $pruned;
    }

    /** Meta key of the purge in progress (totals accumulated across batches). */
    private const PURGE_PROGRESS = 'run_progress_purge';

    /**
     * A file the purge may delete: a thumbnail as the Indexer names it,
     * `galinf-thumbs/aa/bb/<md5>.<ext>` where aa/bb are the first characters
     * of the hash. Anything else (the .htaccess, a file put there by hand) is
     * never touched.
     */
    private const THUMB_FILE = '#^galinf-thumbs/([0-9a-f]{2})/([0-9a-f]{2})/\1\2[0-9a-f]{28}\.[a-z0-9]+$#';

    /**
     * Deletes from `galinf-thumbs/` the thumbnails that no longer match any
     * image in the index (deleted images, or an old thumbnail width), in
     * time-bounded BATCHES like runBatch(): the admin chains the calls,
     * shows progress and can stop. Short requests also keep the site
     * responsive during a purge of tens of thousands of files.
     *
     * The unit of work is a first-level shard folder (`aa/`, at most 256),
     * taken in name order AFTER $cursor (the last folder done): resuming by
     * name rather than by position, since emptied folders are removed along
     * the way. Only thumbnail files are deleted (see THUMB_FILE); the
     * .htaccess is put back at the end if it is missing.
     *
     * @param string $cursor last folder processed ('' = start)
     * @return array{cursor:string, processed:int, total:int, done:bool, deleted:int, kept:int, freed:int}
     *         processed/total = folders; deleted/kept/freed = run totals so far
     */
    public static function purgeBatch(string $cursor = '', float $budget = 2.0): array
    {
        @set_time_limit(0);
        $started = microtime(true);

        $root = defined('GRAV_ROOT') ? GRAV_ROOT : getcwd();
        $dir  = $root . '/galinf-thumbs';

        $db = IndexDatabase::fromConfig();
        $db->migrate();
        $valid = $db->allThumbPaths();

        $folders = [];
        foreach (is_dir($dir) ? (array) scandir($dir) : [] as $name) {
            if (preg_match('/^[0-9a-f]{2}$/', (string) $name) && is_dir($dir . '/' . $name)) {
                $folders[] = (string) $name;
            }
        }
        sort($folders, SORT_STRING);

        $state = $cursor !== '' ? json_decode((string) $db->getMeta(self::PURGE_PROGRESS), true) : null;
        if (!\is_array($state)) {
            $state = ['started' => $started, 'total' => \count($folders), 'processed' => 0,
                      'deleted' => 0, 'kept' => 0, 'freed' => 0];
        }

        $todo = array_values(array_filter($folders, static fn(string $f): bool => strcmp($f, $cursor) > 0));
        foreach ($todo as $folder) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir . '/' . $folder, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname()); // only removes it if empty
                    continue;
                }
                $rel = 'galinf-thumbs/' . str_replace('\\', '/', substr($file->getPathname(), \strlen($dir) + 1));
                if (isset($valid[$rel]) || !preg_match(self::THUMB_FILE, $rel)) {
                    $state['kept']++;
                    continue;
                }
                $size = (int) $file->getSize();
                if (@unlink($file->getPathname())) {
                    $state['deleted']++;
                    $state['freed'] += $size;
                }
            }
            @rmdir($dir . '/' . $folder); // only removes it if empty
            $cursor = $folder;
            $state['processed']++;
            if (microtime(true) - $started >= $budget) {
                break;
            }
        }

        // Done when no folder is left after the cursor (robust even if a
        // folder vanished between two calls).
        $done = array_filter($folders, static fn(string $f): bool => strcmp($f, $cursor) > 0) === [];
        if ($done) {
            Indexer::ensureThumbsHtaccess($root);
            self::recordRun($db, 'purge', [
                'seconds' => round(microtime(true) - (float) $state['started'], 1),
                'deleted' => $state['deleted'],
                'freed'   => $state['freed'],
            ]);
            $db->deleteMeta(self::PURGE_PROGRESS);
        } else {
            $db->setMeta(self::PURGE_PROGRESS, json_encode($state));
        }

        return [
            'cursor'    => $cursor,
            'processed' => min((int) $state['processed'], (int) $state['total']),
            'total'     => (int) $state['total'],
            'done'      => $done,
            'deleted'   => (int) $state['deleted'],
            'kept'      => (int) $state['kept'],
            'freed'     => (int) $state['freed'],
        ];
    }

    /**
     * The whole orphan purge in one go (CLI), through the same batches as
     * the admin.
     *
     * @param callable|null $progress called after each batch: fn(array $batch)
     * @return array{deleted:int, kept:int, freed:int}
     */
    public static function purgeOrphanThumbs(?callable $progress = null): array
    {
        $cursor = '';
        do {
            $batch  = self::purgeBatch($cursor);
            $cursor = $batch['cursor'];
            if ($progress !== null) {
                $progress($batch);
            }
        } while (!$batch['done']);

        return ['deleted' => $batch['deleted'], 'kept' => $batch['kept'], 'freed' => $batch['freed']];
    }

    /**
     * Deletes the data the plugin created on disk, ahead of its
     * uninstallation: the SQLite index and the thumbnails folder, both
     * rebuildable by a reindex. Visitor COMMENTS, which are irreplaceable,
     * are only deleted on explicit request. The settings
     * (`user/config/plugins/galinf.yaml`) are left in place.
     *
     * Deliberately, nothing is hooked to the uninstallation itself:
     * uninstalling to reinstall must not lose anything.
     *
     * NB: while the plugin stays active, the next visit to the gallery
     * recreates an empty database. To run just before uninstalling.
     *
     * @return array{index:bool, thumbs:int, comments:?int, freed:int}
     */
    public static function removeData(bool $withComments = false): array
    {
        @set_time_limit(0);
        $root  = defined('GRAV_ROOT') ? GRAV_ROOT : getcwd();
        $freed = 0;

        // Persistent thumbnails (fixed folder at the site root).
        [$thumbs, $bytes] = self::measureTree($root . '/galinf-thumbs', '.htaccess');
        $freed += $bytes;
        if (is_dir($root . '/galinf-thumbs')) {
            Folder::delete($root . '/galinf-thumbs');
        }

        // SQLite index and its companion files (WAL mode).
        $db   = IndexDatabase::fromConfig();
        $path = $db->path();
        $db->close();
        $index = false;
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                $freed += (int) filesize($file);
                $index = @unlink($file) || $index;
            }
        }
        @rmdir(\dirname($path)); // only if it is empty

        $comments = null;
        if ($withComments) {
            $folder = Grav::instance()['locator']->findResource('user-data://galinf/comments', true);
            $comments = 0;
            if (\is_string($folder) && is_dir($folder)) {
                [$comments, $bytes] = self::measureTree($folder, 'index.yaml');
                $freed += $bytes;
                Folder::delete($folder);
                @rmdir(\dirname($folder)); // user/data/galinf, if it is empty
                Comments::refresh();
            }
        }

        return ['index' => $index, 'thumbs' => $thumbs, 'comments' => $comments, 'freed' => $freed];
    }

    /**
     * @return array{0:int, 1:int} [number of files outside $ignore, total bytes]
     */
    private static function measureTree(string $dir, string $ignore): array
    {
        if (!is_dir($dir)) {
            return [0, 0];
        }
        $count = 0;
        $bytes = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $bytes += (int) $file->getSize();
                if ($file->getFilename() !== $ignore) {
                    $count++;
                }
            }
        }
        return [$count, $bytes];
    }

    /**
     * Lightweight index stats (without reindexing).
     *
     * @return array{total:int, last_indexed_at:?int, missing_thumbs:array, last_runs:array}
     */
    public static function stats(): array
    {
        $db = IndexDatabase::fromConfig();
        $db->migrate();
        $config = Grav::instance()['config'];

        return [
            'total'           => $db->countAllImages(),
            'last_indexed_at' => $db->lastIndexedAt(),
            'missing_thumbs'  => self::missingThumbs($db),
            'last_runs'       => self::lastRuns($db),
            // No page chosen yet (default after installing): the admin says so.
            'scope_empty'     => !Indexer::fromConfig($db)->hasScope(),
            // Comment captcha that cannot work (only matters when visitors who
            // are not logged in may comment).
            'captcha_issue'   => $config->get('plugins.galinf.comments_enabled', false)
                && $config->get('plugins.galinf.comments_who', 'anonymous') === 'anonymous'
                ? FormCaptcha::issue() : null,
        ];
    }

    /**
     * List of images without a thumbnail for the admin box and the CLI
     * (beyond the first 20 returned with the stats).
     *
     * @return array{count:int, over_limit:int, max_pixels:int, items:array}
     */
    public static function missingThumbsList(int $limit = 1000): array
    {
        $db = IndexDatabase::fromConfig();
        $db->migrate();

        return self::missingThumbs($db, $limit);
    }

    /**
     * Shrinks in place indexed images left without a thumbnail because they
     * exceed `system.images.max_pixels`, then reindexes their articles to
     * create the thumbnails. THE ORIGINALS ARE REPLACED.
     *
     * Only a path present in the index is accepted: the caller therefore
     * cannot designate any other file on the server.
     *
     * Two phases, and the order matters: ALL files are shrunk before any
     * reindex. The core remembers each source's size for the lifetime of the
     * process (ImageMediaTrait::$sourceSizeCache) as soon as it tries to
     * resize it; reindexing an article between two shrinks would freeze
     * there the old size of its other images, which would stay refused —
     * hence without a thumbnail — once shrunk.
     *
     * @param string[]      $imagePaths
     * @param callable|null $progress  called after each shrink: fn(string $path, array $result)
     * @return array<string, array> per path: {ok:true, old_width, old_height, width, height, thumb:bool}
     *                              or {ok:false, error:string, code?:string}
     */
    public static function shrinkOversized(array $imagePaths, ?callable $progress = null): array
    {
        $maxPixels = (int) Grav::instance()['config']->get('system.images.max_pixels', 25000000);

        $db = IndexDatabase::fromConfig();
        $db->migrate();
        $pages = self::pages();

        $results = [];
        $touched = [];
        foreach ($imagePaths as $imagePath) {
            $imagePath = (string) $imagePath;
            try {
                $row = $db->findImage($imagePath);
                if ($row === null) {
                    throw new ShrinkError('not_indexed', 'Image not in the index.');
                }
                if ((int) $row['animated'] === 1 || $row['thumb_path'] !== null) {
                    throw new ShrinkError('has_thumb', 'This image already has a thumbnail.');
                }
                $route = (string) $row['article_route'];
                $page  = $touched[$route] ?? $pages->find($route);
                if ($page === null) {
                    throw new ShrinkError('no_article', 'Article not found.');
                }

                $results[$imagePath] = ['ok' => true]
                    + ImageShrinker::shrink($page->path() . '/' . basename($imagePath), $maxPixels);
                $touched[$route] = $page;
            } catch (\RuntimeException $e) {
                // ShrinkError: code translated by the admin; other exception
                // → raw message, without a code.
                $results[$imagePath] = ['ok' => false, 'error' => $e->getMessage()]
                    + ($e instanceof ShrinkError ? ['code' => $e->reason] : []);
            }
            if ($progress !== null) {
                $progress($imagePath, $results[$imagePath]);
            }
        }

        $indexer = Indexer::fromConfig($db);
        foreach ($touched as $page) {
            $indexer->reindexPage($page);
        }
        foreach ($results as $imagePath => $result) {
            if ($result['ok']) {
                $results[$imagePath]['thumb'] = ($db->findImage($imagePath)['thumb_path'] ?? null) !== null;
            }
        }

        return $results;
    }

    /**
     * Images left without a thumbnail (the gallery then serves the
     * original). Since Grav 2.1.7 the core refuses to resize a source beyond
     * `system.images.max_pixels`: the failure is silent, hence this report.
     *
     * @return array{count:int, over_limit:int, max_pixels:int, items:array}
     */
    private static function missingThumbs(IndexDatabase $db, int $limit = 20): array
    {
        $maxPixels = (int) Grav::instance()['config']->get('system.images.max_pixels', 25000000);

        $items = [];
        foreach ($db->missingThumbs($limit) as $row) {
            $row['over_limit'] = $maxPixels > 0 && $row['width'] * $row['height'] > $maxPixels;
            if ($row['over_limit']) {
                // Dimensions the image would have once shrunk (shown in the admin).
                [$row['target_width'], $row['target_height']] =
                    ImageShrinker::targetSize($row['width'], $row['height'], $maxPixels);
            }
            $items[] = $row;
        }

        return [
            'count'      => $db->countMissingThumbs(),
            'over_limit' => $maxPixels > 0 ? $db->countMissingThumbs($maxPixels) : 0,
            'max_pixels' => $maxPixels,
            'items'      => $items,
        ];
    }

    /**
     * Pages ready to be iterated, in the CLI as well as in an API/admin
     * context (where page loading is disabled by default).
     */
    private static function pages()
    {
        $pages = Grav::instance()['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }
        $pages->init();
        return $pages;
    }
}
