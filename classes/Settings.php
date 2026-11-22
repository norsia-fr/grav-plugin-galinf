<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Grav;

/**
 * Numeric settings, bounded where they are read.
 *
 * The admin form checks these bounds, but a setting can also come from the
 * YAML file or the api plugin's config endpoint, which skip them: a value
 * out of range is brought back to the nearest bound (a non-number gives the
 * default), so that it cannot, for instance, make every reindex create
 * huge "thumbnails" or break the gallery's layout.
 */
final class Settings
{
    /** key => [default, min, max] — same bounds as blueprints.yaml. */
    private const BOUNDS = [
        'thumb_width'        => [500, 100, 1200],
        'batch_size'         => [80, 10, 200],
        'target_row_height'  => [220, 80, 600],
        'gap'                => [6, 0, 40],
        'preload_margin'     => [500, 100, 2000],
        'metadata_lookahead' => [800, 0, 100000],
    ];

    public static function int(string $key): int
    {
        [$default, $min, $max] = self::BOUNDS[$key];
        $value = Grav::instance()['config']->get('plugins.galinf.' . $key, $default);
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /** Image extensions when none is set (or none is accepted). */
    public const DEFAULT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Extensions refused even when listed in `extensions`. An SVG file can
     * carry scripts: opened on its own (link to the original), they run on
     * the site's address, with the session of whoever opens it — an admin
     * included. GD cannot make a thumbnail of it either, and its size is not
     * always readable, which the justified layout needs.
     */
    public const REFUSED_EXTENSIONS = ['svg', 'svgz'];

    /**
     * The `extensions` setting, normalized (lowercase, no dot, no duplicate)
     * and without the refused ones; the defaults if nothing is left.
     *
     * @return string[]
     */
    public static function extensions(): array
    {
        return self::cleanExtensions((array) Grav::instance()['config']->get('plugins.galinf.extensions', self::DEFAULT_EXTENSIONS));
    }

    /**
     * @param array<mixed> $list
     * @return string[]
     */
    public static function cleanExtensions(array $list): array
    {
        $clean = array_values(array_diff(self::normalize($list), self::REFUSED_EXTENSIONS));

        return $clean !== [] ? $clean : self::DEFAULT_EXTENSIONS;
    }

    /**
     * Refused extensions present in the setting (the Maintenance tab says
     * they are ignored).
     *
     * @return string[]
     */
    public static function refusedExtensions(): array
    {
        $list = (array) Grav::instance()['config']->get('plugins.galinf.extensions', self::DEFAULT_EXTENSIONS);

        return array_values(array_intersect(self::normalize($list), self::REFUSED_EXTENSIONS));
    }

    /**
     * @param array<mixed> $list
     * @return string[]
     */
    private static function normalize(array $list): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn($e) => is_scalar($e) ? strtolower(ltrim(trim((string) $e), '.')) : '',
            $list
        ), static fn(string $e): bool => $e !== '')));
    }
}
