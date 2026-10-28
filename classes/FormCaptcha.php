<?php

declare(strict_types=1);

namespace Grav\Plugin\Galinf;

use Grav\Common\Grav;

/**
 * Comment captcha chosen by `comments_captcha`. 'galinf' (default) is the
 * plugin's own sum question (galinf.php) and needs nothing else; 'cap',
 * 'turnstile' and 'recaptcha' are delegated to the Form plugin's captcha
 * providers, with the settings of that plugin (keys, Cap difficulty,
 * reCAPTCHA version and score threshold). 'none' asks nothing: only the
 * honeypot and the rate limit remain, the admin's choice.
 */
final class FormCaptcha
{
    public const PROVIDERS = ['galinf', 'cap', 'turnstile', 'recaptcha', 'none'];

    private const FACTORY = 'Grav\Plugin\Form\Captcha\CaptchaFactory';

    /** Field each Form provider reads its token from (reCAPTCHA v2; v3 reads 'token'). */
    private const FIELDS = ['cap' => 'cap-token', 'turnstile' => 'cf-turnstile-response', 'recaptcha' => 'g-recaptcha-response'];

    /** reCAPTCHA v3 action: set by the browser, required by the server. */
    private const RECAPTCHA_ACTION = 'galinf_comment';

    public static function provider(): string
    {
        $name = (string) Grav::instance()['config']->get('plugins.galinf.comments_captcha', 'galinf');
        return \in_array($name, self::PROVIDERS, true) ? $name : 'galinf';
    }

    /**
     * Why the chosen captcha cannot work, or null when it can (always null
     * for 'galinf' and 'none'). Comments from visitors who are not logged in are then
     * refused, and the Maintenance tab says why.
     *
     * @return string|null 'form_missing' (Form plugin missing, disabled or
     *                     too old) | 'turnstile_keys' | 'recaptcha_keys'
     *                     (keys not set in Form)
     */
    public static function issue(): ?string
    {
        $name = self::provider();
        if ($name === 'galinf' || $name === 'none') {
            return null;
        }
        if (self::formProvider($name) === null) {
            return 'form_missing';
        }
        $config = Grav::instance()['config'];
        if ($name !== 'cap'
            && (trim((string) $config->get("plugins.form.{$name}.site_key", '')) === ''
                || trim((string) $config->get("plugins.form.{$name}.secret_key", '')) === '')) {
            return $name . '_keys';
        }
        return null;
    }

    /**
     * What the browser needs to run the widget (meta payload). Cap loads the
     * files shipped with the Form plugin, never a CDN.
     */
    public static function client(string $name): array
    {
        $grav   = Grav::instance();
        $config = $grav['config'];

        if ($name === 'turnstile') {
            return [
                'provider' => 'turnstile',
                'sitekey'  => (string) $config->get('plugins.form.turnstile.site_key', ''),
                'theme'    => (string) $config->get('plugins.form.turnstile.theme', 'auto'),
            ];
        }
        if ($name === 'recaptcha') {
            return [
                'provider' => 'recaptcha',
                'sitekey'  => (string) $config->get('plugins.form.recaptcha.site_key', ''),
                'theme'    => (string) $config->get('plugins.form.recaptcha.theme', 'light'),
                'version'  => self::recaptchaVersion(),
                'action'   => self::RECAPTCHA_ACTION,
            ];
        }

        $base = rtrim((string) $grav['base_url'], '/');
        $dir  = $base . '/' . trim((string) $grav['locator']->findResource('plugins://form/assets/captcha/cap', false), '/');
        return [
            'provider' => 'cap',
            'endpoint' => $base . '/forms-cap/',   // served by the Form plugin
            'script'   => $dir . '/cap.min.js',
            'wasm'     => $dir . '/cap_wasm_bg.wasm',
            'pako'     => $dir . '/pako_inflate.min.js',
        ];
    }

    /**
     * Checks a widget token with the Form provider. Every provider accepts a
     * token once only (Cap deletes it, Cloudflare and Google refuse a
     * duplicate).
     */
    public static function verify(string $name, string $token): bool
    {
        $provider = self::formProvider($name);
        if ($provider === null || $token === '' || \strlen($token) > 4096) {
            return false;
        }
        $field = self::FIELDS[$name];
        // The providers read $_POST before the array they are given: only
        // the token of the JSON body counts.
        unset($_POST[$field]);
        $form = [$field => $token];
        if ($name === 'recaptcha' && self::recaptchaVersion() === '3') {
            // The expected action comes from here, never from the browser.
            $form = ['token' => $token, 'action' => self::RECAPTCHA_ACTION];
        }
        try {
            $result = $provider->validate($form);
        } catch (\Throwable $e) {
            Grav::instance()['log']->error('galinf: ' . $name . ' captcha check failed — ' . $e->getMessage());
            return false;
        }
        return !empty($result['success']);
    }

    /**
     * reCAPTCHA version set in the Form plugin, normalized like it does:
     * '2-checkbox' | '2-invisible' | '3'.
     */
    private static function recaptchaVersion(): string
    {
        $version = (string) Grav::instance()['config']->get('plugins.form.recaptcha.version', '2-checkbox');
        return match ($version) {
            '3', '2-invisible' => $version,
            default            => '2-checkbox',
        };
    }

    /** @return object|null the Form plugin's provider, if usable */
    private static function formProvider(string $name): ?object
    {
        if (!isset(self::FIELDS[$name])
            || !Grav::instance()['config']->get('plugins.form.enabled', false)
            || !class_exists(self::FACTORY)) {
            return null;
        }
        $factory = self::FACTORY;
        if (!$factory::hasProvider($name) && $factory::getProviders() === []) {
            // Request served before the Form plugin registered them.
            $factory::registerDefaultProviders();
        }
        return $factory::getProvider($name);
    }
}
