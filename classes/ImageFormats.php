<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Grav;

/**
 * What this server can do with each image format, for the note under
 * "Included image extensions" (admin, General tab): the admin learns before
 * indexing that a format will be served as originals, or not at all.
 *
 * A thumbnail needs two things: Grav must treat the format as a resizable
 * image (`media.types.<ext>.type: image`; `bmp` and `tiff` are plain files
 * for Grav), and the image engine Grav uses (`system.images.adapter`: GD,
 * or Imagick when it is installed) must read and write it. A format Grav
 * does not know at all is never part of a page's media, so never indexed.
 */
final class ImageFormats
{
    /**
     * Formats every current browser displays: an original served as is
     * shows. Others (tiff, heic…) would be empty tiles in the gallery.
     */
    public const BROWSER = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp'];

    /** GD: format => constant of imagetypes() (some only exist in recent PHP). */
    private const GD = [
        'jpg' => 'IMG_JPG', 'jpeg' => 'IMG_JPG', 'jpe' => 'IMG_JPG', 'png' => 'IMG_PNG',
        'gif' => 'IMG_GIF', 'webp' => 'IMG_WEBP', 'avif' => 'IMG_AVIF', 'bmp' => 'IMG_BMP',
    ];

    /**
     * @return array{engine: string, types: array<string,string>, thumbnails: string[], browser: string[]}
     *   types: Grav media type of each known format (image, animated, file…);
     *   thumbnails: formats a thumbnail can be made of on this server.
     */
    public static function report(): array
    {
        $config = Grav::instance()['config'];
        $types = [];
        foreach ((array) $config->get('media.types', []) as $ext => $params) {
            $ext = strtolower((string) $ext);
            if ($ext !== 'defaults' && \is_array($params)) {
                $types[$ext] = (string) ($params['type'] ?? '');
            }
        }

        $engine = self::engine((string) $config->get('system.images.adapter', 'gd'));
        $thumbnails = [];
        foreach ($types as $ext => $type) {
            if ($type === 'image' && self::engineHandles($engine, $ext)) {
                $thumbnails[] = $ext;
            }
        }

        return ['engine' => $engine, 'types' => $types, 'thumbnails' => $thumbnails, 'browser' => self::BROWSER];
    }

    /** The engine Grav really uses: Imagick when set and installed, otherwise GD. */
    private static function engine(string $adapter): string
    {
        if ($adapter === 'imagick' && class_exists(\Imagick::class)) {
            return 'imagick';
        }
        return \function_exists('imagetypes') ? 'gd' : 'none';
    }

    private static function engineHandles(string $engine, string $ext): bool
    {
        if ($engine === 'gd') {
            $const = self::GD[$ext] ?? null;
            return $const !== null && \defined($const) && (imagetypes() & \constant($const)) !== 0;
        }
        if ($engine === 'imagick') {
            try {
                return \Imagick::queryFormats(strtoupper(\in_array($ext, ['jpg', 'jpe'], true) ? 'jpeg' : $ext)) !== [];
            } catch (\Throwable $e) {
                return false;
            }
        }
        return false;
    }
}
