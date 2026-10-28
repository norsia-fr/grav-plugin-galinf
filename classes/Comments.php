<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Grav;
use Grav\Framework\Flex\FlexDirectory;

/**
 * Image comments — stored in the `galinf-comments` Flex directory (one
 * object = ONE comment = a small YAML file under user-data://, never
 * rewritten when more are added). The admin moderates them in Admin Next
 * (dedicated Flex list); the plugin's SQLite index only keeps a cache of
 * them (approved comments_count, last_comment_at) for the badge and the
 * "Commented" sort, resynced through Flex events.
 *
 * Every "hot" read (an article's comments, counters) goes through the Flex
 * index ALONE — the route/file/approved/date fields are copied into it by
 * CommentIndex — without loading the objects, except the few comments
 * actually displayed.
 *
 * A comment: { route, file, name, text, date (ISO 8601), approved }.
 */
class Comments
{
    /** Storage cap (also the admin editor's): visitors get maxLength(). */
    public const MAX_TEXT = 2000;
    public const MAX_NAME = 60;
    public const FLEX_TYPE = 'galinf-comments';

    /** Visitor length limit, `comments_max_length` option (50 to 1000). */
    public static function maxLength(): int
    {
        $max = (int) Grav::instance()['config']->get('plugins.galinf.comments_max_length', 300);
        return max(50, min(1000, $max));
    }

    /**
     * Comments the site accepts per day (`comments_daily_max` option, 1 to
     * 10000); 0 = unlimited (`comments_daily_unlimited`).
     */
    public static function dailyMax(): int
    {
        $config = Grav::instance()['config'];
        if ($config->get('plugins.galinf.comments_daily_unlimited', false)) {
            return 0;
        }
        return max(1, min(10000, (int) $config->get('plugins.galinf.comments_daily_max', 20)));
    }

    /**
     * The Flex directory, or null if the flex-objects plugin is
     * missing/inactive.
     */
    public static function directory(): ?FlexDirectory
    {
        try {
            $flex = Grav::instance()['flex'] ?? null;

            return $flex ? $flex->getDirectory(self::FLEX_TYPE) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Flex index entries: key => {route, file, approved, date, …}.
     * An array already in memory (index file, cached by Flex) — no object
     * loaded.
     *
     * @return array<string,array>
     */
    private static function entries(): array
    {
        $dir = self::directory();
        if (!$dir) {
            return [];
        }
        try {
            return (array) $dir->getIndex()->getIndexMap(null);
        } catch (\Throwable $e) {
            Grav::instance()['log']->warning('galinf: lecture de l\'index des commentaires impossible — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * An article's comments, grouped by image file, sorted by ascending date.
     * Only the selected objects are loaded from disk.
     *
     * @return array<string,array[]> [file => [{id,name,text,date,approved}, …]]
     */
    public static function forRoute(string $route, bool $approvedOnly = true): array
    {
        $dir = self::directory();
        if (!$dir) {
            return [];
        }

        $out = [];
        foreach (self::entries() as $key => $entry) {
            if (($entry['route'] ?? null) !== $route) {
                continue;
            }
            if ($approvedOnly && empty($entry['approved'])) {
                continue;
            }
            $object = $dir->getObject((string) $key);
            if (null === $object) {
                continue;
            }
            $file = (string) $object->getProperty('file');
            $out[$file][] = [
                'id'       => (string) $key,
                'name'     => (string) ($object->getProperty('name') ?: 'Anon'),
                'text'     => (string) $object->getProperty('text'),
                'date'     => (string) $object->getProperty('date'),
                'approved' => !empty($object->getProperty('approved')),
            ];
        }

        foreach ($out as &$list) {
            usort($list, static fn(array $a, array $b) => strtotime($a['date']) <=> strtotime($b['date']));
        }

        return $out;
    }

    /**
     * Creates a comment. Lengths are bounded here no matter what (defense in
     * depth — the endpoint already validates).
     *
     * @return array The comment created.
     */
    public static function add(string $route, string $file, string $name, string $text, bool $approved): array
    {
        $dir = self::directory();
        if (!$dir) {
            throw new \RuntimeException('galinf-comments Flex directory unavailable (flex-objects plugin required).');
        }

        $data = [
            'route'    => $route,
            'file'     => $file,
            'name'     => mb_substr(trim($name) !== '' ? trim($name) : 'Anon', 0, self::MAX_NAME),
            'text'     => mb_substr(trim($text), 0, self::MAX_TEXT),
            'date'     => date('c'),
            'approved' => $approved,
        ];

        $object = $dir->createObject($data);
        $object->save();

        return $data;
    }

    /**
     * Counters of ONE image from the Flex index: [approved count, last ts
     * (all comments, as before)].
     *
     * @return array{0:int, 1:int}
     */
    public static function statsFor(string $route, string $file): array
    {
        $approved = 0;
        $last = 0;
        foreach (self::entries() as $entry) {
            if (($entry['route'] ?? null) !== $route || ($entry['file'] ?? null) !== $file) {
                continue;
            }
            if (!empty($entry['approved'])) {
                $approved++;
            }
            $ts = (int) strtotime((string) ($entry['date'] ?? ''));
            if ($ts > $last) {
                $last = $ts;
            }
        }

        return [$approved, $last];
    }

    /**
     * Counters of ALL of an article's images (for the Indexer):
     * [file => [approved count, last ts]].
     *
     * @return array<string,array{0:int, 1:int}>
     */
    public static function statsByFile(string $route): array
    {
        $out = [];
        foreach (self::entries() as $entry) {
            if (($entry['route'] ?? null) !== $route) {
                continue;
            }
            $file = (string) ($entry['file'] ?? '');
            if ($file === '') {
                continue;
            }
            $out[$file] ??= [0, 0];
            if (!empty($entry['approved'])) {
                $out[$file][0]++;
            }
            $ts = (int) strtotime((string) ($entry['date'] ?? ''));
            if ($ts > $out[$file][1]) {
                $out[$file][1] = $ts;
            }
        }

        return $out;
    }

    /**
     * Applies a page move/rename: rewrites the route of the comments of the
     * old route AND of its descendants (children move with their parent).
     * Only the objects concerned are loaded.
     *
     * @return int Number of comments updated.
     */
    public static function changeRoute(string $oldRoute, string $newRoute): int
    {
        $dir = self::directory();
        if (!$dir || $oldRoute === '' || $oldRoute === $newRoute) {
            return 0;
        }

        $count = 0;
        $prefix = rtrim($oldRoute, '/') . '/';
        foreach (self::entries() as $key => $entry) {
            $route = (string) ($entry['route'] ?? '');
            if ($route !== $oldRoute && !str_starts_with($route, $prefix)) {
                continue;
            }
            $object = $dir->getObject((string) $key);
            if (null === $object) {
                continue;
            }
            $object->setProperty('route', $newRoute . substr($route, \strlen($oldRoute)));
            $object->save();
            $count++;
        }

        return $count;
    }

    /**
     * Purges the comments of a deleted page (and of its descendants).
     *
     * @return int Number of comments deleted.
     */
    public static function deleteForRoute(string $route): int
    {
        $dir = self::directory();
        if (!$dir || $route === '') {
            return 0;
        }

        $count = 0;
        $prefix = rtrim($route, '/') . '/';
        foreach (self::entries() as $key => $entry) {
            $r = (string) ($entry['route'] ?? '');
            if ($r !== $route && !str_starts_with($r, $prefix)) {
                continue;
            }
            $object = $dir->getObject((string) $key);
            if (null !== $object) {
                $object->delete();
                $count++;
            }
        }

        return $count;
    }

    /**
     * Invalidates the directory cache so that the reread index reflects a
     * write that just happened (same request).
     */
    public static function refresh(): void
    {
        $dir = self::directory();
        if ($dir) {
            try {
                $dir->clearCache();
            } catch (\Throwable $e) {
                // at worst, the index will be reread on the next request
            }
        }
    }
}
