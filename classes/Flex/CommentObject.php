<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf\Flex;

use Grav\Common\Flex\Types\Generic\GenericObject;
use Grav\Common\Grav;

/**
 * "Comment" Flex object: adds the VIRTUAL properties `url` (the article's
 * address) and `image_url` (the image file's address, served by Grav on the
 * page route), site base included. The admin list declares them as `url`
 * columns → the SPA renders them as real links.
 */
class CommentObject extends GenericObject
{
    /**
     * @param string $property
     * @param mixed $default
     * @return mixed
     */
    public function getProperty($property, $default = null)
    {
        if ($property === 'url' || $property === 'image_url') {
            $route = (string) parent::getProperty('route');
            if ($route === '') {
                return '';
            }
            $base = rtrim((string) (Grav::instance()['base_url'] ?? ''), '/');
            if ($property === 'image_url') {
                $file = (string) parent::getProperty('file');

                return $file !== '' ? $base . $route . '/' . rawurlencode($file) : '';
            }

            return $base . $route;
        }

        return parent::getProperty($property, $default);
    }
}
