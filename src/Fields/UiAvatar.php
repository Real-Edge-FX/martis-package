<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Martis\Enums\AvatarShape;
use Martis\Fields\Concerns\ResolvesInitialsPayload;

/**
 * UiAvatar — auto-generated initials avatar for records that don't
 * carry a real profile image.
 *
 * The value is computed from the model, never stored. Rendering happens
 * entirely on the client — no external service call, no DB column. The
 * same colour palette + initials logic backs {@see Avatar}'s default
 * fallback and the login / topbar / profile surfaces (via the
 * {@see ResolvesInitialsPayload} trait).
 *
 * ⭐ Martis differentials:
 *  - **Deterministic seed-based colour** from a 16-slot palette hash.
 *  - `colorFrom('brand_color')` — per-row brand colour override.
 *  - `initials(Closure)` — custom initials computation.
 *  - Decoupled seed via `from('other_attr')`.
 */
class UiAvatar extends Field
{
    use ResolvesInitialsPayload;

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
        $payload = $this->initialsPayload($model, $seedAttr, $this->colorFromAttribute, $this->initialsCallback);

        return [
            ...$payload,
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
}
