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
     * Endpoint: GET /martis/api/translations/{locale}
     */
    public function show(Request $request, string $locale): JsonResponse
    {
        // Sanitize locale — only alphanumeric, hyphens and underscores
        $locale = preg_replace('/[^a-zA-Z0-9\-_]/', '', $locale) ?? 'en';

        // Apply alias (e.g. en-US → en)
        $locale = self::LOCALE_ALIASES[$locale] ?? $locale;

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
            if (is_dir($path)) {
                $files = glob("{$path}/*.php") ?: [];
                foreach ($files as $file) {
                    $ns = basename($file, '.php');
                    if (! isset($translations[$ns])) {
                        $translations[$ns] = require $file;
                    }
                }
                break;
            }
        }

        return response()->json($translations);
    }
}
