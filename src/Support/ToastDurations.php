<?php

namespace Martis\Support;

use InvalidArgumentException;

/**
 * Normalises `martis.toast.durations`, the lifetime of a toast per severity
 * in milliseconds (`null` = stays until closed). A value that is neither
 * `null` nor a positive integer throws, naming the config key.
 */
final class ToastDurations
{
    /** The lifetime a severity gets when the config does not set one. */
    public const DEFAULT_MS = 5000;

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

            if ($value !== null && (! is_int($value) || $value <= 0)) {
                throw new InvalidArgumentException(
                    "Config key martis.toast.durations.{$severity} must be null (sticky) or a positive integer number of milliseconds."
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
