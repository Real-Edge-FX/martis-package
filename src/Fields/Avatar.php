<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Martis\Enums\AvatarShape;
use Martis\Fields\Concerns\ResolvesInitialsPayload;

/**
 * Avatar — image upload specialised for profile/identity pictures.
 *
 * Extends {@see Image} so every upload helper (`disk`, `storagePath`,
 * `maxSize`, `thumbnail`, …) carries over.
 *
 * ⭐ Martis differentials:
 *  - **Zero-config initials fallback** — when a member has no uploaded
 *    avatar AND the developer didn't declare an explicit `fallback()`,
 *    the field renders coloured initials inline (same deterministic
 *    palette + logic as {@see UiAvatar}, shared via the
 *    {@see ResolvesInitialsPayload} trait). No external service call,
 *    no DB column — matches the look of the topbar / profile surfaces.
 *  - `fallback($url | Closure)` — override the default inline initials
 *    with a custom URL (static or per-row).
 *  - `shape(AvatarShape)` — typed enum (Circle / Rounded / Squared).
 *  - `initialsFrom('attribute')` — override the seed attribute used to
 *    compute the initials (default: `name`).
 *  - `colorFrom('attribute')` — pull the initials background from a
 *    model attribute (brand colour, etc.).
 */
class Avatar extends Image
{
    use ResolvesInitialsPayload;

    protected AvatarShape $shape = AvatarShape::Circle;

    protected string|Closure|null $fallback = null;

    protected ?string $initialsSeedAttribute = null;

    protected ?string $colorFromAttribute = null;

    protected ?Closure $initialsCallback = null;

    public function type(): string
    {
        return 'avatar';
    }

    /** ⭐ Typed shape enum. */
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
     * ⭐ Override the zero-config initials fallback with a custom URL
     * (static or per-row). Closure receives the model.
     */
    public function fallback(string|Closure $source): static
    {
        $this->fallback = $source;

        return $this;
    }

    /** Attribute used as the seed for the initials + palette (default: `name`). */
    public function initialsFrom(string $attribute): static
    {
        $this->initialsSeedAttribute = $attribute;

        return $this;
    }

    /** Pull the initials background colour from a model attribute (hex). */
    public function colorFrom(string $attribute): static
    {
        $this->colorFromAttribute = $attribute;

        return $this;
    }

    /** Customise the initials computation. Closure receives `($seed, $model)`. */
    public function initials(Closure $callback): static
    {
        $this->initialsCallback = $callback;

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

        // Developer-provided URL fallback wins over the built-in initials.
        $fallbackUrl = $this->resolveFallback($model);
        if ($fallbackUrl !== null) {
            return [
                'url' => $fallbackUrl,
                'name' => null,
                'path' => null,
                'thumbnailUrl' => $fallbackUrl,
                'isFallback' => true,
            ];
        }

        // Zero-config default: render coloured initials inline.
        $seedAttr = $this->initialsSeedAttribute ?? 'name';
        $payload = $this->initialsPayload($model, $seedAttr, $this->colorFromAttribute, $this->initialsCallback);

        return [
            'url' => null,
            'name' => null,
            'path' => null,
            'thumbnailUrl' => null,
            'isInitialsFallback' => true,
            'initials' => $payload['initials'],
            'color' => $payload['color'],
            'seed' => $payload['seed'],
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
