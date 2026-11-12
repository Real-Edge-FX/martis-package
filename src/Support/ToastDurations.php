<?php

namespace Martis\Support;

use InvalidArgumentException;

/**
 * Normalises `martis.toast.durations`, the lifetime of a toast per severity
 * in milliseconds (`null` = stays until closed). A value that is neither
 * `null` nor a positive integer (a string of digits, which is what `env()`
 * yields, counts as one) of at most 2147483647 throws, naming the config
 * key: a browser timer holds no longer delay and fires at once beyond it.
 */
final class ToastDurations
{
    /** The lifetime a severity gets when the config does not set one. */
    public const DEFAULT_MS = 5000;

    /** The longest delay a browser timer holds (2^31 - 1 ms, about 24.8 days). */
    public const MAX_MS = 2147483647;

    /** @var list<string> */
    public const SEVERITIES = ['success', 'info', 'warning', 'error'];

    /**
     * @return array{success: int<1, max>|null, info: int<1, max>|null, warning: int<1, max>|null, error: int<1, max>|null}
     */
    public static function resolve(mixed $configured): array
    {
        if ($configured === null) {
            $configured = [];
        }

        if (! is_array($configured)) {
            throw new InvalidArgumentException(
                'Config key martis.toast.durations must be an array keyed by severity (success, info, warning, error).'
            );
        }

        foreach (array_keys($configured) as $severity) {
            if (! in_array($severity, self::SEVERITIES, true)) {
                throw new InvalidArgumentException(
                    "Config key martis.toast.durations.{$severity} is not a toast severity (success, info, warning, error)."
                );
            }
        }

        $out = [];
        foreach (self::SEVERITIES as $severity) {
            $value = array_key_exists($severity, $configured) ? $configured[$severity] : self::DEFAULT_MS;

            if (is_string($value) && ctype_digit($value)) {
                // `env()` hands a number over as a string; one too long for an
                // int overflows to false and is refused below like any other
                // value beyond the maximum.
                $value = filter_var($value, FILTER_VALIDATE_INT);
            }

            if ($value !== null && (! is_int($value) || $value <= 0 || $value > self::MAX_MS)) {
                throw new InvalidArgumentException(
                    "Config key martis.toast.durations.{$severity} must be null (sticky) or a positive integer number of milliseconds, at most "
                    .self::MAX_MS.' (a digit string such as an env value is accepted).'
                );
            }

            $out[$severity] = $value;
        }

        return $out;
    }

    /**
     * The `toast` block published in `window.MartisConfig`.
     *
     * @param  array<string, mixed>|null  $toast  `config('martis.toast')`
     * @return array<string, mixed>
     */
    public static function config(?array $toast): array
    {
        $toast ??= [];
        $toast['position'] ??= 'bottom-right';
        $toast['durations'] = self::resolve($toast['durations'] ?? null);

        return $toast;
    }
}
