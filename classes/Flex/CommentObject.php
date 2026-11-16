<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf\Flex;

use Grav\Common\Flex\Types\Generic\GenericObject;
use Grav\Common\Grav;
use Grav\Plugin\Galinf\Comments;

/**
 * "Comment" Flex object: adds VIRTUAL properties for the admin list —
 * `url` (the article's address) and `image_url` (the image file's address,
 * served by Grav on the page route), site base included, declared as `url`
 * columns → the SPA renders them as real links; `state`, which tells an
 * orphan comment (page or image gone) from the others.
 */
class CommentObject extends GenericObject
{
    /**
     * `route` and `file` can be edited freely in the admin form: they only
     * become a link when they have the expected shape. Without this, on a
     * site installed at the domain root (empty base), a route such as
     * `javascript:…` or `//other-site/…` would be the link itself.
     */
    public static function validRoute(string $route): bool
    {
        return !str_contains($route, '\\') && preg_match('#^/(?!/)\S*$#', $route) === 1;
    }

    public static function validFile(string $file): bool
    {
        return $file !== '' && strpbrk($file, "/\\") === false && !preg_match('/[[:cntrl:]]/', $file);
    }

    /**
     * @param string $property
     * @param mixed $default
     * @return mixed
     */
    public function getProperty($property, $default = null)
    {
        if ($property === 'url' || $property === 'image_url') {
            $route = (string) parent::getProperty('route');
            if (!self::validRoute($route)) {
                return '';
            }
            $base = rtrim((string) (Grav::instance()['base_url'] ?? ''), '/');
            if ($property === 'image_url') {
                $file = (string) parent::getProperty('file');

                return self::validFile($file) ? $base . rtrim($route, '/') . '/' . rawurlencode($file) : '';
            }

            return $base . $route;
        }

        if ($property === 'state') {
            $state = Comments::state((string) parent::getProperty('route'), (string) parent::getProperty('file'));
            if ($state === '') {
                return '';
            }
            $key = $state === Comments::STATE_PAGE_GONE ? 'COMMENT_STATE_PAGE_GONE' : 'COMMENT_STATE_IMAGE_GONE';

            return self::label($key);
        }

        return parent::getProperty($property, $default);
    }

    /**
     * Admin text, in the language the logged-in admin chose for Admin Next
     * when it can be known (the list is built by the api plugin, which keeps
     * that preference), otherwise in the site's language, otherwise English.
     */
    private static function label(string $key): string
    {
        static $cache = [];
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $fallback = ['COMMENT_STATE_PAGE_GONE' => 'Page gone', 'COMMENT_STATE_IMAGE_GONE' => 'Image gone'];
        $text = '';
        try {
            $grav  = Grav::instance();
            $langs = [];
            $resolver = 'Grav\\Plugin\\Api\\Services\\PreferencesResolver';
            $user = $grav['user'] ?? null;
            if ($user && class_exists($resolver)) {
                $prefs = (new $resolver($grav, $grav['config']))->resolve($user, false);
                $admin = $prefs['effective']['adminLanguage'] ?? null;
                if (\is_string($admin) && $admin !== '') {
                    $langs[] = $admin;
                    $langs[] = strtolower(explode('-', $admin)[0]);   // fr-FR → fr
                }
            }
            $langs[] = 'en';
            $text = $grav['language']->translate(['ICU.PLUGIN_GALINF.' . $key], $langs);
        } catch (\Throwable $e) {
            $text = '';
        }

        return $cache[$key] = \is_string($text) && $text !== '' && !str_contains($text, 'PLUGIN_GALINF')
            ? $text
            : $fallback[$key];
    }
}
