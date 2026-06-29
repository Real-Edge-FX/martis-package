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

    /**
     * Badge variant that drives the read-only display style.
     *
     * Values map to design-system tokens:
     *   - `default` (alias `muted`) — `--martis-surface-alt` background +
     *     `--martis-text` foreground (the original look since v1.5.0).
     *   - `accent` — `--martis-accent-bg-light` + `--martis-accent`.
     *     Useful when the slug is visually the row's identity (e.g.
     *     PermissionResource, RoleResource). v1.8.2.
     *   - `success` / `warning` / `danger` — semantic tints for cases
     *     where the slug carries status meaning (rare).
     *   - `custom` — paired with `$badgeCustomColor` (any CSS colour
     *     accepted: hex, rgb, hsl, named). The frontend mixes the
     *     colour with the surface to derive the background tint.
     */
    protected string $badgeVariant = 'default';

    /**
     * Optional custom CSS colour used when `badgeVariant === 'custom'`.
     * Accepts anything `color-mix(in srgb, X 14%, transparent)` accepts.
     */
    protected ?string $badgeCustomColor = null;

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
        // Normalise to lowercase: generate() always produces a lowercase slug
        // (Str::slug), so mixed-case entries like 'Admin' would silently bypass
        // the in_array guard in buildRules() without this normalisation.
        $this->reserved = array_values(array_map(fn ($v) => strtolower((string) $v), $reserved));

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

    /**
     * Set the badge variant used by the read-only display.
     *
     * Use `->badgeColor('#hex')` instead when you want a custom
     * colour — that helper sets `custom` here for you.
     *
     * @param  'default'|'muted'|'accent'|'success'|'warning'|'danger'|'custom'  $variant
     */
    public function badgeVariant(string $variant): static
    {
        $allowed = ['default', 'muted', 'accent', 'success', 'warning', 'danger', 'custom'];
        $normalised = in_array($variant, $allowed, true) ? $variant : 'default';
        // Treat `muted` as an alias for `default` so callers can use
        // either name without surprises.
        $this->badgeVariant = $normalised === 'muted' ? 'default' : $normalised;

        return $this;
    }

    /**
     * Sugar for `->badgeVariant('accent')`. Reads cleanly when the
     * slug IS the row identity (Permission name, Role name, etc).
     */
    public function badgeAccent(): static
    {
        return $this->badgeVariant('accent');
    }

    /**
     * Set a custom CSS colour for the badge. Accepts any value the
     * browser understands as a colour (hex `#3a8a9e`, `rgb(…)`,
     * `hsl(…)`, named, even `oklch(…)`). The frontend tints the
     * background as a 14% mix of this colour with the surface so it
     * stays subtle in both themes; the foreground uses the colour
     * verbatim.
     *
     * Implies `->badgeVariant('custom')`. v1.8.2.
     *
     * Example:
     *
     *     Slug::make('name')->badgeColor('#3a8a9e')
     */
    public function badgeColor(string $color): static
    {
        $this->badgeVariant = 'custom';
        $this->badgeCustomColor = trim($color) === '' ? null : trim($color);

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

    public function buildRules(?string $context = null): array
    {
        return array_merge(parent::buildRules($context), [
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
            // null when default so the schema payload stays minimal —
            // the frontend treats missing as "default". v1.8.2.
            'badgeVariant' => $this->badgeVariant === 'default' ? null : $this->badgeVariant,
            'badgeColor' => $this->badgeVariant === 'custom' ? $this->badgeCustomColor : null,
        ], fn ($v) => $v !== null);
    }
}
