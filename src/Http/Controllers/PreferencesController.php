<?php

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Martis\Enums\AccentColor;
use Martis\Enums\ThemeMode;
use Martis\Enums\UiDensity;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse as MartisJsonResponse;
use Martis\Models\UserPreference;
use Martis\Preferences\CustomAccentsParser;
use Martis\Preferences\PreferencesResolver;

/**
 * User-preferences API.
 *
 * Routes:
 *   GET    /martis/api/preferences           → current effective payload
 *   PUT    /martis/api/preferences           → upsert the authenticated user's row
 *   DELETE /martis/api/preferences           → reset to config defaults (delete row)
 *
 * All responses use the standard JSON envelope. Guest requests read
 * from the URL preset or config defaults — PUT/DELETE require auth.
 */
class PreferencesController extends MartisController
{
    public function __construct(
        private readonly PreferencesResolver $resolver,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $payload = $this->resolver->resolve($request);

        return MartisJsonResponse::make($payload, meta: [
            'source' => $payload['source'],
            'preset' => $payload['preset'],
            'presetsAvailable' => array_keys((array) config('martis.preferences.presets', [])),
            'locales' => $this->resolver->availableLocales(),
            'accents' => AccentColor::presetValues(),
            'themes' => array_column(ThemeMode::cases(), 'value'),
            'densities' => array_column(UiDensity::cases(), 'value'),
        ])->toResponse();
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return JsonErrorResponse::forbidden('Authentication required to save preferences.')->toResponse();
        }
        if (! PreferencesResolver::tableExists()) {
            return JsonErrorResponse::serverError(
                'Preferences table not migrated. Run php artisan migrate after publishing the martis-preferences-migration tag.',
            )->toResponse();
        }

        // Accept the bundled enum values plus any custom accent names
        // declared via MARTIS_CUSTOM_ACCENTS (v1.7.0+). The
        // CustomAccentsParser already validated the names server-side
        // (lowercase, length, no enum collision), so the union is safe.
        $allowedAccents = array_merge(
            array_column(AccentColor::cases(), 'value'),
            array_keys(CustomAccentsParser::parse(
                (string) (config('martis.preferences.custom_accents') ?? ''),
            )),
        );

        $validator = Validator::make($request->all(), [
            'theme' => ['sometimes', 'string', 'in:'.implode(',', array_column(ThemeMode::cases(), 'value'))],
            'accent' => ['sometimes', 'string', 'in:'.implode(',', $allowedAccents)],
            'brandColor' => ['sometimes', 'nullable', 'string', 'regex:/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'],
            'density' => ['sometimes', 'string', 'in:'.implode(',', array_column(UiDensity::cases(), 'value'))],
            'locale' => ['sometimes', 'string', 'in:'.implode(',', $this->resolver->availableLocales())],
            'reducedMotion' => ['sometimes', 'boolean'],
            'dashboardsLayout' => ['sometimes', 'string', 'in:tabs,sidebar'],
        ]);

        if ($validator->fails()) {
            return JsonErrorResponse::validation($validator->errors()->toArray())->toResponse();
        }

        $data = $validator->validated();
        $attrs = [];
        if (array_key_exists('theme', $data)) {
            $attrs['theme'] = $data['theme'];
        }
        if (array_key_exists('accent', $data)) {
            $attrs['accent'] = $data['accent'];
        }
        if (array_key_exists('brandColor', $data)) {
            $attrs['brand_color'] = $data['brandColor'];
        }
        if (array_key_exists('density', $data)) {
            $attrs['density'] = $data['density'];
        }
        if (array_key_exists('locale', $data)) {
            $attrs['locale'] = $data['locale'];
        }
        if (array_key_exists('reducedMotion', $data)) {
            $attrs['reduced_motion'] = $data['reducedMotion'];
        }
        if (array_key_exists('dashboardsLayout', $data)) {
            $attrs['dashboards_layout'] = $data['dashboardsLayout'];
        }

        UserPreference::updateOrCreate(
            ['user_id' => $user->getAuthIdentifier()],
            $attrs,
        );

        return $this->show($request);
    }

    public function reset(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return JsonErrorResponse::forbidden('Authentication required.')->toResponse();
        }
        if (PreferencesResolver::tableExists()) {
            UserPreference::where('user_id', $user->getAuthIdentifier())->delete();
        }

        return $this->show($request);
    }
}
