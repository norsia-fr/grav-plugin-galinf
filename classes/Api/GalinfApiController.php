<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Galinf\IndexDatabase;
use Grav\Plugin\Galinf\Indexer;
use Grav\Plugin\Galinf\ReindexService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin endpoints of the gallery plugin (rare actions, admin only).
 *
 * NB: this is the ONLY legitimate use of the API plugin here (spec
 * §6/§8b). The public feed does NOT go through the API (direct plugin
 * route, outside the auth stack).
 */
class GalinfApiController extends AbstractApiController
{
    /**
     * POST /galinf/reindex-batch — one reindex BATCH (~2 s of work); the
     * interface chains the calls, sending back `offset`.
     * Body: {"mode": "index"|"missing"|"force"|"scratch", "offset": 0, "action": "…"}
     *   index   → updates the index without creating any thumbnail
     *   missing → + creates the missing thumbnails (default)
     *   force   → + recreates every thumbnail
     *   scratch → empties the index first, then like "missing"
     */
    public function reindexBatch(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $body   = $this->getRequestBody($request);
        $mode   = \is_string($body['mode'] ?? null) ? $body['mode'] : 'missing';
        $offset = max(0, (int) ($body['offset'] ?? 0));

        $thumbs = match ($mode) {
            'index' => Indexer::THUMBS_NONE,
            'force' => Indexer::THUMBS_FORCE,
            default => Indexer::THUMBS_MISSING,
        };

        // Maintenance block action behind the call ("Run all" and "Create
        // missing thumbnails" share the same mode).
        $action = \is_string($body['action'] ?? null) ? $body['action'] : '';
        if (!\in_array($action, ['all', 'index', 'missing', 'force', 'scratch'], true)) {
            $action = '';
        }

        return ApiResponse::create(ReindexService::runBatch($thumbs, $offset, $mode === 'scratch', $action));
    }

    /**
     * POST /galinf/purge-thumbs — one BATCH (~2 s of work) of the orphan
     * thumbnail purge; the interface chains the calls, sending back
     * `cursor` (the last folder processed). Body: {"cursor": ""}.
     */
    public function purgeThumbs(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $body   = $this->getRequestBody($request);
        $cursor = \is_string($body['cursor'] ?? null) && preg_match('/^[0-9a-f]{2}$/', $body['cursor'])
            ? $body['cursor'] : '';

        return ApiResponse::create(ReindexService::purgeBatch($cursor));
    }

    /**
     * POST /galinf/remove-data — deletes the index and thumbnails ahead of
     * an uninstall. Body: {"comments": true} to ALSO delete the visitor
     * comments (irreversible: super admin only).
     */
    public function removeData(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $body = $this->getRequestBody($request);
        if (($body['comments'] ?? false) === true) {
            $this->requireSuper($request);
        }

        return ApiResponse::create(ReindexService::removeData(($body['comments'] ?? false) === true));
    }

    /**
     * GET /galinf/stats — number of indexed images + last indexing date.
     */
    public function stats(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        return ApiResponse::create(ReindexService::stats());
    }

    /**
     * GET /galinf/missing-thumbs — full list of images without a thumbnail
     * (the stats only return the first 20).
     */
    public function missingThumbs(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        return ApiResponse::create(ReindexService::missingThumbsList());
    }

    /**
     * POST /galinf/shrink — shrinks IN PLACE an image too large for Grav to
     * resize, then reindexes its article. Body:
     * {"image_path": "blog/article/photo.jpg"}. One image per request (~7 s
     * on a Raspberry Pi): the interface chains the calls.
     *
     * A processing failure is not an HTTP error: {ok:false, error, code} lets
     * the interface show the reason on the line concerned.
     */
    public function shrink(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $body = $this->getRequestBody($request);
        $path = \is_string($body['image_path'] ?? null) ? $body['image_path'] : '';
        if ($path === '') {
            throw new \Grav\Plugin\Api\Exceptions\ValidationException('image_path is missing.');
        }

        return ApiResponse::create(['image_path' => $path] + ReindexService::shrinkOversized([$path])[$path]);
    }

    /**
     * GET /galinf/comment-preview/{key} — preview URLs of the image a comment
     * is about (for the custom field of the Flex edit form): persistent
     * thumbnail known to the index, otherwise the original. Same permission
     * as reading the comments in the admin (granted per user or group).
     */
    public function commentPreview(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'admin.galinf-comments.read');

        $key = (string) $this->getRouteParam($request, 'key');
        $directory = \Grav\Plugin\Galinf\Comments::directory();
        $object = ($directory && $key !== '') ? $directory->getObject($key) : null;
        if (null === $object) {
            throw new \Grav\Plugin\Api\Exceptions\NotFoundException('Unknown comment.');
        }

        $route = (string) $object->getProperty('route');
        $file  = (string) $object->getProperty('file');
        $base  = rtrim((string) ($this->grav['base_url'] ?? ''), '/');

        // Thumbnail: read from the index, as the gallery does. It thus
        // carries its ?v=<mtime> token (galinf-thumbs/ is served "immutable"
        // for a year: without the token, a replaced image would keep its old
        // preview). No thumbnail (animated gif, oversized image) → null, and
        // the interface falls back on the original.
        $row   = IndexDatabase::fromConfig()->findImage(ltrim($route, '/') . '/' . $file);
        $thumb = $row['thumb_path'] ?? null;

        return ApiResponse::create([
            'image' => $route !== '' && $file !== '' ? $base . $route . '/' . rawurlencode($file) : null,
            'thumb' => $thumb !== null ? $base . '/' . $thumb : null,
            'file'  => $file,
            'route' => $route,
        ]);
    }
}
