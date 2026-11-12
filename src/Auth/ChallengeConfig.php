<?php

declare(strict_types=1);

namespace Martis\Auth;

use Martis\Contracts\ChallengeVerifier;

/**
 * The challenge (captcha) hook of the public auth forms
 * (`martis.auth.challenge`, v2.21.0): which surfaces are covered and which
 * class verifies the token. Martis ships no captcha vendor, only the hook.
 */
final class ChallengeConfig
{
    public const LOGIN = 'login';

    public const FORGOT = 'forgot';

    public const RESET = 'reset';

    public const MAGIC_LINK = 'magic_link';

    public const REGISTER = 'register';

    public const INVITATION = 'invitation';

    /** @var list<string> */
    public const SURFACES = [
        self::LOGIN,
        self::FORGOT,
        self::RESET,
        self::MAGIC_LINK,
        self::REGISTER,
        self::INVITATION,
    ];

    private const KEY = 'martis.auth.challenge';

    /**
     * The switch. An empty string (`env()` of a `KEY=` line) is "unset": the
     * default (off) applies.
     */
    public static function enabled(): bool
    {
        $value = config(self::KEY.'.enabled', false);

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($parsed === null) {
            throw AuthConfigurationException::forKey(self::KEY.'.enabled', 'must be true or false.');
        }

        return $parsed;
    }

    /**
     * The surfaces the challenge covers; throws on a name outside SURFACES.
     *
     * @return list<string>
     */
    public static function appliesTo(): array
    {
        $key = self::KEY.'.applies_to';
        $value = config($key, [self::LOGIN, self::FORGOT, self::MAGIC_LINK, self::REGISTER]);

        if (! is_array($value)) {
            throw AuthConfigurationException::forKey($key, 'must be an array of surface names ('.implode(', ', self::SURFACES).').');
        }

        $surfaces = [];
        foreach ($value as $name) {
            if (! is_string($name) || ! in_array($name, self::SURFACES, true)) {
                $shown = is_string($name) ? "[{$name}]" : 'a '.get_debug_type($name);

                throw AuthConfigurationException::forKey($key, "names {$shown}, which is not a surface. Allowed: ".implode(', ', self::SURFACES).'.');
            }

            $surfaces[] = $name;
        }

        return array_values(array_unique($surfaces));
    }

    /**
     * What the browser may know, never the verifier: `applies_to` is
     * filtered to the known names instead of thrown on, the server enforces
     * (and throws on) the configuration at the first covered request.
     *
     * @return array{enabled: bool, siteKey: string|null, appliesTo: list<string>}
     */
    public static function published(): array
    {
        $enabled = false;
        $applies = [];

        try {
            $enabled = self::enabled();
            $applies = $enabled ? self::appliesTo() : [];
        } catch (AuthConfigurationException) {
            // The middleware throws it on the covered routes.
        }

        $siteKey = config(self::KEY.'.site_key');

        return [
            'enabled' => $enabled,
            'siteKey' => is_string($siteKey) && $siteKey !== '' ? $siteKey : null,
            'appliesTo' => $applies,
        ];
    }

    /**
     * Whether the challenge is on and covers the surface.
     */
    public static function covers(string $surface): bool
    {
        if (! in_array($surface, self::SURFACES, true)) {
            throw new \InvalidArgumentException("Unknown challenge surface [{$surface}]; allowed: ".implode(', ', self::SURFACES).'.');
        }

        if (! self::enabled()) {
            return false;
        }

        // Validated whenever the challenge is on, so a bad name fails on
        // every covered route, not only on the one it spoils.
        $applies = self::appliesTo();
        self::verifierClass();

        return in_array($surface, $applies, true);
    }

    /**
     * Whether the flow behind a surface is switched on. A switched-off flow
     * answers its own 404 or 503 (its controller), so nothing is worth
     * verifying: the middleware leaves it alone. The login surface has no
     * switch of its own here (the password sign-in switch runs before the
     * challenge).
     */
    public static function flowEnabled(string $surface): bool
    {
        return match ($surface) {
            self::FORGOT, self::RESET => PasswordLogin::passwordResetEnabled(),
            self::MAGIC_LINK => (bool) config('martis.auth.magic_link.enabled', false),
            self::REGISTER => (bool) config('martis.auth.registration.enabled', false),
            self::INVITATION => (bool) config('martis.invitations.enabled', false),
            default => true,
        };
    }

    /**
     * The configured verifier class; throws when it is missing, does not
     * exist or does not implement ChallengeVerifier.
     *
     * @return class-string<ChallengeVerifier>
     */
    public static function verifierClass(): string
    {
        $key = self::KEY.'.verifier';
        $class = config($key);

        if (! is_string($class) || $class === '') {
            throw AuthConfigurationException::forKey($key, 'must name a class implementing '.ChallengeVerifier::class.' while the challenge is enabled.');
        }

        if (! class_exists($class)) {
            throw AuthConfigurationException::forKey($key, "names [{$class}], which does not exist.");
        }

        if (! is_subclass_of($class, ChallengeVerifier::class)) {
            throw AuthConfigurationException::forKey($key, "names [{$class}], which does not implement ".ChallengeVerifier::class.'.');
        }

        return $class;
    }
}
