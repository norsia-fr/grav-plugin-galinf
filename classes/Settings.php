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
}
