<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Martis\Enums\LineVariant;

/**
 * Line — a single text line inside a {@see Stack}.
 *
 * Laravel Nova v5 parity: Line field.
 * Reference: https://nova.laravel.com/docs/v5/resources/fields#stack-field
 *
 * A Line resolves its value the same way any other field does (model
 * attribute or `resolveUsing` callback) and renders as a single styled
 * text node inside the parent Stack.
 *
 * ⭐ Martis differentials:
 *  - Semantic style variants map to `.martis-line-*` classes so custom
 *    themes restyle every Line by overriding a few CSS variables —
 *    developers don't write inline colours or weights.
 *  - `subtitleFrom('attribute')` — sugar to append a second muted line
 *    below the current one pulled from another model attribute, in a
 *    single declaration.
 *
 * Style variants (mutually exclusive):
 *  - `asHeading()` — bold, slightly larger (default for the first line)
 *  - `asBase()` — regular body weight
 *  - `asSmall()` — smaller, secondary-weight
 *  - `asMuted()` — secondary-colour, de-emphasised
 *  - `asCode()` — monospace ⭐
 */
class Line extends Field
{
    /**
     * Style variant applied via the `.martis-line-{variant}` CSS class.
     */
    protected LineVariant $variant = LineVariant::Base;

    /** Optional attribute whose value is rendered as a subtitle below this line. */
    protected ?string $subtitleAttribute = null;

    /** Closure that produces the subtitle instead of pulling an attribute. */
    protected ?Closure $subtitleCallback = null;

    public function type(): string
    {
        return 'line';
    }

    /**
     * Line never appears as a direct form input — it lives inside a Stack
     * and is always display-only.
     */
    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromForms();
    }

    /**
     * Explicitly set the style variant.
     */
    public function variant(LineVariant $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function asHeading(): static
    {
        return $this->variant(LineVariant::Heading);
    }

    public function asBase(): static
    {
        return $this->variant(LineVariant::Base);
    }

    public function asSmall(): static
    {
        return $this->variant(LineVariant::Small);
    }

    public function asMuted(): static
    {
        return $this->variant(LineVariant::Muted);
    }

    /** ⭐ Martis differential — render the line in a monospace typeface. */
    public function asCode(): static
    {
        return $this->variant(LineVariant::Code);
    }

    /**
     * ⭐ Martis differential — append an extra muted line below this one
     * pulled from another model attribute, without having to declare a
     * second Line inside the Stack.
     *
     * Accepts either:
     *   - an attribute name (pulled from the model), OR
     *   - a Closure receiving `($model)` and returning the subtitle string.
     */
    public function subtitleFrom(string|Closure $source): static
    {
        if ($source instanceof Closure) {
            $this->subtitleCallback = $source;
            $this->subtitleAttribute = null;
        } else {
            $this->subtitleAttribute = $source;
            $this->subtitleCallback = null;
        }

        return $this;
    }

    /**
     * Resolve the subtitle for a given model — used by Stack during
     * per-row serialisation. Returns `null` when no subtitle is configured.
     */
    public function resolveSubtitle(Model $model): ?string
    {
        if ($this->subtitleCallback !== null) {
            $value = ($this->subtitleCallback)($model);

            return $value === null ? null : (string) $value;
        }

        if ($this->subtitleAttribute !== null) {
            $value = $model->getAttribute($this->subtitleAttribute);

            return $value === null ? null : (string) $value;
        }

        return null;
    }

    public function getVariant(): LineVariant
    {
        return $this->variant;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'variant' => $this->variant->value,
            'subtitleAttribute' => $this->subtitleAttribute,
            'hasSubtitleCallback' => $this->subtitleCallback !== null,
        ], fn (mixed $v): bool => $v !== null && $v !== false && $v !== '');
    }
}
