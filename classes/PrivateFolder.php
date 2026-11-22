<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Grav;

/**
 * Keeps the plugin's private data folder out of reach over HTTP:
 * `user/data/galinf/`, which holds the index database (`gallery.sqlite`, with
 * its `-wal`/`-shm` companion files) and the comments. Also the folder of
 * versions up to 2.1.0 (`user/data/gallery-index/`) as long as it exists
 * (LEGACY 2.1.0, remove in 3.0).
 *
 * Grav 2's own `.htaccess` (and its Nginx sample) already refuse everything
 * under `user/data/`. But a site upgraded from Grav 1.7 usually keeps its
 * old server rules, which only refuse some extensions under `user/`
 * (`.yaml`, `.md`…): `gallery.sqlite` would then be downloadable. It holds,
 * among others, the titles and routes of scheduled articles that are not
 * published yet. A deny-all `.htaccess` in each folder closes that on Apache
 * whatever the site's rules; Nginx ignores it (see the README).
 */
final class PrivateFolder
{
    /** First line of the file: tells ours apart from one written by someone else. */
    private const MARKER = '# galinf: private data';

    private const CONTENT = self::MARKER . " (index database, comments). Never served over HTTP.\n"
        . "# Grav 2's own rules already refuse user/data/; this file also covers sites\n"
        . "# that kept an older .htaccess. Written by the galinf plugin.\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Order allow,deny\n"
        . "    Deny from all\n"
        . "</IfModule>\n";

    /** @var array<string,true> folders already handled in this process */
    private static array $done = [];

    /** The comments folder (user/data/galinf), existing or not. */
    public static function dataFolder(): ?string
    {
        $data = Grav::instance()['locator']->findResource('user-data://', true, true);

        return \is_string($data) && $data !== '' ? rtrim($data, '/') . '/galinf' : null;
    }

    /**
     * Writes the deny-all `.htaccess` in $dir (existing folder), once per
     * process. An `.htaccess` already there is left alone, ours or not.
     * Never throws: a folder that cannot be written keeps Grav's own rules.
     */
    public static function protect(string $dir): void
    {
        if (isset(self::$done[$dir])) {
            return;
        }
        self::$done[$dir] = true;

        $file = $dir . '/.htaccess';
        if (!is_dir($dir) || is_file($file)) {
            return;
        }
        @file_put_contents($file, self::CONTENT, LOCK_EX);
        @chmod($file, 0644);
    }

    /**
     * Removes $dir when nothing but our `.htaccess` is left in it (data
     * deleted before an uninstall). Anything else there keeps the folder,
     * and its protection.
     */
    public static function removeIfEmpty(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = array_values(array_diff((array) @scandir($dir), ['.', '..']));
        if ($entries === ['.htaccess'] && self::isOurs($dir . '/.htaccess')) {
            @unlink($dir . '/.htaccess');
        }
        @rmdir($dir);   // only if it is empty
        unset(self::$done[$dir]);
    }

    private static function isOurs(string $file): bool
    {
        $head = @file_get_contents($file, false, null, 0, \strlen(self::MARKER));

        return $head === self::MARKER;
    }
}
