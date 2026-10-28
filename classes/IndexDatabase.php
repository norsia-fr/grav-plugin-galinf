<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Grav;
use RuntimeException;
use SQLite3;

/**
 * Access layer to the gallery index's SQLite file.
 *
 * In charge of the schema (migration), per-article upserts/deletions, and
 * stats reads. No business logic here: see Indexer.
 */
class IndexDatabase
{
    private string $path;
    private ?SQLite3 $db = null;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Database designated by the plugin configuration (`db_path`, relative to
     * Grav's root). Single entry point: plugin, service, API and CLI.
     */
    public static function fromConfig(): self
    {
        $root = \defined('GRAV_ROOT') ? GRAV_ROOT : getcwd();
        $path = (string) Grav::instance()['config']->get('plugins.galinf.db_path', 'user/data/gallery-index/gallery.sqlite');

        return new self($root . '/' . ltrim($path, '/'));
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Opens (and creates if needed) the database, then ensures the schema.
     */
    public function connect(): SQLite3
    {
        if ($this->db instanceof SQLite3) {
            return $this->db;
        }

        if (!class_exists('SQLite3')) {
            throw new RuntimeException(
                "Galinf: the \"sqlite3\" PHP extension is required but not enabled. "
                . "Enable it for the CLI AND the web (e.g. Debian/Ubuntu: \"apt install phpX.Y-sqlite3\" "
                . "then restart php-fpm; shared hosting: tick \"sqlite3\" in the PHP version selector)."
            );
        }

        $dir = \dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create the index folder: {$dir}");
        }

        $this->db = new SQLite3($this->path);
        $this->db->busyTimeout(5000);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');

        // Deterministic random order for the `random` sort: stable hash of
        // (id, seed). Same seed → same permutation for the whole scroll
        // session (avoids the duplicates/gaps of a naive ORDER BY RANDOM()).
        $this->db->createFunction('gali_rand', static fn($id, $seed): int => crc32($id . ':' . $seed), 2);

        $this->migrate();

        return $this->db;
    }

    /**
     * Creates the tables and indexes if they do not exist (idempotent).
     */
    public function migrate(): void
    {
        $db = $this->db ?? $this->connect();

        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS images (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                image_path      TEXT    NOT NULL,
                thumb_path      TEXT,
                width           INTEGER NOT NULL,
                height          INTEGER NOT NULL,
                article_route   TEXT    NOT NULL,
                article_title   TEXT,
                published_at    INTEGER NOT NULL,
                sort_in_article INTEGER DEFAULT 0,
                animated        INTEGER DEFAULT 0,
                folder_sort     TEXT    NOT NULL DEFAULT '',
                UNIQUE(image_path)
            );
        SQL);

        // Soft migrations for a database created before these columns were
        // added.
        $this->addColumnIfMissing('images', 'thumb_path', 'TEXT');
        $this->addColumnIfMissing('images', 'animated', 'INTEGER DEFAULT 0');
        // "Admin manual order" sort key (normalized folder prefixes, see
        // Indexer::folderSortKey). Empty until the article has been reindexed
        // with a version >= 1.6.0.
        $this->addColumnIfMissing('images', 'folder_sort', "TEXT NOT NULL DEFAULT ''");
        // Comment cache (source of truth: the galinf-comments Flex
        // directory): number of APPROVED comments + date of the last comment
        // (even pending, for the moderation round).
        $this->addColumnIfMissing('images', 'comments_count', 'INTEGER DEFAULT 0');
        $this->addColumnIfMissing('images', 'last_comment_at', 'INTEGER DEFAULT 0');
        // Per-image hiding, decided by a tag in the `.meta.yaml` (see
        // Indexer::hiddenFlags). Resolved at INDEXING time, never on the fly:
        // the feed only reads the index. Default 0 = visible, so rows indexed
        // before these columns were added stay visible.
        $this->addColumnIfMissing('images', 'hide_article', 'INTEGER DEFAULT 0');
        $this->addColumnIfMissing('images', 'hide_global', 'INTEGER DEFAULT 0');
        // Scheduled publishing (the article's start and end dates), NULL = no
        // date. Filtered on EVERY request by live().
        $this->addColumnIfMissing('images', 'publish_at', 'INTEGER');
        $this->addColumnIfMissing('images', 'unpublish_at', 'INTEGER');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_published_at  ON images(published_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_article_route ON images(article_route)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_folder_sort   ON images(folder_sort, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_last_comment  ON images(last_comment_at, id)');

        // Anti-burst safeguard for comment writes (per hashed IP).
        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS comment_rate (
                ip_hash TEXT    NOT NULL,
                ts      INTEGER NOT NULL
            );
        SQL);

        // Comments posted from the site, per day (`comments_daily_max`
        // option): one row per day, only today's is read.
        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS comment_daily (
                day TEXT    PRIMARY KEY,
                n   INTEGER NOT NULL
            );
        SQL);

        // Captcha tokens already presented: a token is only used ONCE, right
        // or wrong answer (no replay, no trying every sum).
        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS captcha_used (
                sig TEXT    PRIMARY KEY,
                exp INTEGER NOT NULL
            );
        SQL);

        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS meta (
                key   TEXT PRIMARY KEY,
                value TEXT
            );
        SQL);
    }

    /**
     * Adds a column if it does not exist yet (idempotent migration).
     */
    private function addColumnIfMissing(string $table, string $column, string $type): void
    {
        $db = $this->db ?? $this->connect();
        $res = $db->query("PRAGMA table_info({$table})");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === $column) {
                return;
            }
        }
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
    }

    /**
     * Empties the whole index ("Rebuild from scratch", `reindex --clear`):
     * the images table is recreated from scratch. The `meta` table is kept,
     * except the last indexing date: it also holds the log of last runs shown
     * in the admin.
     */
    public function reset(): void
    {
        $db = $this->connect();
        $db->exec('DROP TABLE IF EXISTS images');
        $db->exec("DELETE FROM meta WHERE key = 'last_indexed_at'");
        $this->migrate();
    }

    /**
     * Replaces all of an article's rows with the given set, in one
     * transaction. Handles creation / modification / removal of images
     * symmetrically: delete, then reinsert.
     *
     * @param string  $route  Grav route of the article
     * @param array[] $rows   Ready rows: image_path, width, height,
     *                        article_title, published_at, sort_in_article
     */
    public function replaceArticle(string $route, array $rows): void
    {
        $db = $this->connect();
        $db->exec('BEGIN');

        try {
            $del = $db->prepare('DELETE FROM images WHERE article_route = :route');
            $del->bindValue(':route', $route, SQLITE3_TEXT);
            $del->execute();

            $ins = $db->prepare(<<<'SQL'
                INSERT INTO images
                    (image_path, thumb_path, width, height, article_route, article_title, published_at, sort_in_article, animated, folder_sort, comments_count, last_comment_at, hide_article, hide_global, publish_at, unpublish_at)
                VALUES
                    (:image_path, :thumb_path, :width, :height, :route, :title, :published_at, :sort, :animated, :folder_sort, :ccount, :clast, :hide_article, :hide_global, :publish_at, :unpublish_at)
            SQL);

            foreach ($rows as $row) {
                $ins->bindValue(':image_path', $row['image_path'], SQLITE3_TEXT);
                $ins->bindValue(':thumb_path', $row['thumb_path'] ?? null, $row['thumb_path'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
                $ins->bindValue(':width', $row['width'], SQLITE3_INTEGER);
                $ins->bindValue(':height', $row['height'], SQLITE3_INTEGER);
                $ins->bindValue(':route', $route, SQLITE3_TEXT);
                $ins->bindValue(':title', $row['article_title'], SQLITE3_TEXT);
                $ins->bindValue(':published_at', $row['published_at'], SQLITE3_INTEGER);
                $ins->bindValue(':sort', $row['sort_in_article'], SQLITE3_INTEGER);
                $ins->bindValue(':animated', !empty($row['animated']) ? 1 : 0, SQLITE3_INTEGER);
                $ins->bindValue(':folder_sort', (string) ($row['folder_sort'] ?? ''), SQLITE3_TEXT);
                $ins->bindValue(':ccount', (int) ($row['comments_count'] ?? 0), SQLITE3_INTEGER);
                $ins->bindValue(':clast', (int) ($row['last_comment_at'] ?? 0), SQLITE3_INTEGER);
                $ins->bindValue(':hide_article', !empty($row['hide_article']) ? 1 : 0, SQLITE3_INTEGER);
                $ins->bindValue(':hide_global', !empty($row['hide_global']) ? 1 : 0, SQLITE3_INTEGER);
                foreach (['publish_at', 'unpublish_at'] as $col) {
                    $v = $row[$col] ?? null;
                    $ins->bindValue(':' . $col, $v, $v === null ? SQLITE3_NULL : SQLITE3_INTEGER);
                }
                $ins->execute();
                $ins->reset();
            }

            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }

        $this->touch();
    }

    /**
     * Deletes all of an article's rows (page deletion).
     */
    public function deleteArticle(string $route): void
    {
        $db = $this->connect();
        $stmt = $db->prepare('DELETE FROM images WHERE article_route = :route');
        $stmt->bindValue(':route', $route, SQLITE3_TEXT);
        $stmt->execute();
        $this->touch();
    }

    /**
     * Routes of every article in the index (cleanup of vanished articles at
     * the end of a reindex).
     *
     * @return string[]
     */
    public function articleRoutes(): array
    {
        $db = $this->connect();
        $res = $db->query('SELECT DISTINCT article_route FROM images');

        $routes = [];
        while ($row = $res->fetchArray(SQLITE3_NUM)) {
            $routes[] = (string) $row[0];
        }
        return $routes;
    }

    /**
     * Deletes the rows of an article AND of all its descendant pages (parent
     * page deleted, moved or now restricted: see Indexer::forgetSubtree,
     * which also removes the thumbnails).
     */
    public function deleteSubtree(string $route): void
    {
        $db = $this->connect();
        $stmt = $db->prepare('DELETE FROM images WHERE ' . self::SUBTREE);
        self::bindSubtree($stmt, $route);
        $stmt->execute();
        $this->touch();
    }

    /**
     * SQL condition "this route or one of its descendants". Exact prefix
     * comparison (not LIKE, which is case-insensitive in SQLite: `/Blog`
     * would take `/blog` with it). substr() counts characters, hence
     * mb_strlen() for a route with accents.
     */
    private const SUBTREE = '(article_route = :route OR substr(article_route, 1, :plen) = :prefix)';

    private static function bindSubtree(\SQLite3Stmt $stmt, string $route): void
    {
        $prefix = rtrim($route, '/') . '/';
        $stmt->bindValue(':route', $route, SQLITE3_TEXT);
        $stmt->bindValue(':prefix', $prefix, SQLITE3_TEXT);
        $stmt->bindValue(':plen', mb_strlen($prefix, 'UTF-8'), SQLITE3_INTEGER);
    }

    /**
     * Reads a batch of images for the public feed, sorted and paginated by
     * cursor.
     *
     * Cursor based on (published_at, id): stable total order even if images
     * are added/removed while scrolling (no skip/duplicate). $limit + 1 rows
     * are read to know whether another batch follows.
     *
     * @param string   $sort   'recent' (published_at DESC) | 'oldest' (ASC)
     * @param int|null $curPub published_at of the last item received (cursor)
     * @param int|null $curId  id of the last item received (cursor)
     * @param int      $limit  requested batch size
     * @return array[] Raw rows (one more if a next batch exists)
     */
    public function fetchFeed(string $sort, ?int $curPub, ?int $curId, int $limit): array
    {
        $db = $this->connect();

        $desc  = $sort !== 'oldest';        // 'recent' by default
        $order = $desc ? 'DESC' : 'ASC';
        $cmp   = $desc ? '<' : '>';

        // Hidden from the global gallery (.meta.yaml tag): never served.
        $where = 'WHERE hide_global = 0 AND ' . $this->live();
        if ($curPub !== null && $curId !== null) {
            // Lexicographic comparison on (published_at, id).
            $where .= " AND ((published_at {$cmp} :cp) OR (published_at = :cp AND id {$cmp} :ci))";
        }

        $sql = "SELECT id, image_path, thumb_path, animated, width, height, article_route, article_title, published_at, comments_count
                FROM images
                {$where}
                ORDER BY published_at {$order}, id {$order}
                LIMIT :lim";

        $stmt = $db->prepare($sql);
        if ($curPub !== null && $curId !== null) {
            $stmt->bindValue(':cp', $curPub, SQLITE3_INTEGER);
            $stmt->bindValue(':ci', $curId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':lim', $limit + 1, SQLITE3_INTEGER);

        $res  = $stmt->execute();
        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Batch for the feed sorted by `folder_sort` (manual page order in the
     * admin), paginated by cursor (folder_sort, id) — same keyset mechanism
     * as fetchFeed, with a TEXT key.
     *
     * Rows indexed before v1.6.0 have folder_sort = '': they come grouped at
     * the start (ASC) / at the end (DESC) until a reindex has filled the
     * column.
     *
     * @param bool        $desc   direction
     * @param string|null $curKey folder_sort of the last item received (cursor)
     * @param int|null    $curId  id of the last item received (cursor)
     * @param int         $limit  requested batch size
     * @return array[] Raw rows (one more if a next batch exists)
     */
    public function fetchFeedByFolder(bool $desc, ?string $curKey, ?int $curId, int $limit): array
    {
        $db = $this->connect();

        $order = $desc ? 'DESC' : 'ASC';
        $cmp   = $desc ? '<' : '>';

        $where = 'WHERE hide_global = 0 AND ' . $this->live();
        if ($curKey !== null && $curId !== null) {
            $where .= " AND ((folder_sort {$cmp} :ck) OR (folder_sort = :ck AND id {$cmp} :ci))";
        }

        $sql = "SELECT id, image_path, thumb_path, animated, width, height,
                       article_route, article_title, published_at, folder_sort, comments_count
                FROM images
                {$where}
                ORDER BY folder_sort {$order}, id {$order}
                LIMIT :lim";

        $stmt = $db->prepare($sql);
        if ($curKey !== null && $curId !== null) {
            $stmt->bindValue(':ck', $curKey, SQLITE3_TEXT);
            $stmt->bindValue(':ci', $curId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':lim', $limit + 1, SQLITE3_INTEGER);

        $res  = $stmt->execute();
        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Batch for the `random` sort, paginated by cursor (hash, id).
     *
     * The order is `gali_rand(id, seed)`: a stable permutation as long as the
     * same seed is passed. The cursor encodes (hash, id) of the last item
     * received.
     *
     * @param int      $seed    seed set on the client side for the whole session
     * @param int|null $curHash hash of the last item received (cursor)
     * @param int|null $curId   id of the last item received (cursor)
     * @param int      $limit   requested batch size
     * @return array[] Rows (with column `h` = hash); one more if a next batch exists.
     */
    public function fetchFeedRandom(int $seed, ?int $curHash, ?int $curId, int $limit): array
    {
        $db = $this->connect();

        $where = '';
        if ($curHash !== null && $curId !== null) {
            $where = 'WHERE (h > :ch) OR (h = :ch AND id > :ci)';
        }

        $sql = "SELECT * FROM (
                    SELECT id, image_path, thumb_path, animated, width, height,
                           article_route, article_title, published_at, comments_count,
                           gali_rand(id, :seed) AS h
                    FROM images
                    WHERE hide_global = 0 AND {$this->live()}
                )
                {$where}
                ORDER BY h ASC, id ASC
                LIMIT :lim";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':seed', $seed, SQLITE3_INTEGER);
        if ($where !== '') {
            $stmt->bindValue(':ch', $curHash, SQLITE3_INTEGER);
            $stmt->bindValue(':ci', $curId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':lim', $limit + 1, SQLITE3_INTEGER);

        $res  = $stmt->execute();
        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Batch of ONE article's images (article page), in upload order
     * (`sort_in_article`), paginated by cursor (sort_in_article, id).
     *
     * @param string   $route    Grav route of the article (exact filter)
     * @param int|null $curSort  sort_in_article of the last item received
     * @param int|null $curId    id of the last item received
     * @param int      $limit    requested batch size
     * @return array[] Rows; one more if a next batch exists.
     */
    public function fetchArticle(string $route, ?int $curSort, ?int $curId, int $limit): array
    {
        $db = $this->connect();

        // hide_article: hidden from ITS article's gallery (.meta.yaml tag).
        $where = 'WHERE article_route = :route AND hide_article = 0 AND ' . $this->live();
        if ($curSort !== null && $curId !== null) {
            $where .= ' AND ((sort_in_article > :cs) OR (sort_in_article = :cs AND id > :ci))';
        }

        $sql = "SELECT id, image_path, thumb_path, animated, width, height,
                       article_route, article_title, sort_in_article, comments_count
                FROM images
                {$where}
                ORDER BY sort_in_article ASC, id ASC
                LIMIT :lim";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':route', $route, SQLITE3_TEXT);
        if ($curSort !== null && $curId !== null) {
            $stmt->bindValue(':cs', $curSort, SQLITE3_INTEGER);
            $stmt->bindValue(':ci', $curId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':lim', $limit + 1, SQLITE3_INTEGER);

        $res  = $stmt->execute();
        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Batch for the "Commented" sort: images with ≥ 1 approved comment, most
     * recently commented first. Keyset cursor (last_comment_at, id).
     *
     * @return array[] Rows; one more if a next batch exists.
     */
    public function fetchFeedCommented(?int $curLast, ?int $curId, int $limit): array
    {
        $db = $this->connect();

        $where = 'WHERE comments_count > 0 AND hide_global = 0 AND ' . $this->live();
        if ($curLast !== null && $curId !== null) {
            $where .= ' AND ((last_comment_at < :cl) OR (last_comment_at = :cl AND id < :ci))';
        }

        $sql = "SELECT id, image_path, thumb_path, animated, width, height,
                       article_route, article_title, published_at, last_comment_at, comments_count
                FROM images
                {$where}
                ORDER BY last_comment_at DESC, id DESC
                LIMIT :lim";

        $stmt = $db->prepare($sql);
        if ($curLast !== null && $curId !== null) {
            $stmt->bindValue(':cl', $curLast, SQLITE3_INTEGER);
            $stmt->bindValue(':ci', $curId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':lim', $limit + 1, SQLITE3_INTEGER);

        $res  = $stmt->execute();
        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Number of images with at least one approved comment ("Commented" sort
     * total).
     */
    public function countCommented(): int
    {
        $db = $this->connect();
        return (int) $db->querySingle('SELECT COUNT(*) FROM images WHERE comments_count > 0 AND hide_global = 0 AND ' . $this->live());
    }

    /**
     * Updates an image's comment cache (recomputed from the galinf-comments
     * Flex directory, which is the source of truth).
     */
    public function updateCommentStats(string $imagePath, int $approvedCount, int $lastCommentAt): void
    {
        $db = $this->connect();
        $stmt = $db->prepare(
            'UPDATE images SET comments_count = :c, last_comment_at = :l WHERE image_path = :p'
        );
        $stmt->bindValue(':c', $approvedCount, SQLITE3_INTEGER);
        $stmt->bindValue(':l', $lastCommentAt, SQLITE3_INTEGER);
        $stmt->bindValue(':p', $imagePath, SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * Write safeguard: at most $max writes per (hashed) IP over the $window
     * (s). Returns false if the limit is reached, otherwise records the write
     * and returns true.
     */
    public function rateLimitAllow(string $ipHash, int $max = 5, int $window = 600): bool
    {
        $db  = $this->connect();
        $now = time();

        $db->exec('DELETE FROM comment_rate WHERE ts < ' . ($now - $window));

        $stmt = $db->prepare('SELECT COUNT(*) AS n FROM comment_rate WHERE ip_hash = :h');
        $stmt->bindValue(':h', $ipHash, SQLITE3_TEXT);
        $n = (int) ($stmt->execute()->fetchArray(SQLITE3_ASSOC)['n'] ?? 0);
        if ($n >= $max) {
            return false;
        }

        $ins = $db->prepare('INSERT INTO comment_rate (ip_hash, ts) VALUES (:h, :t)');
        $ins->bindValue(':h', $ipHash, SQLITE3_TEXT);
        $ins->bindValue(':t', $now, SQLITE3_INTEGER);
        $ins->execute();

        return true;
    }

    /**
     * Comments posted from the site today (server's date).
     */
    public function commentsToday(): int
    {
        $stmt = $this->connect()->prepare('SELECT n FROM comment_daily WHERE day = :d');
        $stmt->bindValue(':d', date('Y-m-d'), SQLITE3_TEXT);
        return (int) ($stmt->execute()->fetchArray(SQLITE3_ASSOC)['n'] ?? 0);
    }

    /**
     * Counts one more comment for today; the previous days are dropped.
     */
    public function countCommentToday(): void
    {
        $db  = $this->connect();
        $day = date('Y-m-d');

        $del = $db->prepare('DELETE FROM comment_daily WHERE day <> :d');
        $del->bindValue(':d', $day, SQLITE3_TEXT);
        $del->execute();

        $ins = $db->prepare('INSERT INTO comment_daily (day, n) VALUES (:d, 1)
                             ON CONFLICT(day) DO UPDATE SET n = n + 1');
        $ins->bindValue(':d', $day, SQLITE3_TEXT);
        $ins->execute();
    }

    /**
     * Thumbnails of an article (and of its descendants if $descendants),
     * without their ?v= token (paths relative to the site root).
     *
     * @return string[]
     */
    public function thumbPathsForRoute(string $route, bool $descendants = false): array
    {
        $db = $this->connect();
        if ($descendants) {
            $stmt = $db->prepare('SELECT thumb_path FROM images WHERE ' . self::SUBTREE . ' AND thumb_path IS NOT NULL');
            self::bindSubtree($stmt, $route);
        } else {
            $stmt = $db->prepare('SELECT thumb_path FROM images WHERE article_route = :route AND thumb_path IS NOT NULL');
            $stmt->bindValue(':route', $route, SQLITE3_TEXT);
        }
        $res = $stmt->execute();

        $paths = [];
        while ($row = $res->fetchArray(SQLITE3_NUM)) {
            $paths[] = (string) preg_replace('/\?.*$/', '', (string) $row[0]);
        }
        return $paths;
    }

    /**
     * Marks a captcha token as consumed. Returns false if it already was
     * (replay). Inserting on the primary key makes the operation atomic: two
     * simultaneous requests with the same token only let one through. Expired
     * rows are purged along the way (a token lives 15 minutes).
     */
    public function captchaConsume(string $sig, int $exp): bool
    {
        $db = $this->connect();
        $db->exec('DELETE FROM captcha_used WHERE exp < ' . time());

        $stmt = $db->prepare('INSERT OR IGNORE INTO captcha_used (sig, exp) VALUES (:sig, :exp)');
        $stmt->bindValue(':sig', $sig, SQLITE3_TEXT);
        $stmt->bindValue(':exp', $exp, SQLITE3_INTEGER);
        $stmt->execute();

        return $db->changes() === 1;
    }

    /**
     * Set of thumb_path values referenced by the index (key = path, for an
     * O(1) membership test). Used to purge orphan thumbnails.
     *
     * @return array<string,true>
     */
    public function allThumbPaths(): array
    {
        $db  = $this->connect();
        $set = [];
        $res = $db->query('SELECT DISTINCT thumb_path FROM images WHERE thumb_path IS NOT NULL');
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            // Stored paths carry a version token (`?v=<mtime>`, see
            // Indexer::thumbUrl): FILES on disk are compared here, so it must
            // be stripped — otherwise NO thumbnail would be recognized and the
            // purge would delete them all.
            $path = (string) $row['thumb_path'];
            $q = strpos($path, '?');
            $set[$q !== false ? substr($path, 0, $q) : $path] = true;
        }
        return $set;
    }

    /**
     * Number of images in an article (for the article page's progress).
     */
    public function countByRoute(string $route): int
    {
        $db = $this->connect();
        $stmt = $db->prepare('SELECT COUNT(*) AS n FROM images WHERE article_route = :route AND hide_article = 0 AND ' . $this->live());
        $stmt->bindValue(':route', $route, SQLITE3_TEXT);
        $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return (int) ($res['n'] ?? 0);
    }

    /**
     * Number of images in the GLOBAL gallery (feed total → progress bar):
     * images hidden by tag are excluded, otherwise the progress would never
     * reach 100%.
     */
    public function countImages(): int
    {
        $db = $this->connect();
        return (int) $db->querySingle('SELECT COUNT(*) FROM images WHERE hide_global = 0 AND ' . $this->live());
    }

    /**
     * Total number of indexed rows, hidden ones included (admin and CLI
     * statistics: "N images indexed" must reflect the real index).
     */
    public function countAllImages(): int
    {
        $db = $this->connect();
        return (int) $db->querySingle('SELECT COUNT(*) FROM images');
    }

    /**
     * Non-animated images left WITHOUT a thumbnail: the gallery then serves
     * the original instead (heavy). Main cause: a source beyond
     * `system.images.max_pixels`, which the core refuses to resize.
     */
    public function countMissingThumbs(int $maxPixels = 0): int
    {
        $db = $this->connect();
        $sql = 'SELECT COUNT(*) FROM images WHERE thumb_path IS NULL AND animated = 0';
        if ($maxPixels > 0) {
            $sql .= ' AND width * height > ' . $maxPixels;
        }
        return (int) $db->querySingle($sql);
    }

    /**
     * @return array<int, array{image_path:string, article_route:string, width:int, height:int}>
     */
    public function missingThumbs(int $limit = 20): array
    {
        $db = $this->connect();
        $stmt = $db->prepare(
            'SELECT image_path, article_route, width, height FROM images
             WHERE thumb_path IS NULL AND animated = 0
             ORDER BY width * height DESC, id ASC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), SQLITE3_INTEGER);
        $res = $stmt->execute();

        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = [
                'image_path'    => (string) $row['image_path'],
                'article_route' => (string) $row['article_route'],
                'width'         => (int) $row['width'],
                'height'        => (int) $row['height'],
            ];
        }
        return $rows;
    }

    /**
     * @return array{image_path:string, article_route:string, width:int, height:int, animated:int, thumb_path:?string}|null
     */
    public function findImage(string $imagePath): ?array
    {
        $db = $this->connect();
        $stmt = $db->prepare(
            'SELECT image_path, article_route, width, height, animated, thumb_path
             FROM images WHERE image_path = :path LIMIT 1'
        );
        $stmt->bindValue(':path', $imagePath, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        return $row ?: null;
    }

    public function getMeta(string $key): ?string
    {
        $db = $this->connect();
        $stmt = $db->prepare('SELECT value FROM meta WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_NUM);

        return $row ? (string) $row[0] : null;
    }

    /**
     * @return array<string, string> metas whose key starts with $prefix
     */
    public function metaByPrefix(string $prefix): array
    {
        $db = $this->connect();
        $stmt = $db->prepare("SELECT key, value FROM meta WHERE key LIKE :prefix ESCAPE '\\'");
        $stmt->bindValue(':prefix', addcslashes($prefix, '%_\\') . '%', SQLITE3_TEXT);
        $res = $stmt->execute();

        $out = [];
        while ($row = $res->fetchArray(SQLITE3_NUM)) {
            $out[(string) $row[0]] = (string) $row[1];
        }
        return $out;
    }

    public function deleteMeta(string $key): void
    {
        $db = $this->connect();
        $stmt = $db->prepare('DELETE FROM meta WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function setMeta(string $key, string $value): void
    {
        $db = $this->connect();
        $stmt = $db->prepare(
            'INSERT INTO meta (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = :value'
        );
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function lastIndexedAt(): ?int
    {
        $db = $this->connect();
        $val = $db->querySingle("SELECT value FROM meta WHERE key = 'last_indexed_at'");
        return $val !== null ? (int) $val : null;
    }

    /**
     * Updates the last indexing timestamp.
     */
    private function touch(): void
    {
        $db = $this->connect();
        $stmt = $db->prepare(
            "INSERT INTO meta (key, value) VALUES ('last_indexed_at', :ts)
             ON CONFLICT(key) DO UPDATE SET value = :ts"
        );
        $stmt->bindValue(':ts', (string) time(), SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * SQL condition "the article is published right now": publishing start
     * in the past (or absent) AND publishing end in the future (or absent).
     * Evaluated on every request: a scheduled article appears and disappears
     * to the second, with no reindex or scheduled task. The timestamp is an
     * integer computed here, never request data.
     */
    private function live(): string
    {
        $now = time();

        return "(publish_at IS NULL OR publish_at <= {$now}) AND (unpublish_at IS NULL OR unpublish_at > {$now})";
    }

    public function close(): void
    {
        $this->db?->close();
        $this->db = null;
    }
}
