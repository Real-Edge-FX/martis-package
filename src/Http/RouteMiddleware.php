<?php

namespace Martis\Http;

use InvalidArgumentException;

/**
 * The middleware of the Martis routes, in one place.
 *
 * `routes/martis.php` nests its route groups from these pieces, and a
 * Tool's routes (`Tool::loadRoutes()`, `ToolRoutes::middleware()`) run the
 * stack of a protected API route, `api()`, so a route a tool adds is
 * guarded exactly as the package's own API is. The `martis.api` middleware
 * group holds `api()` too, as built when the application boots.
 */
final class RouteMiddleware
{
    /**
     * Every Martis route, the public ones included: `martis.middleware`.
     *
     * @return list<string>
     */
    public static function base(): array
    {
        return self::configured('martis.middleware', ['web']);
    }

    /**
     * A signed-in user: `martis.auth_middleware`, then the expiry of an
     * impersonation that ran past `martis.impersonation.max_duration_minutes`.
     *
     * @return list<string>
     */
    public static function authenticated(): array
    {
        return [...self::configured('martis.auth_middleware', ['martis.auth']), 'martis.impersonation.duration'];
    }

    /**
     * A signed-in user who passed the 2FA challenge and, when
     * `martis.auth.email_verification.enabled`, verified their email, with
     * the locale of their preferences applied. The 2FA challenge route
     * itself runs without it.
     *
     * @return list<string>
     */
    public static function verified(): array
    {
        return ['martis.2fa', 'martis.locale', 'martis.verified'];
    }

    /**
     * The API rate limit (`martis.throttle.max_attempts` per
     * `martis.throttle.decay_minutes`, per user), or none when
     * `martis.throttle.enabled` is false.
     *
     * @return list<string>
     */
    public static function throttle(): array
    {
        if (! config('martis.throttle.enabled', true)) {
            return [];
        }

        return ['throttle:'.self::limit('martis.throttle.max_attempts', 120).','.self::limit('martis.throttle.decay_minutes', 1)];
    }

    /**
     * The whole stack of a protected API route, in the order it runs.
     *
     * @return list<string>
     */
    public static function api(): array
    {
        return [...self::base(), ...self::authenticated(), ...self::verified(), ...self::throttle()];
    }

    /**
     * A middleware list from config: a name or a list of names. Unset
     * (`null`) is the default; anything else is a configuration error.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function configured(string $key, array $default): array
    {
        $value = config($key) ?? $default;
        $names = is_string($value) ? [$value] : $value;

        if (! is_array($names) || array_filter($names, static fn (mixed $name): bool => ! is_string($name) || $name === '') !== []) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] config value must be a middleware name or a list of middleware names, got %s.',
                $key,
                get_debug_type($value),
            ));
        }

        /** @var list<string> */
        return array_values($names);
    }

    /**
     * A throttle parameter from config, as Laravel's `throttle` middleware
     * reads it: a number, or the name of a user attribute holding one.
     * Unset (`null`) is the default.
     */
    private static function limit(string $key, int $default): string
    {
        $value = config($key) ?? $default;

        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && $value !== '')) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] config value must be a number, or the name of a user attribute holding one, got %s.',
                $key,
                get_debug_type($value),
            ));
        }

        return (string) $value;
    }
}
