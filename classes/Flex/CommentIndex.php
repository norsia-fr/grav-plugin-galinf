<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf\Flex;

use Grav\Common\Flex\Types\Generic\GenericIndex;
use Grav\Framework\Flex\Interfaces\FlexStorageInterface;

/**
 * Flex index of comments: copies route/file/approved/date into the index
 * entries. Everything the plugin needs on the fly (an article's comments,
 * per-image counters, sorting the admin list by date) is then read from the
 * index file ALONE, without loading the objects — a prerequisite for
 * handling tens of thousands of comments.
 */
class CommentIndex extends GenericIndex
{
    // Changing the version forces the index file to be rebuilt.
    const VERSION = parent::VERSION . '.galinf.3';

    /**
     * Enables the enriched index FILE (index.yaml next to the storage), like
     * the core's UserIndex/PageIndex — GenericIndex does not: without this
     * override, updateObjectMeta is never called and the entries come down to
     * key + timestamp.
     *
     * @param FlexStorageInterface $storage
     * @return array
     */
    public static function loadEntriesFromStorage(FlexStorageInterface $storage): array
    {
        // Saved index (may be stale).
        $index = static::loadIndex($storage);
        $version = $index['version'] ?? 0;
        $force = static::VERSION !== $version;

        // Up-to-date entries (the storage's real keys + timestamps).
        $entries = parent::loadEntriesFromStorage($storage);

        // Safeguard: the storage scan uses a path RELATIVE to the cwd (Grav
        // core). Under Apache mod_php, the cwd changes in shutdown functions →
        // a rebuild there would see "zero objects" and overwrite the index. If
        // the storage folder is not even visible, the saved index is returned
        // as is rather than destroyed.
        if (!$entries && !empty($index['index'])) {
            $path = $storage->getStoragePath();
            if (!$path || !file_exists($path)) {
                return $index['index'];
            }
        }

        return static::updateIndexFile($storage, $index['index'], $entries, ['force_update' => $force]);
    }

    /**
     * @param array $meta
     * @param array $data
     * @param FlexStorageInterface $storage
     * @return void
     */
    public static function updateObjectMeta(array &$meta, array $data, FlexStorageInterface $storage)
    {
        parent::updateObjectMeta($meta, $data, $storage);

        $meta['route']    = (string) ($data['route'] ?? '');
        $meta['file']     = (string) ($data['file'] ?? '');
        $meta['approved'] = !empty($data['approved']) ? 1 : 0;
        $meta['date']     = (string) ($data['date'] ?? '');
        // The admin list's "Page" and "Image" columns (url/image_url virtual
        // properties) are sortable like every SPA column (clickable headers,
        // cannot be disabled): their sort keys are indexed — route and
        // route/file, same order since the site base is a constant prefix — so
        // that these sorts NEVER load the objects.
        $meta['url']       = $meta['route'];
        $meta['image_url'] = $meta['route'] . '/' . $meta['file'];
    }
}
