<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Martis\Enums\AvatarShape;

/**
 * UiAvatar — auto-generated initials avatar for records that don't
 * carry a real profile image.
 *
 * Laravel Nova v5 parity: UiAvatar field.
 * Reference: https://nova.laravel.com/docs/v5/resources/fields#ui-avatar-field
 *
 * The value is computed from the model, never stored. The frontend
 * renders the initials inside a coloured pill whose shape matches the
 * {@see Avatar} field so the two can be used interchangeably as row
 * identity pictures.
 *
 * ⭐ Martis differentials:
 *  - **Deterministic seed-based colour** — the palette slot is derived
 *    from a stable hash of the seed value. Same name → same colour,
 *    no DB column required, works even for freshly-created records.
 *  - `colorFrom('attribute')` — overrides the seed-based palette with
 *    a colour pulled from another model attribute (brand colour, etc.).
 *    Mirrors the API shape the Icon field uses.
 *  - `initials(Closure)` — compute the initials from any Closure. The
 *    default takes the first letter of each whitespace-separated token
 *    (max 2 letters).
 *  - Honours `shape(AvatarShape::*)` for visual parity with Avatar.
 *
 * By default the seed attribute is the field's own attribute. Override
 * with {@see self::from()} when the display column differs from the
 * seed column (e.g. `UiAvatar::make('avatar')->from('name')`).
 */
class UiAvatar extends Field
{
    protected ?string $seedAttribute = null;

    protected AvatarShape $shape = AvatarShape::Circle;

    protected ?Closure $initialsCallback = null;

    protected ?string $colorFromAttribute = null;

    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromForms();
    }

    public function type(): string
    {
        return 'ui_avatar';
    }

    /** Override the attribute used as seed for initials + palette. */
    public function from(string $attribute): static
    {
        $this->seedAttribute = $attribute;

        return $this;
    }

    public function shape(AvatarShape $shape): static
    {
        $this->shape = $shape;

        return $this;
    }

    public function circle(): static
    {
        return $this->shape(AvatarShape::Circle);
    }

    public function rounded(): static
    {
        return $this->shape(AvatarShape::Rounded);
    }

    public function squared(): static
    {
        return $this->shape(AvatarShape::Squared);
    }

    /** ⭐ Custom initials computation. Closure receives `($seed, $model)`. */
    public function initials(Closure $callback): static
    {
        $this->initialsCallback = $callback;

        return $this;
    }

    /** ⭐ Pull the background colour from a model attribute (hex). */
    public function colorFrom(string $attribute): static
    {
        $this->colorFromAttribute = $attribute;

        return $this;
    }

    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $seedAttr = $this->seedAttribute ?? $this->attribute();
        $seed = (string) ($model->getAttribute($seedAttr) ?? '');
        $initials = $this->computeInitials($seed, $model);
        $color = $this->resolveColor($seed, $model);

        return [
            'initials' => $initials,
            'color' => $color,
            'seed' => $seed,
            'shape' => $this->shape->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'shape' => $this->shape->value,
            'colorFromAttribute' => $this->colorFromAttribute,
            'seedAttribute' => $this->seedAttribute,
        ], fn (mixed $v): bool => $v !== null);
    }

    public function getShape(): AvatarShape
    {
        return $this->shape;
    }

    public function getSeedAttribute(): string
    {
        return $this->seedAttribute ?? $this->attribute();
    }

    private function computeInitials(string $seed, Model $model): string
    {
        if ($this->initialsCallback !== null) {
            $result = ($this->initialsCallback)($seed, $model);

            return is_string($result) ? mb_strtoupper(mb_substr($result, 0, 3)) : '';
        }

        $trimmed = trim($seed);
        if ($trimmed === '') {
            return '';
        }

        $tokens = preg_split('/\s+/u', $trimmed) ?: [];
        $first = mb_substr($tokens[0] ?? '', 0, 1);
        $last = count($tokens) > 1 ? mb_substr($tokens[count($tokens) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    private function resolveColor(string $seed, Model $model): string
    {
        if ($this->colorFromAttribute !== null) {
            $custom = $model->getAttribute($this->colorFromAttribute);
            if (is_string($custom) && $custom !== '') {
                return $custom;
            }
        }

        return $this->deterministicColor($seed);
    }

    /**
     * ⭐ Deterministic palette. Stable across requests + migrations, so
     * the same name always renders the same colour. 16-slot palette
     * chosen for visual distinctiveness on light and dark surfaces.
     */
    private function deterministicColor(string $seed): string
    {
        static $palette = [
            '#2563eb', '#7c3aed', '#db2777', '#dc2626',
            '#ea580c', '#ca8a04', '#16a34a', '#0d9488',
            '#0891b2', '#4f46e5', '#c026d3', '#9333ea',
            '#e11d48', '#059669', '#0284c7', '#475569',
        ];

        if ($seed === '') {
            return $palette[0];
        }

        $hash = 0;
        $bytes = unpack('C*', $seed) ?: [];
        foreach ($bytes as $byte) {
            $hash = (($hash << 5) - $hash + $byte) & 0xFFFFFFFF;
        }

        return $palette[abs($hash) % count($palette)];
    }
}
