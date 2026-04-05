<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\AvatarShape;

/**
 * Gravatar field — displays avatar from Gravatar service or a direct URL.
 *
 * Paridade com Laravel Nova v5: Gravatar field.
 * Generates Gravatar URL from email or uses a direct avatar URL.
 *
 * Nova-compatible API:
 *   - squared()  — display with square edges
 *   - rounded()  — display with rounded (circle) edges (default)
 *   - sourceType('email'|'url') — configure input type
 *
 * Default: uses 'email' column, rounded display.
 *
 * Contextos: index (sim), detail (sim), create/update (configurable).
 */
class Gravatar extends Field
{
    protected AvatarShape $shape = AvatarShape::Rounded;

    protected int $size = 40;

    /** Source type: 'email' generates Gravatar URL, 'url' uses direct URL */
    protected string $sourceType = 'email';

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
     */
    public function squared(): static
    {
        $this->shape = AvatarShape::Squared;

        return $this;
    }

    /**
     * Display avatar with rounded (circle) edges.
     */
    public function rounded(): static
    {
        $this->shape = AvatarShape::Rounded;

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
     * Set the source type: 'email' (generates Gravatar URL) or 'url' (direct avatar URL).
     */
    public function sourceType(string $type): static
    {
        $this->sourceType = in_array($type, ['email', 'url']) ? $type : 'email';

        return $this;
    }

    /**
     * Shorthand: configure as email source (default).
     */
    public function fromEmail(): static
    {
        return $this->sourceType('email');
    }

    /**
     * Shorthand: configure as direct URL source.
     */
    public function fromUrl(): static
    {
        return $this->sourceType('url');
    }

    public function getShape(): AvatarShape
    {
        return $this->shape;
    }

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
     * Resolve: returns the avatar URL.
     * For 'email' source, generates Gravatar URL.
     * For 'url' source, returns the raw value directly.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($attribute ?? $this->attribute), $model, $attribute ?? $this->attribute);
        }

        $value = $model->getAttribute($attribute ?? $this->attribute);

        if ($value === null || $value === '') {
            return null;
        }

        if ($this->sourceType === 'url') {
            return $value;
        }

        return self::gravatarUrl($value, $this->size);
    }

    /**
     * Gravatar is display-only by default — fill is a no-op.
     * When shown on forms, fill saves the raw value (email or URL).
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
            'shape' => $this->shape->value,
            'avatarSize' => $this->size,
            'sourceType' => $this->sourceType,
        ];
    }
}
