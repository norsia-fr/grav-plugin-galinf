<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Grav;

/**
 * The plugin's three public routes (`feed_route`, `meta_route`,
 * `comment_route`), checked when they are read.
 *
 * They are not in the admin form, but anyone allowed to write the plugin's
 * configuration (the YAML file, or the api plugin's config endpoint, which
 * keeps keys the form does not show) can set them. A value is only used if
 * it looks like a route of its own: starts with `/`, ends with `.json`,
 * plain characters, not under the admin's or the API's route, and different
 * from the two others. Otherwise the default is used, and the Maintenance
 * tab says so (issues()).
 */
final class Routes
{
    public const DEFAULTS = [
        'feed_route'    => '/gallery-feed.json',
        'meta_route'    => '/gallery-meta.json',
        'comment_route' => '/gallery-comment.json',
    ];

    /** @var array{routes: array<string,string>, issues: string[]}|null */
    private static ?array $resolved = null;

    /** The route to use for $key (one of DEFAULTS' keys). */
    public static function get(string $key): string
    {
        return self::resolve()['routes'][$key] ?? self::DEFAULTS[$key];
    }

    /**
     * Options whose value was refused (the default is used instead).
     *
     * @return string[]
     */
    public static function issues(): array
    {
        return self::resolve()['issues'];
    }

    /**
     * @return array{routes: array<string,string>, issues: string[]}
     */
    private static function resolve(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $config = Grav::instance()['config'];
        $reserved = [];
        foreach (['plugins.admin2.route' => '/admin', 'plugins.admin.route' => '/admin', 'plugins.api.route' => '/api'] as $k => $default) {
            $r = trim((string) $config->get($k, $default), '/');
            if ($r !== '') {
                $reserved[strtolower(explode('/', $r)[0])] = true;
            }
        }

        $routes = [];
        $issues = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $config->get('plugins.galinf.' . $key, $default);
            $value = \is_string($value) ? trim($value) : '';
            if (!self::acceptable($value, $reserved) || \in_array(strtolower($value), array_map('strtolower', $routes), true)) {
                if ($value !== $default) {
                    $issues[] = $key;
                }
                $value = $default;
            }
            $routes[$key] = $value;
        }
        // A refused value may have been replaced by a default another option
        // already uses: then the three defaults, which are distinct.
        if (\count(array_unique(array_map('strtolower', $routes))) < \count($routes)) {
            foreach (self::DEFAULTS as $key => $default) {
                if ($routes[$key] !== $default && !\in_array($key, $issues, true)) {
                    $issues[] = $key;
                }
            }
            $routes = self::DEFAULTS;
        }

        return self::$resolved = ['routes' => $routes, 'issues' => $issues];
    }

    /**
     * @param array<string,true> $reserved first segments that belong to the admin or the API
     */
    private static function acceptable(string $route, array $reserved): bool
    {
        if (!preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.json$#', $route) || \strlen($route) > 100) {
            return false;
        }
        $first = strtolower(explode('/', substr($route, 1))[0]);
        $first = preg_replace('/\.json$/', '', $first);

        return !isset($reserved[$first]);
    }
}
