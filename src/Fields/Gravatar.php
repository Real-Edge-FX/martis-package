<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\AvatarShape;
use Martis\Enums\GravatarSourceType;

/**
 * Gravatar field — displays avatar from Gravatar service or a direct URL.
 *
 * Generates Gravatar URL from email or uses a direct avatar URL.
 *
 * API:
 *   - squared()  — display with square edges
 *   - rounded()  — display with rounded (circle) edges (default)
 *   - sourceType(GravatarSourceType) — configure input type
 *   - fromEmail()  — shorthand for sourceType(GravatarSourceType::Email)
 *   - fromUrl()    — shorthand for sourceType(GravatarSourceType::Url)
 *
 * Default: uses 'email' column, rounded display.
 *
 * Contexts: index (sim), detail (sim), create/update (configurable).
 */
class Gravatar extends Field
{
    protected AvatarShape $shape = AvatarShape::Rounded;

    protected int $size = 40;

    protected GravatarSourceType $sourceType = GravatarSourceType::Email;

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'gravatar';
    }

    /** {@inheritdoc} */
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
     * Set the source type using the GravatarSourceType enum.
     */
    public function sourceType(GravatarSourceType $type): static
    {
        $this->sourceType = $type;

        return $this;
    }

    /**
     * Shorthand: configure as email source (default).
     */
    public function fromEmail(): static
    {
        return $this->sourceType(GravatarSourceType::Email);
    }

    /**
     * Shorthand: configure as direct URL source.
     */
    public function fromUrl(): static
    {
        return $this->sourceType(GravatarSourceType::Url);
    }

    /**
     * Get shape.
     */
    public function getShape(): AvatarShape
    {
        return $this->shape;
    }

    /**
     * Get size.
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

    /** {@inheritdoc} */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($attribute ?? $this->attribute), $model, $attribute ?? $this->attribute, $this->safeRequest());
        }

        $value = $model->getAttribute($attribute ?? $this->attribute);

        if ($value === null || $value === '') {
            return null;
        }

        if ($this->sourceType === GravatarSourceType::Url) {
            return $value;
        }

        return self::gravatarUrl($value, $this->size);
    }

    /**
     * Resolve the value for form contexts. The gravatar field is purely
     * a read-only preview surface (the avatar itself lives on
     * gravatar.com, not the host DB), so we emit the COMPUTED image URL
     * even on create / update forms — the frontend `<GravatarFieldInput>`
     * needs a URL to render the preview, not the source email/url
     * attribute. The actual stored attribute is preserved on submit
     * because Gravatar fields don't `fill()` anything (the field is
     * always readonly when shown on a form via `showOnForms()`).
     */
    public function resolveForForm(Model $model, ?string $attribute = null): mixed
    {
        return $this->resolve($model, $attribute);
    }

    /** {@inheritdoc} */
    public function fill(Model $model, mixed $value): void
    {
        // sourceType=Email is computed (we never want to write the
        // generated gravatar URL back over the email column). Only
        // sourceType=Url receives writes from the form, which is the
        // case where the consumer stores a direct avatar URL on the
        // model.
        if ($this->sourceType === GravatarSourceType::Email) {
            return;
        }
        if ($value !== null) {
            $model->setAttribute($this->attribute, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'shape' => $this->shape->value,
            'avatarSize' => $this->size,
            'sourceType' => $this->sourceType->value,
        ];
    }
}
