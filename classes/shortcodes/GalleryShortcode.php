<?php

declare(strict_types=1);

namespace Grav\Plugin\Shortcodes;

use Grav\Plugin\Galinf\GalleryRenderer;
use Thunder\Shortcode\Shortcode\ShortcodeInterface;

/**
 * Gallery shortcodes, rendered in any theme (assets served from the
 * plugin). Logic shared with the Twig functions through GalleryRenderer.
 *
 *   [galinf]                       → global gallery (plugin options)
 *   [galinf sort="asc"]            → global, initial order imposed
 *   [galinf field="folder"]        → global, criterion imposed (date|folder)
 *   [galinf src="article"]         → media of the current page
 *   [galinf src="/blog/my-page"]   → media of a given page
 *   [galinf-article]               → shortcut for src="article"
 */
class GalleryShortcode extends Shortcode
{
    public function init()
    {
        $this->shortcode->getHandlers()->add('galinf', function (ShortcodeInterface $sc) {
            return $this->renderGallery([
                'src'    => (string) $sc->getParameter('src', ''),
                'sort'   => (string) $sc->getParameter('sort', ''),
                'field'  => (string) $sc->getParameter('field', ''),
            ]);
        });

        $this->shortcode->getHandlers()->add('galinf-article', function (ShortcodeInterface $sc) {
            return $this->renderGallery(['src' => 'article']);
        });
    }

    private function renderGallery(array $opts): string
    {
        // Assets in the page meta (they survive a content cache HIT).
        GalleryRenderer::registerCachedAssets($this->shortcode);
        // HTML + assets added straight to the pipeline. The page is the one
        // whose content is being processed, not the displayed page: an
        // article rendered in a list (blog summaries, feed) thus keeps ITS
        // gallery.
        return GalleryRenderer::render($opts, $this->shortcode->getPage());
    }
}
