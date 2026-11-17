<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

/**
 * Builds the JSON response of the gallery's public feed from the index.
 *
 * Read-only, outside the API auth stack (spec §6): pure transformation of
 * SQLite rows → payload, with opaque cursor pagination.
 */
class Feed
{
    private IndexDatabase $db;
    private string $baseUrl;
    private int $maxLimit;

    /** `random` order: 'shared' (precomputed shuffle) | 'live' (one per visit). */
    private string $randomMode;

    public function __construct(IndexDatabase $db, string $baseUrl, int $maxLimit = 200, string $randomMode = 'shared')
    {
        $this->db = $db;
        // Relative base_url (e.g. "/galinf"), prefixed to stored paths.
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->maxLimit = $maxLimit;
        $this->randomMode = $randomMode === 'live' ? 'live' : 'shared';
    }

    /**
     * @param string      $sort   asc | desc | random (aliases: recent → desc,
     *                            oldest → asc, for existing shortcodes)
     * @param string|null $cursor opaque cursor received from the client
     * @param int         $limit  requested batch size
     * @param int|null    $seed   seed (random sort); generated if absent
     * @param string|null $route  if provided: images of THAT article (article page)
     * @param string      $field  criterion: date (publication) | folder (manual
     *                            page order in the admin)
     * @return array{items: array[], next_cursor: ?string, sort: string}
     */
    public function build(string $sort, ?string $cursor, int $limit, ?int $seed = null, ?string $route = null, string $field = 'date'): array
    {
        $limit = max(1, min($limit, $this->maxLimit));

        if ($route !== null && $route !== '') {
            return $this->buildArticle($route, $cursor, $limit);
        }

        $sort = self::normalizeSort($sort);
        if ($sort === 'random') {
            return $this->buildRandom($cursor, $limit, $seed);
        }
        if ($sort === 'commented') {
            return $this->buildCommented($cursor, $limit);
        }

        $desc  = $sort === 'desc';
        $field = self::normalizeField($field);

        if ($field === 'folder') {
            [$curKey, $curId] = $this->decodeCursorText($cursor);
            $rows = $this->db->fetchFeedByFolder($desc, $curKey, $curId, $limit);
        } else {
            [$curPub, $curId] = $this->decodeCursor($cursor);
            $rows = $this->db->fetchFeed($desc ? 'recent' : 'oldest', $curPub, $curId, $limit);
        }

        // limit+1 was requested: the extra row signals that a next batch exists.
        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $items = array_map(fn(array $r) => $this->mapItem($r), $rows);

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[\count($rows) - 1];
            $nextCursor = $field === 'folder'
                ? $this->encodeCursorText((string) $last['folder_sort'], (int) $last['id'])
                : $this->encodeCursor((int) $last['published_at'], (int) $last['id']);
        }

        return [
            'items'       => $items,
            'next_cursor' => $nextCursor,
            'sort'        => $sort,
            'field'       => $field,
        ] + $this->total($cursor, fn() => $this->db->countImages());
    }

    /**
     * Canonical direction: asc | desc | random.
     * The old recent/oldest values (shortcodes, existing configs) are still
     * accepted as aliases.
     */
    public static function normalizeSort(string $sort): string
    {
        return match (strtolower(trim($sort))) {
            'asc', 'oldest'  => 'asc',
            'random'         => 'random',
            'commented'      => 'commented',   // commented images, most recent first
            default          => 'desc',        // 'desc', 'recent', unknown value
        };
    }

    /**
     * "Commented" sort: images with ≥ 1 approved comment, most recently
     * commented first. Cursor (last_comment_at, id) — int:int format.
     *
     * @return array{items: array[], next_cursor: ?string, sort: string}
     */
    private function buildCommented(?string $cursor, int $limit): array
    {
        [$curLast, $curId] = $this->decodeCursor($cursor);

        $rows = $this->db->fetchFeedCommented($curLast, $curId, $limit);

        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $items = array_map(fn(array $r) => $this->mapItem($r), $rows);

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[\count($rows) - 1];
            $nextCursor = $this->encodeCursor((int) $last['last_comment_at'], (int) $last['id']);
        }

        return [
            'items'       => $items,
            'next_cursor' => $nextCursor,
            'sort'        => 'commented',
        ] + $this->total($cursor, fn() => $this->db->countCommented());
    }

    /**
     * Canonical sort criterion: date | folder.
     */
    public static function normalizeField(string $field): string
    {
        return strtolower(trim($field)) === 'folder' ? 'folder' : 'date';
    }

    /**
     * Random sort with a stable seed. If no seed is provided, one is
     * generated and returned in the response: the client replays it on every
     * batch to keep a consistent order for the whole scroll.
     *
     * @return array{items: array[], next_cursor: ?string, sort: string, seed: int}
     */
    private function buildRandom(?string $cursor, int $limit, ?int $seed): array
    {
        if ($seed === null || $seed <= 0) {
            $seed = random_int(1, 2147483646);
        }
        if ($this->randomMode === 'shared') {
            return $this->buildShuffled($cursor, $limit, $seed);
        }

        // For random, the cursor encodes (hash, id) — same int:int format.
        [$curHash, $curId] = $this->decodeCursor($cursor);

        $rows = $this->db->fetchFeedRandom($seed, $curHash, $curId, $limit);

        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $items = array_map(fn(array $r) => $this->mapItem($r), $rows);

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[\count($rows) - 1];
            $nextCursor = $this->encodeCursor((int) $last['h'], (int) $last['id']);
        }

        return [
            'items'       => $items,
            'next_cursor' => $nextCursor,
            'sort'        => 'random',
            'seed'        => $seed,
        ] + $this->total($cursor, fn() => $this->db->countImages());
    }

    /**
     * Random order, SHARED mode: the precomputed shuffle, entered at the
     * point given by the seed (each visit has its own). Cursor
     * (rnd, id, lap) — see IndexDatabase::fetchFeedShuffled.
     *
     * @return array{items: array[], next_cursor: ?string, sort: string, seed: int}
     */
    private function buildShuffled(?string $cursor, int $limit, int $seed): array
    {
        [$curKey, $curId, $curLap] = [null, null, 0];
        $decoded = $cursor !== null && $cursor !== '' ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;
        if (\is_string($decoded) && preg_match('/^(\d{1,10}):(\d{1,19}):([01])$/', $decoded, $m)) {
            [$curKey, $curId, $curLap] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        }

        $rows = $this->db->fetchFeedShuffled($seed % 2147483647, $curKey, $curId, $curLap, $limit);

        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[\count($rows) - 1];
            $nextCursor = rtrim(strtr(base64_encode((int) $last['rnd'] . ':' . (int) $last['id'] . ':' . (int) $last['lap']), '+/', '-_'), '=');
        }

        return [
            'items'       => array_map(fn(array $r) => $this->mapItem($r), $rows),
            'next_cursor' => $nextCursor,
            'sort'        => 'random',
            'seed'        => $seed,
        ] + $this->total($cursor, fn() => $this->db->countImages());
    }

    /**
     * Images of a single article, upload order (sort_in_article), paginated.
     * Cursor = (sort_in_article, id). Used by the article page.
     *
     * @return array{items: array[], next_cursor: ?string, sort: string}
     */
    private function buildArticle(string $route, ?string $cursor, int $limit): array
    {
        [$curSort, $curId] = $this->decodeCursor($cursor);

        $rows = $this->db->fetchArticle($route, $curSort, $curId, $limit);

        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $items = array_map(fn(array $r) => $this->mapItem($r), $rows);

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[\count($rows) - 1];
            $nextCursor = $this->encodeCursor((int) $last['sort_in_article'], (int) $last['id']);
        }

        return [
            'items'       => $items,
            'next_cursor' => $nextCursor,
            'sort'        => 'article',
        ] + $this->total($cursor, fn() => $this->db->countByRoute($route));
    }

    /**
     * `total` (progress bar) on the FIRST batch only: counting means reading
     * every row of the index (~18 ms for 50,000 images on a Raspberry Pi),
     * and the browser keeps the first figure for the rest of its scroll.
     *
     * @return array{total?: int}
     */
    private function total(?string $cursor, callable $count): array
    {
        return $cursor === null || $cursor === '' ? ['total' => (int) $count()] : [];
    }

    /**
     * Index row → feed item (URLs prefixed with the base_url).
     */
    private function mapItem(array $r): array
    {
        $full  = $this->url($r['image_path']);
        $thumb = !empty($r['thumb_path']) ? $this->url($r['thumb_path']) : $full;

        return [
            'thumb_url'     => $thumb,
            'full_url'      => $full,
            'width'         => (int) $r['width'],
            'height'        => (int) $r['height'],
            'article_route' => $r['article_route'],
            'article_title' => $r['article_title'],
            'animated'      => !empty($r['animated']),
            'comments'      => (int) ($r['comments_count'] ?? 0),
        ];
    }

    private function url(string $path): string
    {
        $path = ltrim($path, '/');

        // Keeps a possible query string (inert in production, useful in tests).
        $query = '';
        $qpos = strpos($path, '?');
        if ($qpos !== false) {
            $query = substr($path, $qpos);
            $path  = substr($path, 0, $qpos);
        }

        // Encodes each segment (spaces, accents…) while keeping the '/'.
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return $this->baseUrl . '/' . $encoded . $query;
    }

    /**
     * Opaque cursor = base64url("pub:id").
     */
    private function encodeCursor(int $pub, int $id): string
    {
        return rtrim(strtr(base64_encode("{$pub}:{$id}"), '+/', '-_'), '=');
    }

    /**
     * @return array{0: ?int, 1: ?int} [published_at, id] or [null, null]
     */
    private function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [null, null];
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return [null, null];
        }

        [$pub, $id] = explode(':', $decoded, 2);
        if (!ctype_digit($pub) || !ctype_digit($id)) {
            return [null, null];
        }

        return [(int) $pub, (int) $id];
    }

    /**
     * Text-key cursor (folder sort) = base64url(rawurlencode(key) . ":id").
     * rawurlencode guarantees there is no ':' in the encoded key.
     */
    private function encodeCursorText(string $key, int $id): string
    {
        return rtrim(strtr(base64_encode(rawurlencode($key) . ':' . $id), '+/', '-_'), '=');
    }

    /**
     * @return array{0: ?string, 1: ?int} [key, id] or [null, null]
     */
    private function decodeCursorText(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [null, null];
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return [null, null];
        }

        [$key, $id] = explode(':', $decoded, 2);
        if (!ctype_digit($id)) {
            return [null, null];
        }

        return [rawurldecode($key), (int) $id];
    }
}
