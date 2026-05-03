<?php

namespace Martis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as AuthUser;
use Martis\Enums\AccentColor;
use Martis\Enums\ThemeMode;
use Martis\Enums\UiDensity;
use Martis\Preferences\PreferencesResolver;

/**
 * Per-user UI preferences.
 *
 * The model is intentionally small — all business logic lives in
 * {@see PreferencesResolver}. This class only
 * shapes the persistence layer and exposes typed enum casts.
 *
 * @property int $id
 * @property int $user_id
 * @property ThemeMode $theme
 * @property string $accent
 * @property ?string $brand_color
 * @property UiDensity $density
 * @property string $locale
 * @property bool $reduced_motion
 */
class UserPreference extends Model
{
    protected $table = 'martis_user_preferences';

    protected $guarded = [];

    protected $casts = [
        'theme' => ThemeMode::class,
        // `accent` is a plain string column, not an enum cast (v1.7.4).
        // Casting to AccentColor::class silently dropped any custom
        // accent name registered via MARTIS_CUSTOM_ACCENTS — the cast
        // ran AccentColor::tryFrom() on read, returned null for
        // custom values, and toPayload() crashed on `null->value`. The
        // PreferencesResolver::normaliseAccent() helper validates the
        // string against the union of bundled enum + custom names on
        // every read, so the model layer doesn't need to.
        'density' => UiDensity::class,
        'reduced_motion' => 'boolean',
    ];

    /** @return BelongsTo<AuthUser, $this> */
    public function user(): BelongsTo
    {
        /** @var class-string<AuthUser> $userModel */
        $userModel = config('auth.providers.users.model', AuthUser::class);

        /** @phpstan-ignore-next-line — generic resolved from config at runtime */
        return $this->belongsTo($userModel);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'theme' => $this->theme->value,
            // String column (no enum cast) so custom accent names from
            // MARTIS_CUSTOM_ACCENTS round-trip without being silently
            // nulled. PreferencesResolver::normaliseAccent() guarantees
            // the value is a known accent (bundled or custom) before it
            // reaches the SPA payload.
            'accent' => $this->accent,
            'brandColor' => $this->brand_color,
            'density' => $this->density->value,
            'locale' => $this->locale,
            'reducedMotion' => (bool) $this->reduced_motion,
        ];
    }
}
