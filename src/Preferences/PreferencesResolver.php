<?php

namespace Martis\Preferences;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Martis\Enums\AccentColor;
use Martis\Enums\ThemeMode;
use Martis\Enums\UiDensity;
use Martis\Models\UserPreference;
use Throwable;

/**
 * Resolve the effective UI preferences for the current request.
 *
 * Resolution chain (highest priority first):
 *   1. URL preset — `?preset=<name>` maps to `config('martis.preferences.presets.<name>')`
 *   2. User row in `martis_user_preferences` (when authenticated)
 *   3. `config('martis.preferences.defaults')`
 *
 * The resolver is tolerant: a missing table, missing config section,
 * or invalid enum value all degrade to the built-in defaults instead
 * of throwing — apps that skip the migration never see errors.
 *
 * A preference the app does not let users change (v2.12.0,
 * `martis.preferences.editable`) is taken from neither (1) nor (2): it
 * always resolves to its default.
 */
class PreferencesResolver
{
    /** The preferences `martis.preferences.editable` may list (v2.12.0). */
    public const EDITABLE_KEYS = ['theme', 'accent', 'density', 'locale', 'reducedMotion'];

    /**
     * @return array{
     *   theme: string,
     *   accent: string,
     *   brandColor: string|null,
     *   density: string,
     *   locale: string,
     *   reducedMotion: bool,
     *   source: string,
     *   preset: string|null,
     * }
     */
    public function resolve(Request $request): array
    {
        $defaults = $this->configDefaults();
        $preset = null;
        $presetName = null;

        // (1) URL preset override
        $presetParam = $request->query('preset');
        if (is_string($presetParam) && $presetParam !== '') {
            $presets = (array) config('martis.preferences.presets', []);
            if (isset($presets[$presetParam]) && is_array($presets[$presetParam])) {
                $preset = $presets[$presetParam];
                $presetName = $presetParam;
            }
        }

        // (2) Persisted user preferences
        $userPrefs = null;
        $user = $request->user();
        if ($user !== null && self::tableExists()) {
            try {
                $row = UserPreference::where('user_id', $user->getAuthIdentifier())->first();
                if ($row !== null) {
                    $userPrefs = $row->toPayload();
                }
            } catch (QueryException|Throwable) {
                $userPrefs = null;
            }
        }

        $editable = $this->editable();
        $merged = array_merge(
            $defaults,
            is_array($userPrefs) ? $this->onlyEditable($userPrefs, $editable) : [],
            is_array($preset) ? $this->normalisePreset($preset, $editable) : [],
        );

        $accent = $this->normaliseAccent($merged['accent'] ?? $defaults['accent']);
        $brandColor = $this->normaliseBrandColor($merged['brandColor'] ?? null);
        // `custom` only means something with a hex: a stored `custom` whose
        // hex is no longer applied (brandColor not editable) falls back.
        if ($accent === AccentColor::Custom->value && $brandColor === null) {
            $accent = $defaults['accent'];
        }

        return [
            'theme' => $this->normaliseTheme($merged['theme'] ?? $defaults['theme']),
            'accent' => $accent,
            'brandColor' => $brandColor,
            'density' => $this->normaliseDensity($merged['density'] ?? $defaults['density']),
            'locale' => $this->normaliseLocale($merged['locale'] ?? null, $defaults['locale']),
            'reducedMotion' => (bool) ($merged['reducedMotion'] ?? $defaults['reducedMotion']),
            'source' => $preset !== null ? 'preset' : ($userPrefs !== null ? 'user' : 'default'),
            'preset' => $presetName,
        ];
    }

    /**
     * The preferences users may change (v2.12.0): `martis.preferences.editable`
     * (`MARTIS_PREFERENCES_EDITABLE`), every one of {@see self::EDITABLE_KEYS}
     * when the key is absent (a config published before v2.12.0). `theme`
     * is dropped when `theme.allowToggle` is false. `brandColor` is added
     * when `accent` is editable and `preferences.allowBrandColor` is on: the
     * hex input is part of the accent choice. An unknown name throws, naming
     * the variable: a set-but-unusable value is never dropped silently.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function editable(): array
    {
        $configured = config('martis.preferences.editable');
        if ($configured === null) {
            // The config file mergeConfigFrom() merges is shallow: a
            // `preferences` block published before v2.12.0 hides the key,
            // and with it the variable.
            if (is_string(Env::get('MARTIS_PREFERENCES_EDITABLE')) && trim((string) Env::get('MARTIS_PREFERENCES_EDITABLE')) !== '') {
                throw new InvalidArgumentException(
                    'MARTIS_PREFERENCES_EDITABLE is set but the published config/martis.php has no `preferences.editable` key, so it is ignored. Add the key from the package config (or republish the config).',
                );
            }
            $configured = self::EDITABLE_KEYS;
        }
        if (! is_array($configured)) {
            throw new InvalidArgumentException(sprintf(
                'MARTIS_PREFERENCES_EDITABLE (martis.preferences.editable): expected a list of preference names, got %s.',
                get_debug_type($configured),
            ));
        }

        $editable = [];
        foreach ($configured as $key) {
            if (! is_string($key) || ! in_array($key, self::EDITABLE_KEYS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'MARTIS_PREFERENCES_EDITABLE (martis.preferences.editable): unknown preference %s (expected any of %s%s).',
                    is_string($key) ? '"'.$key.'"' : get_debug_type($key),
                    implode(', ', self::EDITABLE_KEYS),
                    $key === 'brandColor' ? '; brandColor follows accent and preferences.allowBrandColor' : '',
                ));
            }
            $editable[$key] = true;
        }

        if (config('martis.theme.allowToggle') === false) {
            unset($editable['theme']);
        }
        if (isset($editable['accent']) && (bool) config('martis.preferences.allowBrandColor', false)) {
            $editable['brandColor'] = true;
        }

        return array_keys($editable);
    }

    /**
     * The values of `$values` users may change. A `brandColor` of `null` is
     * part of picking an accent (it clears a hex, the default's included),
     * so it is kept whenever `accent` is editable; a hex needs `brandColor`.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $editable
     * @return array<string, mixed>
     */
    public function onlyEditable(array $values, array $editable): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (in_array($key, $editable, true)
                || ($key === 'brandColor' && $value === null && in_array('accent', $editable, true))) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function configDefaults(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = (array) config('martis.preferences.defaults', []);

        return [
            'theme' => $this->normaliseTheme($raw['theme'] ?? 'dark'),
            'accent' => $this->normaliseAccent($raw['accent'] ?? 'martis'),
            'brandColor' => $this->normaliseBrandColor($raw['brandColor'] ?? null),
            'density' => $this->normaliseDensity($raw['density'] ?? 'comfortable'),
            'locale' => $this->normaliseLocale($raw['locale'] ?? null, $this->availableLocales()[0]),
            'reducedMotion' => (bool) ($raw['reducedMotion'] ?? false),
        ];
    }

    /**
     * The locales the language picker offers: `martis.preferences.locales`
     * (`MARTIS_UI_LOCALES`), or the three bundled ones when the list is
     * empty. A code that does not look like a locale throws (v2.10.0): a
     * set-but-unusable value is never dropped silently.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function availableLocales(): array
    {
        $locales = config('martis.preferences.locales');
        if (is_array($locales) && $locales !== []) {
            $valid = [];
            foreach ($locales as $locale) {
                if (! is_string($locale) || ! LocaleLabelsParser::isValidCode($locale)) {
                    throw new InvalidArgumentException(sprintf(
                        'MARTIS_UI_LOCALES (martis.preferences.locales): invalid locale code %s (expected e.g. `en`, `en_GB`, `pt-BR`).',
                        is_string($locale) ? '"'.$locale.'"' : get_debug_type($locale),
                    ));
                }
                $valid[] = $locale;
            }

            return array_values(array_unique($valid));
        }

        // Fallback — whatever is in resources/lang (or vendor/martis)
        return ['en', 'pt_PT', 'pt_BR'];
    }

    /**
     * Labels the language pickers show: the `martis.preferences.locale_labels`
     * map merged with `MARTIS_UI_LOCALE_LABELS` (`locale_labels_env`), the
     * env entries winning. A locale without a label shows its code.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException When the env value is malformed.
     */
    public function localeLabels(): array
    {
        $labels = [];
        foreach ((array) config('martis.preferences.locale_labels', []) as $code => $label) {
            if (is_string($code) && is_string($label)) {
                $labels[$code] = $label;
            }
        }

        return array_merge($labels, LocaleLabelsParser::parse(
            (string) (config('martis.preferences.locale_labels_env') ?? ''),
        ));
    }

    /**
     * A locale the app offers, else `$fallback` (v2.10.0). A stored locale
     * the app no longer lists, a preset's and a default outside the list
     * all degrade instead of being applied as they are.
     */
    protected function normaliseLocale(mixed $value, string $fallback): string
    {
        return is_string($value) && in_array($value, $this->availableLocales(), true) ? $value : $fallback;
    }

    public static function tableExists(): bool
    {
        try {
            return Schema::hasTable('martis_user_preferences');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The preset's keys users may change. A preset is written by the app,
     * so its `brandColor` follows `accent` alone, not `allowBrandColor`
     * (which governs the hex a user types).
     *
     * @param  array<string, mixed>  $preset
     * @param  list<string>  $editable
     * @return array<string, mixed>
     */
    protected function normalisePreset(array $preset, array $editable): array
    {
        $out = [];
        foreach (['theme', 'accent', 'brandColor', 'density', 'locale', 'reducedMotion'] as $key) {
            $allowed = $key === 'brandColor' ? in_array('accent', $editable, true) : in_array($key, $editable, true);
            if ($allowed && array_key_exists($key, $preset)) {
                $out[$key] = $preset[$key];
            }
        }

        return $out;
    }

    protected function normaliseTheme(mixed $value): string
    {
        $enum = $value instanceof ThemeMode ? $value : ThemeMode::tryFrom((string) $value);

        return ($enum ?? ThemeMode::Dark)->value;
    }

    protected function normaliseAccent(mixed $value): string
    {
        // 1. Built-in enum match (martis, blue, teal, violet, amber, custom).
        $enum = $value instanceof AccentColor ? $value : AccentColor::tryFrom((string) $value);
        if ($enum !== null) {
            return $enum->value;
        }

        // 2. Custom accent registered via `MARTIS_CUSTOM_ACCENTS` (v1.7.0).
        // The consumer can ship arbitrary `name:hex` pairs that extend
        // the built-in set. The parser already validated the names,
        // so a key match here is enough.
        $candidate = is_string($value) ? strtolower($value) : null;
        if ($candidate !== null) {
            $custom = CustomAccentsParser::parse((string) (config('martis.preferences.custom_accents') ?? ''));
            if (array_key_exists($candidate, $custom)) {
                return $candidate;
            }
        }

        // 3. Anything else degrades to the safe default.
        return AccentColor::Martis->value;
    }

    protected function normaliseDensity(mixed $value): string
    {
        $enum = $value instanceof UiDensity ? $value : UiDensity::tryFrom((string) $value);

        return ($enum ?? UiDensity::Comfortable)->value;
    }

    protected function normaliseBrandColor(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        // Accept the four CSS hex forms: #RGB, #RGBA, #RRGGBB, #RRGGBBAA.
        if (! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
            return null;
        }

        return strtolower($value);
    }
}
