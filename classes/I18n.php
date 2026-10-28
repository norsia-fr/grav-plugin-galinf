<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Grav;

/**
 * Visitor texts (gallery, lightbox, comments): dictionaries injected into
 * the gallery HTML, with no extra request.
 *
 * Source: the `ICU.PLUGIN_GALINF.FRONT` subtree of the language files
 * (plugin + site overrides made in Admin Next's translation editor, already
 * merged by Grav). English is the reference language: each dictionary is
 * English completed by the target language, so an untranslated key stays
 * readable.
 *
 * Language choice:
 *  - the site declares languages in Grav → active language, imposed;
 *  - otherwise → every available dictionary, and the JS picks one from the
 *    browser. The server CANNOT do it: the gallery is cached with the page
 *    content, so the first visitor would impose their language on everyone
 *    after them.
 */
final class I18n
{
    public const FALLBACK = 'en';
    private const FRONT   = 'ICU.PLUGIN_GALINF.FRONT';

    /**
     * @return array{lang: ?string, dicts: array<string, array<string, string>>}
     *         lang = language imposed by Grav, null = up to the browser
     */
    public static function frontPayload(): array
    {
        $language = Grav::instance()['language'];

        if ($language->enabled()) {
            $lang = (string) ($language->getActive() ?: $language->getDefault() ?: self::FALLBACK);
            return ['lang' => $lang, 'dicts' => [$lang => self::dict($lang)]];
        }

        $dicts = [];
        foreach (self::available() as $lang) {
            $dicts[$lang] = self::dict($lang);
        }
        return ['lang' => null, 'dicts' => $dicts];
    }

    /** A language's dictionary, with English as a per-key fallback. */
    public static function dict(string $lang): array
    {
        $base = self::subtree(self::FALLBACK);
        return $lang === self::FALLBACK ? $base : array_replace($base, self::subtree($lang));
    }

    /** Languages that provide visitor texts (plugin or site). */
    private static function available(): array
    {
        $langs = [];
        foreach (array_keys(Grav::instance()['languages']->toArray()) as $lang) {
            if (self::subtree((string) $lang) !== []) {
                $langs[] = (string) $lang;
            }
        }
        return $langs ?: [self::FALLBACK];
    }

    /** @return array<string, string> */
    private static function subtree(string $lang): array
    {
        $tree = Grav::instance()['language']->getTranslation($lang, self::FRONT, true);
        return \is_array($tree) ? array_filter($tree, 'is_string') : [];
    }
}
