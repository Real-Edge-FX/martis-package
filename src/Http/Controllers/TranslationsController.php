<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;

class TranslationsController extends MartisController
{
    /**
     * Return all translation strings for the given locale as JSON.
     * Falls back to 'en' when the requested locale is not found.
     *
     * Accepts Laravel locale identifiers such as `en`, `pt_BR`, and `pt_PT`.
     *
     * @param  string  $locale  Laravel locale identifier (e.g. `pt_PT`, `pt_BR`, `en`)
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
    public function show(string $locale): JsonResponse
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
        $locale = preg_replace('/[^a-zA-Z0-9_]/', '', $locale) ?? 'en';

        if (preg_match('/^([a-z]{2,3})_([a-zA-Z]{2,4})$/', $locale, $matches)) {
            return strtolower($matches[1]).'_'.strtoupper($matches[2]);
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
            $file = resource_path("lang/{$candidate}/validation.php");
            if (! is_file($file)) {
                continue;
            }

            $loaded = require $file;
            if (is_array($loaded)) {
                $validation = array_replace_recursive($validation, $loaded);
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
