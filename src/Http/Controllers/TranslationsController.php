<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;

class TranslationsController extends MartisController
{
    /**
     * Return all translation strings for the given locale as JSON.
     *
     * Layered with `array_replace_recursive` so each layer's keys win
     * over the previous one without throwing the rest of the namespace
     * away. Layers, applied bottom → top:
     *
     *   1. Each locale in `martis.locales.fallback_chain` (default `['en']`),
     *      using package defaults for that locale. Earlier entries seed
     *      missing keys for the requested locale.
     *   2. Package defaults for the requested locale itself.
     *   3. Host-app namespaces declared in `martis.locales.app_namespaces`,
     *      probed at `lang/<locale>/<ns>.php`.
     *   4. Consumer overrides published to `lang/vendor/martis/<locale>/`.
     *
     * Result: a consumer can override a single key without copying the
     * whole namespace file, and any namespace the package adds in a
     * future release flows through automatically.
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

        $packageLangBase = realpath(__DIR__.'/../../../resources/lang');

        if ($packageLangBase === false) {
            return response()->json([]);
        }

        $translations = [];

        // Layer 1 — fallback chain seeds the namespace.
        foreach ($this->fallbackChain($locale) as $fallback) {
            $this->mergeFromDirectory($translations, "{$packageLangBase}/{$fallback}");
        }

        // Layer 2 — package defaults for the requested locale.
        $this->mergeFromDirectory($translations, "{$packageLangBase}/{$locale}");

        // Layer 3 — host-app namespaces (lang/<locale>/<ns>.php).
        foreach ($this->appNamespaces() as $namespace) {
            $this->mergeAppNamespace($translations, $locale, $namespace);
        }

        // Layer 4 — consumer overrides. Laravel 11+ ships translations at
        // the top-level `lang/` directory; pre-11 apps still keep them
        // under `resources/lang/`. Probe both so a consumer override
        // wins regardless of host version (and the order — top-level
        // first — matches Laravel's own resolution priority).
        $this->mergeFromDirectory($translations, lang_path("vendor/martis/{$locale}"));
        $this->mergeFromDirectory($translations, resource_path("lang/vendor/martis/{$locale}"));

        $this->mergeLaravelValidationTranslations($translations, $locale);

        return response()->json($this->convertPlaceholders($translations));
    }

    /**
     * Merge every `<ns>.php` file under `$path` into `$translations`,
     * with `array_replace_recursive` so per-key overrides survive.
     *
     * @param  array<string, mixed>  $translations
     */
    private function mergeFromDirectory(array &$translations, string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = glob("{$path}/*.php") ?: [];
        foreach ($files as $file) {
            $namespace = basename($file, '.php');
            $loaded = require $file;
            if (! is_array($loaded)) {
                continue;
            }

            $existing = is_array($translations[$namespace] ?? null) ? $translations[$namespace] : [];
            $translations[$namespace] = array_replace_recursive($existing, $loaded);
        }
    }

    /**
     * Merge a host-app translation namespace (lang/<locale>/<ns>.php).
     *
     * @param  array<string, mixed>  $translations
     */
    private function mergeAppNamespace(array &$translations, string $locale, string $namespace): void
    {
        // Probe the Laravel 11+ top-level `lang/` first, fall back to
        // the legacy `resources/lang/` so consumers on either layout
        // can ship app-namespace overrides.
        $file = lang_path("{$locale}/{$namespace}.php");
        if (! is_file($file)) {
            $file = resource_path("lang/{$locale}/{$namespace}.php");
        }
        if (! is_file($file)) {
            return;
        }

        $loaded = require $file;
        if (! is_array($loaded)) {
            return;
        }

        $existing = is_array($translations[$namespace] ?? null) ? $translations[$namespace] : [];
        $translations[$namespace] = array_replace_recursive($existing, $loaded);
    }

    /**
     * Resolve the fallback chain for a given locale. Empty / invalid
     * config defaults to `['en']` to preserve historical behaviour.
     * The requested locale is filtered out so it never seeds itself.
     *
     * @return list<string>
     */
    private function fallbackChain(string $requestedLocale): array
    {
        $configured = config('martis.locales.fallback_chain', ['en']);
        if (! is_array($configured)) {
            $configured = ['en'];
        }

        $chain = [];
        foreach ($configured as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            $normalized = $this->normalizeLocale($entry);
            if ($normalized === $requestedLocale) {
                continue;
            }
            $chain[] = $normalized;
        }

        return $chain === [] ? [] : $chain;
    }

    /**
     * Resolve the host-app namespaces config (`martis.locales.app_namespaces`).
     * Empty by default so existing consumers see no behaviour change.
     *
     * @return list<string>
     */
    private function appNamespaces(): array
    {
        $configured = config('martis.locales.app_namespaces', []);
        if (! is_array($configured)) {
            return [];
        }

        $namespaces = [];
        foreach ($configured as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            // Block path traversal and namespace collisions on disk.
            if (! preg_match('/^[a-z][a-z0-9_]*$/i', $entry)) {
                continue;
            }
            $namespaces[] = $entry;
        }

        return $namespaces;
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
            // Top-level `lang/` (Laravel 11+) wins over the legacy
            // `resources/lang/` if both exist; same priority chain
            // Laravel's own translator uses.
            $file = lang_path("{$candidate}/validation.php");
            if (! is_file($file)) {
                $file = resource_path("lang/{$candidate}/validation.php");
            }
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
