<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Martis\Enums\AvatarShape;

/**
 * Avatar — image upload specialised for profile/identity pictures.
 *
 * Laravel Nova v5 parity: Avatar field.
 * Reference: https://nova.laravel.com/docs/v5/resources/fields#avatar-field
 *
 * Extends {@see Image} so every upload helper (`disk`, `storagePath`,
 * `maxSize`, `thumbnail`, …) carries over. Where Nova only offers a
 * `rounded(bool)` toggle, Martis adds typed shapes, per-row fallbacks
 * and compose-with-Stack identity pills.
 *
 * ⭐ Martis differentials:
 *  - `fallback($url | Closure)` — per-row fallback URL. Closure receives
 *    the model, so you can derive the fallback from Gravatar, a
 *    UiAvatar route, or any external service. Nova only supports a
 *    static `fallbackUrl`.
 *  - `shape(AvatarShape)` — typed enum (Circle / Rounded / Squared)
 *    instead of Nova's boolean.
 *  - Renders as a compact pill when used inside a Stack — the frontend
 *    detects the context and shrinks the avatar automatically.
 */
class Avatar extends Image
{
    protected AvatarShape $shape = AvatarShape::Circle;

    protected string|Closure|null $fallback = null;

    public function type(): string
    {
        return 'avatar';
    }

    /** ⭐ Martis differential — typed shape enum. */
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

    /**
     * ⭐ Martis differential — per-row fallback URL.
     *
     * Accepts a static URL or a Closure receiving the model that
     * returns the URL to use when the stored avatar is missing.
     */
    public function fallback(string|Closure $source): static
    {
        $this->fallback = $source;

        return $this;
    }

    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $value = parent::resolve($model, $attribute);

        $hasImage = is_array($value)
            && isset($value['url'])
            && $value['url'] !== null
            && $value['url'] !== '';

        if ($hasImage) {
            return $value;
        }

        $fallbackUrl = $this->resolveFallback($model);
        if ($fallbackUrl === null) {
            return $value;
        }

        return [
            'url' => $fallbackUrl,
            'name' => null,
            'path' => null,
            'thumbnailUrl' => $fallbackUrl,
            'isFallback' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_merge(parent::extraAttributes(), [
            'shape' => $this->shape->value,
        ]);
    }

    public function getShape(): AvatarShape
    {
        return $this->shape;
    }

    private function resolveFallback(Model $model): ?string
    {
        if ($this->fallback === null) {
            return null;
        }
        if ($this->fallback instanceof Closure) {
            $result = ($this->fallback)($model);

            return is_string($result) && $result !== '' ? $result : null;
        }

        return $this->fallback !== '' ? $this->fallback : null;
    }
}
