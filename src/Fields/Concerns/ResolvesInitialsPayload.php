<?php

namespace Martis\Fields\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Martis\Support\Initials;

/**
 * Shared trait for fields that render "initials-in-a-coloured-circle"
 * (Avatar's default fallback, UiAvatar). The letters and the palette slot
 * come from {@see Initials}, which the Topbar and profile avatars use too,
 * so a person gets the same initials on the same colour on every surface.
 *
 * Consumers provide:
 *   - A seed attribute (e.g. 'name') — the source of both initials and
 *     the deterministic palette slot.
 *   - Optional `colorFrom` attribute (e.g. 'brand_color') — when the
 *     model carries an explicit colour, it overrides the palette.
 *   - Optional `initialsCallback` — custom `(seed, model) => string`.
 */
trait ResolvesInitialsPayload
{
    /**
     * Build the payload the frontend uses to render a coloured circle with
     * letters inline — no external service call:
     *
     *   - `initials`: the letters;
     *   - `palette`: the slot of the theme's avatar tokens the circle is
     *     painted with (`var(--martis-avatar-{palette})`), or null when the
     *     `colorFrom` attribute gave the colour;
     *   - `color`: the literal colour, the `colorFrom` value or the slot's
     *     colour in the built-in theme;
     *   - `seed`: the seed attribute's value.
     *
     * @return array{initials: string, color: string, palette: int|null, seed: string}
     */
    protected function initialsPayload(
        Model $model,
        string $seedAttribute,
        ?string $colorFromAttribute = null,
        ?Closure $initialsCallback = null,
    ): array {
        $seed = (string) ($model->getAttribute($seedAttribute) ?? '');
        $initials = $this->computeInitials($seed, $model, $initialsCallback);
        $customColor = $this->customInitialsColor($model, $colorFromAttribute);

        if ($customColor !== null) {
            return ['initials' => $initials, 'color' => $customColor, 'palette' => null, 'seed' => $seed];
        }

        $palette = Initials::paletteSlot($seed);

        return ['initials' => $initials, 'color' => Initials::defaultColor($palette), 'palette' => $palette, 'seed' => $seed];
    }

    protected function computeInitials(string $seed, Model $model, ?Closure $callback = null): string
    {
        if ($callback !== null) {
            $result = $callback($seed, $model);

            return is_string($result) ? mb_strtoupper(mb_substr($result, 0, 3)) : '';
        }

        return Initials::of($seed);
    }

    /** The `colorFrom` attribute's colour, or null when it has none and the palette colours the circle. */
    protected function customInitialsColor(Model $model, ?string $colorFromAttribute = null): ?string
    {
        if ($colorFromAttribute === null) {
            return null;
        }

        $custom = $model->getAttribute($colorFromAttribute);

        return is_string($custom) && $custom !== '' ? $custom : null;
    }
}
