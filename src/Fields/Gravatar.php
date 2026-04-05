<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;

/**
 * Gravatar field — displays avatar from Gravatar service based on email hash.
 *
 * Paridade com Laravel Nova v5: Gravatar field.
 * Read-only, display-only field. Does NOT map to a writable column.
 * Generates Gravatar URL from the model's email attribute.
 *
 * Nova-compatible API:
 *   - squared()  — display with square edges
 *   - rounded()  — display with rounded (circle) edges (default)
 *
 * Default: uses 'email' column, rounded display.
 *
 * Contextos: index (sim), detail (sim), create/update (não — display-only).
 */
class Gravatar extends Field
{
    /** @var 'rounded'|'squared' */
    protected string $shape = 'rounded';

    protected int $size = 40;

    public function type(): string
    {
        return 'gravatar';
    }

    /**
     * Override make() to default to display-only and use 'email' as default attribute.
     */
    public static function make(string $attribute = 'email', ?string $label = null): static
    {
        $label = $label ?? ($attribute === 'email' ? 'Avatar' : ucfirst(str_replace('_', ' ', $attribute)));

        return parent::make($attribute, $label)->hideFromForms();
    }

    /**
     * Display avatar with square edges.
     * Nova-compatible API.
     */
    public function squared(): static
    {
        $this->shape = 'squared';

        return $this;
    }

    /**
     * Display avatar with rounded (circle) edges.
     * Nova-compatible API.
     */
    public function rounded(): static
    {
        $this->shape = 'rounded';

        return $this;
    }

    /**
     * Set the avatar size in pixels.
     */
    public function size(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Get the avatar shape.
     */
    public function getShape(): string
    {
        return $this->shape;
    }

    /**
     * Get the avatar size.
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Generate the Gravatar URL from an email.
     */
    public static function gravatarUrl(string $email, int $size = 40): string
    {
        $hash = md5(strtolower(trim($email)));

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=mp";
    }

    /**
     * Resolve: returns the Gravatar URL (not the raw email).
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($attribute ?? $this->attribute), $model, $attribute ?? $this->attribute);
        }

        $email = $model->getAttribute($attribute ?? $this->attribute);

        if ($email === null || $email === '') {
            return null;
        }

        return self::gravatarUrl($email, $this->size);
    }

    /**
     * Gravatar is display-only — fill is a no-op.
     */
    public function fill(Model $model, mixed $value): void
    {
        // Display-only field — no fill
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'shape' => $this->shape,
            'avatarSize' => $this->size,
        ];
    }
}
