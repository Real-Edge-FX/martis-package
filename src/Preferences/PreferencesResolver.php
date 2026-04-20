<?php

namespace Martis\Preferences;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Enums\AccentColor;
use Martis\Enums\ThemeMode;
use Martis\Enums\UiDensity;
use Martis\Models\UserPreference;
use Throwable;

/**
 * Resolve the effective UI preferences for the current request (Task 07.1 ⭐ D2).
 *
 * Resolution chain (highest priority first):
 *   1. URL preset — `?preset=<name>` maps to `config('martis.preferences.presets.<name>')`
 *   2. User row in `martis_user_preferences` (when authenticated)
 *   3. `config('martis.preferences.defaults')`
 *
 * The resolver is tolerant: a missing table, missing config section,
 * or invalid enum value all degrade to the built-in defaults instead
 * of throwing — apps that skip the migration never see errors.
 */
class PreferencesResolver
{
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

        $merged = array_merge(
            $defaults,
            is_array($userPrefs) ? $userPrefs : [],
            is_array($preset) ? $this->normalisePreset($preset) : [],
        );

        return [
            'theme' => $this->normaliseTheme($merged['theme'] ?? $defaults['theme']),
            'accent' => $this->normaliseAccent($merged['accent'] ?? $defaults['accent']),
            'brandColor' => $this->normaliseBrandColor($merged['brandColor'] ?? null),
            'density' => $this->normaliseDensity($merged['density'] ?? $defaults['density']),
            'locale' => is_string($merged['locale'] ?? null) ? $merged['locale'] : $defaults['locale'],
            'reducedMotion' => (bool) ($merged['reducedMotion'] ?? $defaults['reducedMotion']),
            'source' => $preset !== null ? 'preset' : ($userPrefs !== null ? 'user' : 'default'),
            'preset' => $presetName,
        ];
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
            'locale' => is_string($raw['locale'] ?? null) ? $raw['locale'] : 'en',
            'reducedMotion' => (bool) ($raw['reducedMotion'] ?? false),
        ];
    }

    /** @return list<string> */
    public function availableLocales(): array
    {
        /** @var array<int, string>|null $locales */
        $locales = config('martis.preferences.locales');
        if (is_array($locales) && $locales !== []) {
            return array_values(array_filter($locales, 'is_string'));
        }

        // Fallback — whatever is in resources/lang (or vendor/martis)
        return ['en', 'pt_PT', 'pt_BR'];
    }

    public static function tableExists(): bool
    {
        try {
            return Schema::hasTable('martis_user_preferences');
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $preset @return array<string, mixed> */
    protected function normalisePreset(array $preset): array
    {
        $out = [];
        foreach (['theme', 'accent', 'brandColor', 'density', 'locale', 'reducedMotion'] as $key) {
            if (array_key_exists($key, $preset)) {
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
        $enum = $value instanceof AccentColor ? $value : AccentColor::tryFrom((string) $value);

        return ($enum ?? AccentColor::Martis)->value;
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
        if (! preg_match('/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
            return null;
        }

        return strtolower($value);
    }
}
