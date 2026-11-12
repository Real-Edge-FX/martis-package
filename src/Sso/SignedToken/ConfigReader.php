<?php

declare(strict_types=1);

namespace Martis\Sso\SignedToken;

use InvalidArgumentException;

/**
 * Reads the values of the signed-token blocks and refuses the ones that are
 * set but unusable, naming the config key (never a silent fallback).
 */
final class ConfigReader
{
    /** A non-empty string. */
    public static function string(#[\SensitiveParameter] mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw self::unusable($key, 'must be a non-empty string', $value);
        }

        return $value;
    }

    /** A non-empty string, or `$default` when the value is unset (null). */
    public static function stringOr(#[\SensitiveParameter] mixed $value, string $key, string $default): string
    {
        return $value === null ? $default : self::string($value, $key);
    }

    /** A string, or null when the value is unset (null). An empty string is refused. */
    public static function optionalString(#[\SensitiveParameter] mixed $value, string $key): ?string
    {
        return $value === null ? null : self::string($value, $key);
    }

    /**
     * A key that an earlier release read and this one no longer does. Setting
     * it at all (null counts as unset) throws, naming the key and why, so a
     * block written for the old release does not run on a silently changed
     * rule. `environment_claim` is the one so far: since v2.22.0 the claim
     * that carries the environment is always `env` (a locked issuer and an
     * unlocked verifier that read different claim names disagreed with no
     * error at all).
     */
    public static function removed(#[\SensitiveParameter] mixed $value, string $key, string $why): void
    {
        if ($value !== null) {
            throw new InvalidArgumentException("The [{$key}] config value was removed in v2.22.0: {$why}");
        }
    }

    /**
     * An integer from `$min` up, given as an int or as the digits of one (what
     * `env()` returns); `$default` when the value is unset (null).
     */
    public static function int(#[\SensitiveParameter] mixed $value, string $key, int $default, int $min, ?int $max = null): int
    {
        if ($value === null) {
            return $default;
        }

        if (is_string($value) && preg_match('/\A-?[0-9]{1,9}\z/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min || ($max !== null && $value > $max)) {
            throw self::unusable($key, 'must be a whole number of seconds from '.$min.($max !== null ? ' to '.$max : ''), $value);
        }

        return $value;
    }

    public static function bool(#[\SensitiveParameter] mixed $value, string $key): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [null, 0, '0', 'false', ''], true)) {
            return false;
        }

        if (in_array($value, [1, '1', 'true'], true)) {
            return true;
        }

        throw self::unusable($key, 'must be true or false', $value);
    }

    /**
     * A standard base64 value (padded or not) that decodes to exactly
     * `$length` bytes: the raw bytes.
     */
    public static function base64Key(#[\SensitiveParameter] mixed $value, string $key, int $length, string $what): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw self::unusable($key, "must be the base64 of {$what} ({$length} bytes)", $value);
        }

        $value = trim($value);
        $raw = preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $value) === 1 ? base64_decode($value, true) : false;

        if ($raw === false || strlen($raw) !== $length || rtrim(base64_encode($raw), '=') !== rtrim($value, '=')) {
            // The value itself is never put in the message: it may be a secret key.
            throw new InvalidArgumentException("The [{$key}] config value is not the base64 of {$what} ({$length} bytes).");
        }

        return $raw;
    }

    private static function unusable(string $key, string $expected, #[\SensitiveParameter] mixed $value): InvalidArgumentException
    {
        // Only the type of the value: it may be a secret.
        return new InvalidArgumentException("The [{$key}] config value {$expected}, got ".get_debug_type($value).'.');
    }
}
