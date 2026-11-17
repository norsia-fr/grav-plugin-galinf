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
    /** Nickname length a visitor may type (the admin keeps MAX_NAME). */
    public const MAX_VISITOR_NAME = 30;
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
     * A visitor's nickname, cleaned: no control or invisible formatting
     * character (line breaks, right-to-left override U+202E, zero-width
     * characters…), runs of spaces reduced to one, trimmed.
     */
    public static function cleanName(string $name): string
    {
        // Line breaks and tabs separate words: they become spaces first.
        $name = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $name);
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name);
        $name = (string) preg_replace('/ {2,}/', ' ', $name);

        return trim($name);
    }

    /**
     * A comment's text, cleaned: line breaks kept (\r\n → \n), tabs turned
     * into spaces, other control characters removed, and the invisible
     * characters that can disguise text (writing direction overrides,
     * zero-width spaces) removed. The zero-width joiner stays: composed
     * emoji need it.
     */
    public static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[^\P{Cc}\n]+/u', '', $text);
        $text = (string) preg_replace('/[\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{061C}\x{00AD}]+/u', '', $text);

        return trim($text);
    }

    /**
     * Comparison form of a name: Unicode-normalized (full-width letters…),
     * lower-case, without spaces, dots, hyphens or underscores. "Nor Sia",
     * "norsia" and "NORSIA" compare equal.
     */
    public static function nameKey(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $name = (string) \Normalizer::normalize($name, \Normalizer::FORM_KC);
        }

        return (string) preg_replace('/[\s\p{Z}._\-]+/u', '', mb_strtolower(self::cleanName($name)));
    }

    /**
     * Is this nickname taken by an account of the site (username or full
     * name)? A visitor who is not logged in cannot sign as a member, or as
     * an administrator.
     */
    public static function isReservedName(string $name): bool
    {
        $key = self::nameKey($name);

        return $key !== '' && isset(self::reservedNames()[$key]);
    }

    /**
     * Comparison forms of every account's username and full name. Account
     * files are only reread when one of them changes (same file count and
     * latest modification time → cached list).
     *
     * @return array<string,true>
     */
    private static function reservedNames(): array
    {
        $grav = Grav::instance();
        $dir  = $grav['locator']->findResource('account://', true);
        if (!\is_string($dir) || !is_dir($dir)) {
            return [];
        }

        // Regular and Flex "file" storage: <user>.yaml; Flex "folder"
        // storage: <user>/user.yaml.
        $files = array_merge(glob($dir . '/*.yaml') ?: [], glob($dir . '/*/user.yaml') ?: []);
        $stamp = 0;
        foreach ($files as $file) {
            $stamp = max($stamp, (int) @filemtime($file));
        }
        $cacheKey = 'galinf-reserved-names-' . md5($dir . '|' . \count($files) . '|' . $stamp);
        $cache = $grav['cache'];
        $names = $cache->fetch($cacheKey);
        if (\is_array($names)) {
            return $names;
        }

        $names = [];
        foreach ($files as $file) {
            $username = basename($file) === 'user.yaml' ? basename(\dirname($file)) : basename($file, '.yaml');
            $names[self::nameKey($username)] = true;
            try {
                $data = \Grav\Common\File\CompiledYamlFile::instance($file)->content();
                $full = \is_array($data) && \is_string($data['fullname'] ?? null) ? $data['fullname'] : '';
                if ($full !== '') {
                    $names[self::nameKey($full)] = true;
                }
            } catch (\Throwable $e) {
                // unreadable account file: its username is still reserved
            }
        }
        unset($names['']);
        $cache->save($cacheKey, $names, 3600);

        return $names;
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
            Grav::instance()['log']->warning('galinf: could not read the comments index — ' . $e->getMessage());
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
                // Posted by a logged-in member (never the account name itself).
                'member'   => (string) $object->getProperty('user') !== '',
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
    public static function add(string $route, string $file, string $name, string $text, bool $approved, string $user = ''): array
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
        if ($user !== '') {
            // Account of a logged-in member: the comment is shown as theirs.
            $data['user'] = $user;
        }

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
     * old route AND of its descendants (children move with their parent;
     * the home page, whose route is `/`, has none).
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

        // The home page's route is `/`: taken as a prefix it would match EVERY
        // comment of the site. The home page has no descendants in terms of
        // routes, so only its own comments follow it (same rule as
        // Indexer::forgetSubtree). Likewise when the page BECOMES the home.
        $old    = rtrim($oldRoute, '/');
        $new    = rtrim($newRoute, '/');
        $prefix = $old !== '' ? $old . '/' : null;

        $count = 0;
        foreach (self::entries() as $key => $entry) {
            $route = (string) ($entry['route'] ?? '');
            if ($route === $oldRoute) {
                $target = $newRoute;
            } elseif ($prefix !== null && str_starts_with($route, $prefix)) {
                $target = $new . substr($route, \strlen($old));
            } else {
                continue;
            }
            $object = $dir->getObject((string) $key);
            if (null === $object) {
                continue;
            }
            $object->setProperty('route', $target);
            $object->save();
            $count++;
        }

        return $count;
    }

    /** Comment whose page no longer exists. */
    public const STATE_PAGE_GONE = 'page';
    /** Comment whose page exists but no longer holds the image file. */
    public const STATE_IMAGE_GONE = 'image';

    /**
     * Is the comment still attached to something? '' when its page and its
     * image exist, otherwise STATE_PAGE_GONE or STATE_IMAGE_GONE.
     *
     * Nothing is purged when a page is deleted (a deletion can be undone, the
     * home page's route is `/`, a single translation may be removed…): such
     * comments simply stay out of sight — no public endpoint serves a page
     * that is not there — until the admin fixes or deletes them, one by one in
     * the list, or deletes them all at once from the Maintenance tab.
     *
     * When in doubt (pages could not be listed), the answer is '': a comment
     * is never declared orphan by mistake.
     */
    public static function state(string $route, string $file): string
    {
        $folders = self::pageFolders();
        if ($folders === [] || $route === '') {
            return '';
        }
        if (!isset($folders[$route])) {
            return self::STATE_PAGE_GONE;
        }
        if ($file !== '' && $folders[$route] !== '' && !is_file($folders[$route] . '/' . basename($file))) {
            return self::STATE_IMAGE_GONE;
        }
        return '';
    }

    /**
     * Orphan comments — page gone, or page still there but image gone —
     * as [key => STATE_PAGE_GONE | STATE_IMAGE_GONE]. Read from the Flex
     * index alone (route and file are copied into it).
     *
     * A page or an image that was only RENAMED outside the admin looks the
     * same from here: that is why nothing deletes these comments on its own,
     * and why the admin can fix their route or file in the list instead.
     *
     * @return array<string,string>
     */
    public static function orphans(): array
    {
        $found = [];
        foreach (self::entries() as $key => $entry) {
            $state = self::state((string) ($entry['route'] ?? ''), (string) ($entry['file'] ?? ''));
            if ($state !== '') {
                $found[(string) $key] = $state;
            }
        }
        return $found;
    }

    /**
     * @return array{pages:int, images:int} orphan comments per cause
     */
    public static function orphanCounts(): array
    {
        $counts = array_count_values(self::orphans());

        return [
            'pages'  => (int) ($counts[self::STATE_PAGE_GONE] ?? 0),
            'images' => (int) ($counts[self::STATE_IMAGE_GONE] ?? 0),
        ];
    }

    /**
     * Deletes every orphan comment (Maintenance tab,
     * `bin/plugin galinf purge-orphan-comments`).
     *
     * @return array{pages:int, images:int} comments deleted per cause
     */
    public static function deleteOrphans(): array
    {
        $deleted = ['pages' => 0, 'images' => 0];
        $dir = self::directory();
        if (!$dir) {
            return $deleted;
        }
        foreach (self::orphans() as $key => $state) {
            $object = $dir->getObject($key);
            if (null !== $object) {
                $object->delete();
                $deleted[$state === self::STATE_PAGE_GONE ? 'pages' : 'images']++;
            }
        }
        if ($deleted['pages'] + $deleted['images'] > 0) {
            self::refresh();
        }
        return $deleted;
    }

    /** @var array<string,string>|null route => folder of every existing page (per request). */
    private static ?array $pageFolders = null;

    /**
     * Every page of the site, published or not, in every language: route =>
     * folder. Empty when the pages could not be listed.
     *
     * @return array<string,string>
     */
    private static function pageFolders(): array
    {
        if (self::$pageFolders !== null) {
            return self::$pageFolders;
        }

        $folders = [];
        try {
            $grav  = Grav::instance();
            $pages = $grav['pages'];
            if (method_exists($pages, 'enablePages')) {
                $pages->enablePages();
            }
            $pages->init();
            self::collectFolders($pages, $folders);

            // Multilingual site: routes can differ from one language to the
            // next (translated slugs) → every language is listed.
            $language = $grav['language'];
            if ($language->enabled()) {
                $active = $language->getActive();
                try {
                    foreach ((array) $language->getLanguages() as $lang) {
                        if ($lang === $active) {
                            continue;
                        }
                        $language->setActive($lang);
                        $pages->reset();
                        self::collectFolders($pages, $folders);
                    }
                } finally {
                    $language->setActive($active);
                    $pages->reset();
                }
            }
        } catch (\Throwable $e) {
            Grav::instance()['log']->warning('galinf: could not list the pages — ' . $e->getMessage());
            $folders = [];
        }

        return self::$pageFolders = $folders;
    }

    private static function collectFolders($pages, array &$folders): void
    {
        foreach ($pages->instances() as $page) {
            $route = $page->route();
            if (\is_string($route) && $route !== '') {
                $folders[$route] = (string) $page->path();
            }
        }
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
