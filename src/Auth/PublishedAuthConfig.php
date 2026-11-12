<?php

declare(strict_types=1);

namespace Martis\Auth;

/**
 * What `window.MartisConfig.auth` carries (v2.21.0).
 *
 * The login page is public, so the array is an allow-list: exactly the keys
 * the SPA reads (`resources/js/lib/config.ts`, `MartisAuthConfig`), built key
 * by key. A key of `martis.auth` the SPA does not read (the SSO provider
 * blocks with their role maps, tenant ids and logout URLs, column names,
 * throttle values, anything an app adds) never reaches the browser, and an
 * unknown key is never passed through at any level.
 *
 * `copy` is the one verbatim block: it is presentation text by design.
 */
final class PublishedAuthConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'sso' => [
                'enabled' => (bool) config('martis.auth.sso.enabled', false),
                'providers' => self::providers(),
            ],
            // Off while password sign-in is: the SPA hides the link.
            'passwordReset' => [
                'enabled' => PasswordLogin::passwordResetEnabled(),
                'url' => self::url('martis.auth.passwordReset.url'),
            ],
            'registration' => [
                'enabled' => (bool) config('martis.auth.registration.enabled', false),
                'url' => self::url('martis.auth.registration.url'),
            ],
            'controls' => [
                'theme' => (bool) config('martis.auth.controls.theme', true),
                'locale' => (bool) config('martis.auth.controls.locale', true),
            ],
            'magicLink' => [
                'enabled' => (bool) config('martis.auth.magic_link.enabled', false),
            ],
            // The SPA switches the post-register toast from "Sign in" to
            // "Check your inbox" and renders the verify notice page for a
            // visitor without a session.
            'emailVerification' => [
                'enabled' => (bool) config('martis.auth.email_verification.enabled', false),
            ],
            'passwordRequirements' => PasswordPolicy::requirements(),
            'passwordChange' => [
                'enabled' => PasswordChangeRequirement::enabled(),
                'url' => PasswordChangeRequirement::pageUrl(),
            ],
            // Only whether: the break-glass list itself stays on the server.
            // `breakGlass` is true when password sign-in is off AND the list
            // holds an address (v2.23.0): the login page opens the form on
            // `?password_login=1` only then, so an SSO-only panel with no
            // break-glass address never shows a form nobody can use.
            'passwordLogin' => self::passwordLogin(),
            'challenge' => ChallengeConfig::published(),
            // `auth.copy.*` accepts strings or `array<locale, string>`; the
            // React helper `useAuthCopy()` resolves the active locale.
            'copy' => config('martis.auth.copy', [
                'login' => ['title' => null, 'subtitle' => null, 'subtitle_with_sso' => null, 'subtitle_sso_only' => null],
                'register' => ['title' => null, 'subtitle' => null],
                'forgot_password' => ['title' => null, 'subtitle' => null],
                'reset_password' => ['title' => null, 'subtitle' => null],
                'invitation_accept' => ['title' => null, 'subtitle' => null],
            ]),
        ];
    }

    /**
     * `enabled`: the password sign-in switch. `breakGlass`: whether the form
     * may still be opened on `?password_login=1`, that is, password sign-in is
     * off and `break_glass_emails` holds an entry. A boolean only, never the
     * list, and computed without validating it: a malformed list is reported
     * where a break-glass sign-in reads it, not by every page render.
     *
     * @return array{enabled: bool, breakGlass: bool}
     */
    private static function passwordLogin(): array
    {
        $enabled = PasswordLogin::enabled();

        return [
            'enabled' => $enabled,
            'breakGlass' => ! $enabled && PasswordLogin::hasBreakGlassEmails(),
        ];
    }

    /**
     * The enabled providers of an SSO panel, each with `enabled`, `label`,
     * `icon` and the keys its block lists in `public`: for an app whose
     * custom login component draws more than the button strip. A disabled
     * provider is not published.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function providers(): array
    {
        if (! (bool) config('martis.auth.sso.enabled', false)) {
            return [];
        }

        $blocks = config('martis.auth.sso.providers', []);
        $published = [];

        foreach (is_array($blocks) ? $blocks : [] as $name => $block) {
            if (! is_string($name) || ! is_array($block) || ! (bool) ($block['enabled'] ?? false)) {
                continue;
            }

            $entry = [];

            foreach (self::publicKeys($name, $block) as $key) {
                if (array_key_exists($key, $block)) {
                    $entry[$key] = $block[$key];
                }
            }

            // After the listed keys: `public` can add to the entry, never
            // change what the button strip reads.
            $icon = $block['icon'] ?? null;

            $entry['enabled'] = true;
            $entry['label'] = self::label($name, $block['label'] ?? null);
            $entry['icon'] = is_string($icon) ? $icon : null;

            $published[$name] = $entry;
        }

        return $published;
    }

    /**
     * The button label of a provider (v2.22.0): a string, or a map of
     * `locale => string` the SPA resolves against the active language the way
     * it resolves `copy` (`resolveCopyValue()` in `authCopy.ts`). Unset (null)
     * or an empty map is `null`, and the button reads "Continue with <name>".
     * A value that is set but is neither throws, naming the key: it used to be
     * dropped without a word.
     *
     * @return string|array<string, string>|null
     */
    private static function label(string $provider, mixed $label): string|array|null
    {
        if ($label === null || is_string($label)) {
            return $label;
        }

        if (is_array($label)) {
            if ($label === []) {
                return null;
            }

            $isMap = true;
            foreach ($label as $locale => $text) {
                if (! is_string($locale) || ! is_string($text)) {
                    $isMap = false;
                    break;
                }
            }

            if ($isMap) {
                /** @var array<string, string> $label */
                return $label;
            }
        }

        throw AuthConfigurationException::forKey(
            "martis.auth.sso.providers.{$provider}.label",
            'must be a string or a map of locale => string.',
        );
    }

    /**
     * @param  array<mixed>  $block
     * @return list<string>
     */
    private static function publicKeys(string $provider, array $block): array
    {
        if (! array_key_exists('public', $block) || $block['public'] === null) {
            return [];
        }

        $key = "martis.auth.sso.providers.{$provider}.public";

        if (! is_array($block['public'])) {
            throw AuthConfigurationException::forKey($key, 'must be an array of the provider keys to publish.');
        }

        $keys = [];
        foreach ($block['public'] as $entry) {
            if (! is_string($entry) || $entry === '') {
                throw AuthConfigurationException::forKey($key, 'must hold key names (strings) only.');
            }

            // `public` itself is never a published key.
            if ($entry !== 'public') {
                $keys[] = $entry;
            }
        }

        return $keys;
    }

    private static function url(string $key): ?string
    {
        $value = config($key);

        return is_string($value) ? $value : null;
    }
}
