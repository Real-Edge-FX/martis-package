<?php

namespace Martis\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as AuthUser;
use Martis\Enums\AccentColor;
use Martis\Enums\ThemeMode;
use Martis\Enums\UiDensity;

/**
 * Per-user UI preferences (Task 07.1 ⭐ D2).
 *
 * The model is intentionally small — all business logic lives in
 * {@see \Martis\Preferences\PreferencesResolver}. This class only
 * shapes the persistence layer and exposes typed enum casts.
 *
 * @property int $id
 * @property int $user_id
 * @property ThemeMode $theme
 * @property AccentColor $accent
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
        'accent' => AccentColor::class,
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
            'accent' => $this->accent->value,
            'brandColor' => $this->brand_color,
            'density' => $this->density->value,
            'locale' => $this->locale,
            'reducedMotion' => (bool) $this->reduced_motion,
        ];
    }
}
