<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranslationsController extends MartisController
{
    /**
     * Locale aliases — maps external locale identifiers to internal lang/ folder names.
     * e.g. 'en-US' → 'en', 'pt-BR' is stored as 'pt-BR'
     */
    private const LOCALE_ALIASES = [
        'en-US' => 'en',
        'en_US' => 'en',
        'en-GB' => 'en',
        'en_GB' => 'en',
    ];

    /**
     * Return all translation strings for the given locale as JSON.
     * Falls back to 'en' when the requested locale is not found.
     *
     * Accepts any BCP-47 locale tag (e.g. `pt-PT`, `pt-pt`, `pt_PT`, `en`).
     * The locale is normalised to canonical form (language lowercase, region uppercase)
     * before resolving the lang/ folder, so `pt-pt` and `pt-PT` both resolve correctly.
     *
     * @param  string  $locale  BCP-47 locale identifier (e.g. `pt-PT`, `pt-BR`, `en`)
     *
     * @response array{
     *   actions: array<string, string>,
     *   auth: array<string, string>,
     *   martis: array<string, string>,
     *   messages: array<string, string>,
     *   navigation: array<string, string>,
     *   resources: array<string, string>
     * }
     */
    public function show(Request $request, string $locale): JsonResponse
    {
        $locale = $this->normalizeLocale($locale);

        $langBase = realpath(__DIR__.'/../../../resources/lang');

        if ($langBase === false) {
            return response()->json([]);
        }

        // Prefer published overrides (lang/vendor/martis), then package defaults
        $paths = [
            resource_path("lang/vendor/martis/{$locale}"),
            "{$langBase}/{$locale}",
            "{$langBase}/en",
        ];

        $translations = [];
        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = glob("{$path}/*.php") ?: [];
            foreach ($files as $file) {
                $ns = basename($file, '.php');
                if (! isset($translations[$ns])) {
                    $translations[$ns] = require $file;
                }
            }
        }

        $this->mergeLaravelValidationTranslations($translations, $locale);

        return response()->json($this->convertPlaceholders($translations));
    }

    private function normalizeLocale(string $locale): string
    {
        // Sanitize locale — only alphanumeric, hyphens and underscores
        $locale = preg_replace('/[^a-zA-Z0-9\-_]/', '', $locale) ?? 'en';

        // Apply alias (e.g. en-US → en)
        $locale = self::LOCALE_ALIASES[$locale] ?? $locale;

        // Normalise to BCP-47 canonical form: language lowercase, region uppercase
        // e.g. pt-pt → pt-PT, pt-br → pt-BR, en-us → en-US
        if (preg_match('/^([a-z]{2,3})[-_]([a-zA-Z]{2,4})$/', $locale, $m)) {
            return strtolower($m[1]).'-'.strtoupper($m[2]);
        }

        return strtolower($locale);
    }

    /**
     * Expose Laravel's own validation messages so the frontend can resolve
     * keys like `validation.required` without shipping package-side copies.
     *
     * @param  array<string, mixed>  $translations
     */
    private function mergeLaravelValidationTranslations(array &$translations, string $locale): void
    {
        $fallbackLocale = $this->normalizeLocale((string) config('app.fallback_locale', 'en'));
        $validation = [];

        foreach ([$fallbackLocale, $locale] as $candidate) {
            foreach ($this->localeDirectoryVariants($candidate) as $variant) {
                $file = resource_path("lang/{$variant}/validation.php");
                if (! is_file($file)) {
                    continue;
                }

                $loaded = require $file;
                if (is_array($loaded)) {
                    $validation = array_replace_recursive($validation, $loaded);
                }
            }
        }

        if ($validation !== []) {
            $translations['validation'] = array_replace_recursive(
                is_array($translations['validation'] ?? null) ? $translations['validation'] : [],
                $validation,
            );
        }
    }

    /**
     * Support both `pt-PT` and `pt_PT` style lang directories in the host app.
     *
     * @return array<int, string>
     */
    private function localeDirectoryVariants(string $locale): array
    {
        $variants = [$locale];

        if (str_contains($locale, '-')) {
            $variants[] = str_replace('-', '_', $locale);
        }

        if (str_contains($locale, '_')) {
            $variants[] = str_replace('_', '-', $locale);
        }

        return array_values(array_unique($variants));
    }

    /**
     * Recursively convert Laravel-style :variable placeholders to
     * react-i18next {{variable}} format.
     *
     * @param  array<string, mixed>  $translations
     * @return array<string, mixed>
     */
    private function convertPlaceholders(array $translations): array
    {
        foreach ($translations as $key => $value) {
            if (is_array($value)) {
                $translations[$key] = $this->convertPlaceholders($value);
            } elseif (is_string($value)) {
                $translations[$key] = preg_replace('/:([a-zA-Z_][a-zA-Z0-9_]*)/', '{{$1}}', $value);
            }
        }

        return $translations;
    }
}
