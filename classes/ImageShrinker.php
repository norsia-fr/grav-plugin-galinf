<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

/**
 * Shrinks IN PLACE an image whose pixel count exceeds the limit beyond which
 * Grav core refuses to resize it (`system.images.max_pixels`, since Grav
 * 2.1.7).
 *
 * The original is REPLACED (user's choice, after confirmation in the
 * interface). The result is written to a neighboring temporary file, then
 * swapped in with rename(): on failure, the original stays intact.
 *
 * Re-encoding with GD does not keep the EXIF data; the EXIF orientation is
 * therefore applied to the pixels before writing, otherwise the image would
 * appear lying on its side.
 */
class ImageShrinker
{
    private const JPEG_QUALITY = 90;
    private const WEBP_QUALITY = 90;

    /**
     * @return array{old_width:int, old_height:int, width:int, height:int}
     * @throws ShrinkError code translated by the admin + English sentence (CLI)
     */
    public static function shrink(string $file, int $maxPixels): array
    {
        if ($maxPixels <= 0) {
            throw new ShrinkError('no_limit', 'No pixel limit is configured.');
        }
        if (!is_file($file)) {
            throw new ShrinkError('file_missing', 'File not found.');
        }
        if (!is_writable($file) || !is_writable(\dirname($file))) {
            throw new ShrinkError('not_writable', 'The web server is not allowed to modify this file.');
        }
        if (!\function_exists('imagecreatetruecolor')) {
            throw new ShrinkError('no_gd', 'The PHP GD extension is missing.');
        }

        $size = @getimagesize($file);
        if ($size === false) {
            throw new ShrinkError('unreadable', 'Unreadable image.');
        }
        [$w, $h, $type] = [(int) $size[0], (int) $size[1], (int) $size[2]];
        if ($w <= 0 || $h <= 0 || $h <= intdiv($maxPixels, $w)) {
            throw new ShrinkError('under_limit', 'This image does not exceed the limit.');
        }

        $reader = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng',
                   IMAGETYPE_WEBP => 'imagecreatefromwebp'][$type] ?? null;
        if ($reader === null || !\function_exists($reader)) {
            throw new ShrinkError('unsupported', 'Unsupported format (JPEG, PNG and WebP only).');
        }

        [$nw, $nh] = self::targetSize($w, $h, $maxPixels);

        // Decoding several tens of megapixels takes 100 to 200 MB.
        @ini_set('memory_limit', '-1');
        @set_time_limit(0);

        $src = @$reader($file);
        if (!$src) {
            throw new ShrinkError('decode_failed', 'The image could not be decoded.');
        }

        $dst = imagecreatetruecolor($nw, $nh);
        if ($type !== IMAGETYPE_JPEG) {
            // Transparency kept (PNG, WebP).
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        if ($type === IMAGETYPE_JPEG) {
            $dst = self::applyOrientation($dst, self::orientation($file));
        }

        // Extension kept on the temporary file: not needed, but with no risk
        // of being taken for a page medium (dot prefix = hidden file).
        $tmp = \dirname($file) . '/.galinf-shrink-' . bin2hex(random_bytes(6)) . '.tmp';
        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($dst, $tmp, self::JPEG_QUALITY),
            IMAGETYPE_PNG  => imagepng($dst, $tmp),
            IMAGETYPE_WEBP => imagewebp($dst, $tmp, self::WEBP_QUALITY),
        };
        $finalW = imagesx($dst);
        $finalH = imagesy($dst);
        imagedestroy($dst);

        if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            throw new ShrinkError('write_failed', 'The shrunk image could not be written.');
        }

        @chmod($tmp, fileperms($file) & 0777);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new ShrinkError('replace_failed', 'The original could not be replaced.');
        }
        clearstatcache(true, $file);

        return ['old_width' => $w, 'old_height' => $h, 'width' => $finalW, 'height' => $finalH];
    }

    /**
     * Dimensions after shrinking: just under the limit, proportions kept.
     * The core refuses as soon as height > intdiv(limit, width): the same
     * test is applied. Also used to announce the result in the admin.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public static function targetSize(int $w, int $h, int $maxPixels): array
    {
        $scale = sqrt($maxPixels / ($w * $h));
        $nw = max(1, (int) floor($w * $scale));
        $nh = max(1, (int) floor($h * $scale));
        while ($nh > 1 && $nh > intdiv($maxPixels, $nw)) {
            $nh--;
        }

        return [$nw, $nh];
    }

    private static function orientation(string $file): int
    {
        if (!\function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($file);
        return \is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    /**
     * Applies the EXIF orientation (values 2 to 8) to the pixels.
     * imagerotate() rotates counter-clockwise.
     */
    private static function applyOrientation(\GdImage $img, int $orientation): \GdImage
    {
        $angle = [3 => 180, 4 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated !== false) {
                imagedestroy($img);
                $img = $rotated;
            }
        }
        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }
        return $img;
    }
}
