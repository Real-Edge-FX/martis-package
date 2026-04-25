<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Slug field — URL-safe identifier auto-generated from a source attribute.
 *
 * Core API: `from()`, `separator()`.
 *
 * Martis differentials:
 *  - ⭐ Live preview — the React component streams the generated slug in
 *    real time as the source field is typed (i18n-aware transliteration).
 *  - ⭐ Collision detection — client polls the slug-check endpoint while
 *    typing; the backend replies with `{ available, suggestion }`.
 *  - ⭐ Reserved words guard — `->reserved([...])` rejects system-path
 *    slugs before they reach the DB.
 *  - ⭐ Lock after condition — `->lockAfter(fn($model) => $model->is_published)`
 *    freezes the slug once the condition holds (SEO protection).
 */
class Slug extends Field
{
    protected ?string $sourceAttribute = null;

    protected string $separator = '-';

    /** @var list<string> */
    protected array $reserved = [];

    protected ?Closure $lockCondition = null;

    public function type(): string
    {
        return 'slug';
    }

    /** Source attribute that will be slugified as the user types. */
    public function from(string $attribute): static
    {
        $this->sourceAttribute = $attribute;

        return $this;
    }

    /** Separator character used between slug tokens (default: `-`). */
    public function separator(string $separator): static
    {
        $this->separator = $separator;

        return $this;
    }

    /**
     * Block the slug from taking any of the listed values (system paths).
     *
     * @param  list<string>  $reserved
     */
    public function reserved(array $reserved): static
    {
        $this->reserved = array_values(array_map(fn ($v) => (string) $v, $reserved));

        return $this;
    }

    /**
     * Lock the slug once the given condition holds against the model.
     * Useful for preventing edits after publish (SEO).
     */
    public function lockAfter(Closure $condition): static
    {
        $this->lockCondition = $condition;

        return $this;
    }

    public function getSourceAttribute(): ?string
    {
        return $this->sourceAttribute;
    }

    public function getSeparator(): string
    {
        return $this->separator;
    }

    /**
     * @return list<string>
     */
    public function getReserved(): array
    {
        return $this->reserved;
    }

    /**
     * Determine whether the lockAfter condition applies to the given model.
     */
    public function isLockedFor(?Model $model): bool
    {
        if ($this->lockCondition === null || $model === null) {
            return false;
        }

        try {
            return (bool) ($this->lockCondition)($model);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Generate a slug from the given raw value using this field's separator.
     * Unicode-safe: "São Paulo" → "sao-paulo".
     */
    public function generate(string $value): string
    {
        return Str::slug($value, $this->separator);
    }

    public function buildRules(): array
    {
        return array_merge(parent::buildRules(), [
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '' || ! is_string($value)) {
                    return;
                }
                // Input is tolerant: we normalise before rejecting so the user
                // can submit `"Hello World"` and still pass through. The fill()
                // method will persist the canonical form. Only outright-invalid
                // results — empty string or a reserved value — get rejected.
                $generated = $this->generate($value);
                if ($generated === '') {
                    $fail(self::translate('martis::messages.slug_invalid_format', [
                        'attribute' => $attribute,
                    ], "The {$attribute} must contain at least one alphanumeric character."));

                    return;
                }
                if (in_array($generated, $this->reserved, true)) {
                    $fail(self::translate('martis::messages.slug_reserved', [
                        'attribute' => $attribute,
                        'value' => $generated,
                    ], "The {$attribute} value \"{$generated}\" is reserved and cannot be used."));
                }
            },
        ]);
    }

    /**
     * Resolve a translation with a hard-coded English fallback when the
     * translator binding is unavailable (e.g. unit tests running outside the
     * Laravel container).
     *
     * @param  array<string, string>  $replace
     */
    private static function translate(string $key, array $replace, string $fallback): string
    {
        try {
            $translated = trans($key, $replace);
        } catch (\Throwable) {
            return $fallback;
        }
        if (! is_string($translated) || $translated === $key) {
            return $fallback;
        }

        return $translated;
    }

    public function fill(Model $model, mixed $value): void
    {
        // If the slug is locked (e.g. post already published), silently ignore
        // incoming writes — the existing value is preserved.
        if ($this->isLockedFor($model) && $model->exists) {
            return;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                $value = null;
            } else {
                $value = $this->generate($value);
            }
        }

        parent::fill($model, $value);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'sourceAttribute' => $this->sourceAttribute,
            'separator' => $this->separator,
            'reserved' => $this->reserved === [] ? null : $this->reserved,
            'hasLock' => $this->lockCondition !== null ? true : null,
        ], fn ($v) => $v !== null);
    }
}
