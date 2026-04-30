<?php

declare(strict_types=1);

namespace Martis\Preferences;

use Illuminate\Support\Facades\Log;
use Martis\Enums\AccentColor;

/**
 * Parses the `MARTIS_CUSTOM_ACCENTS` env value into a normalised
 * `[name => hex]` map.
 *
 * Format: comma-separated `name:hex` pairs. Whitespace around the
 * separators is tolerated.
 *
 *     MARTIS_CUSTOM_ACCENTS="edgeflow:#1a73e8, sunset:#ff6b35"
 *
 * Validation rules:
 *   - Name: `[a-z][a-z0-9_-]{1,32}`. Lowercase, alphanumeric + dash /
 *     underscore. Must not collide with a bundled `AccentColor`
 *     enum value (martis, blue, teal, violet, amber, custom).
 *   - Hex: `^#[0-9a-fA-F]{6}$` (6-digit hex with leading `#`).
 *   - Duplicates: last-wins (env-override semantics).
 *   - Invalid entries are silently dropped, but logged at WARNING
 *     so a typo surfaces in `storage/logs/laravel.log`.
 *
 * The output is consumed by:
 *   - `PreferencesResolver::normaliseAccent()` to extend the set of
 *     accepted accent names.
 *   - The SPA boot script in `app.blade.php`, which:
 *       (a) injects an inline `<style>` block defining the CSS
 *           variables for each custom accent;
 *       (b) surfaces the list to the React PreferencesMenu so each
 *           custom accent renders as an extra swatch.
 */
final class CustomAccentsParser
{
    /** Maximum number of custom accents tolerated to keep the inline `<style>` block sensible. */
    public const MAX_ACCENTS = 24;

    /**
     * Parse the raw env / config value into a `[name => hex]` map.
     *
     * @param  string|null  $raw  Raw `MARTIS_CUSTOM_ACCENTS` value.
     * @return array<string, string> Map of accent-name → 6-digit hex including the leading `#`.
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $reserved = array_map(
            static fn (AccentColor $c): string => $c->value,
            AccentColor::cases(),
        );

        $accents = [];
        $entries = array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== '');

        foreach ($entries as $entry) {
            $parts = array_map('trim', explode(':', $entry, 2));
            if (count($parts) !== 2) {
                Log::warning('Martis custom accent: malformed entry (expected `name:hex`)', ['entry' => $entry]);

                continue;
            }

            [$name, $hex] = $parts;

            // Names must already be lowercase. We do NOT strtolower
            // before validation so a consumer who wrote `EdgeFlow`
            // gets a warning instead of silently being normalised
            // to `edgeflow` (the persisted-string mismatch would
            // cause downstream confusion). Length 1-32 to allow
            // single-character experimental names like `a`.
            if (! preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $name)) {
                Log::warning('Martis custom accent: invalid name (must match [a-z][a-z0-9_-]{0,31}, lowercase only)', ['name' => $name]);

                continue;
            }

            if (in_array($name, $reserved, true)) {
                Log::warning('Martis custom accent: name collides with a bundled accent and is ignored', [
                    'name' => $name,
                    'reserved' => $reserved,
                ]);

                continue;
            }

            if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                Log::warning('Martis custom accent: invalid hex (must match #RRGGBB)', [
                    'name' => $name,
                    'hex' => $hex,
                ]);

                continue;
            }

            // Last-wins. Re-assigning preserves env-override semantics
            // when the consumer pastes overlapping entries.
            $accents[$name] = strtolower($hex);

            if (count($accents) >= self::MAX_ACCENTS) {
                Log::warning('Martis custom accent: too many entries; truncating', [
                    'limit' => self::MAX_ACCENTS,
                ]);
                break;
            }
        }

        return $accents;
    }
}
