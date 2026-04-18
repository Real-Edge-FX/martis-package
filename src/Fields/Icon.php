<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Icon field — renders a Phosphor icon attached to a resource.
 *
 * Fully a Martis differential — Laravel Nova 5 has no icon field. Three
 * modes, each with ⭐ deliberate ergonomics:
 *
 *  1. **Display-only (Mode A)** — the field ignores the model entirely.
 *     Pass a fixed icon name as the second `make()` argument; the field
 *     never reads or writes any column. Used as a visual marker.
 *     ```php
 *     Icon::make('status', 'rocket')->color('success')
 *     ```
 *
 *  2. **Stored (Mode B)** — opt in with `->stored()`. The icon name lives
 *     in a DB column and the field ships a ⭐ visual picker with search +
 *     palette restriction.
 *     ```php
 *     Icon::make('brand_icon')->stored()->palette(['rocket', 'star'])
 *     ```
 *
 *  3. **Computed (Mode C)** — pass a `resolveUsing` callback. No DB, no
 *     picker. Return either a string (`'check'`) or an array
 *     (`['icon' => 'check', 'color' => 'success']`).
 *     ```php
 *     Icon::make('state')->resolveUsing(fn ($m) => $m->is_active ? 'check' : 'x')
 *     ```
 *
 * ⭐ Martis differentials on top of "just show an icon":
 *   - `color()` accepts semantic tokens (`success`, `warning`, `danger`,
 *     `info`, `muted`), CSS vars (`var(--…)`) or arbitrary CSS colors
 *     (hex, rgb, named). Semantic tokens map to `var(--martis-…)`.
 *   - `colorFrom(attribute)` reads the color from a sibling column on the
 *     same model — perfect for per-record branding.
 *   - `map([value => icon|['icon','color']])` — declarative value→icon(+color)
 *     mapping for Mode B, shortcut to avoid `resolveUsing`.
 *   - `palette([...])` — whitelist for the picker; without it the picker
 *     falls back to a curated set of common icons.
 *   - `size(int)` — 12|14|16|20|24|28|32 supported across every context.
 */
class Icon extends Field
{
    protected ?string $fixedIcon = null;

    protected bool $stored = false;

    /** Raw color value (semantic token, CSS var or hex/rgb string). */
    protected ?string $color = null;

    protected ?string $colorFromAttribute = null;

    /** @var array<string, array{icon: string, color?: ?string}> */
    protected array $map = [];

    /** @var list<string> */
    protected array $palette = [];

    protected int $size = 16;

    /** Custom resolver returning `string` (icon) or `['icon' => ..., 'color' => ...]`. */
    protected ?Closure $iconResolver = null;

    /**
     * Create an Icon field.
     *
     * When `$fixedIcon` is provided the field defaults to display-only
     * (Mode A); `->stored()` flips it to persistent (Mode B).
     */
    public static function make(string $attribute, ?string $fixedIcon = null, ?string $label = null): static
    {
        /** @var static $field */
        $field = new static($attribute, $label ?? self::humanize($attribute));
        $field->fixedIcon = $fixedIcon;
        // A fixed icon implies Mode A: hide from forms unless the caller
        // explicitly flips to stored().
        if ($fixedIcon !== null) {
            $field->showOnForms = false;
            $field->showOnCreate = false;
            $field->showOnUpdate = false;
        }

        return $field;
    }

    public function type(): string
    {
        return 'icon';
    }

    /** Flip to Mode B — the icon name is read/written to the DB column. */
    public function stored(bool $value = true): static
    {
        $this->stored = $value;
        if ($value) {
            // Stored icons need form exposure.
            $this->showOnForms = true;
            $this->showOnCreate = null;
            $this->showOnUpdate = null;
        }

        return $this;
    }

    public function isStored(): bool
    {
        return $this->stored;
    }

    /**
     * Set the color. Accepts:
     *  - semantic tokens: `success | warning | danger | info | muted | accent`
     *  - CSS variable reference: `var(--my-color)`
     *  - arbitrary CSS color: hex (`#ec4899`), rgb (`rgb(…)`), named (`red`)
     */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    /**
     * Pull the color from another attribute on the same model (per-record).
     * Takes precedence over `->color()` when the model attribute is set.
     */
    public function colorFrom(string $attribute): static
    {
        $this->colorFromAttribute = $attribute;

        return $this;
    }

    /**
     * Declarative value → icon (+ optional color) map for stored fields.
     *
     * ```php
     * Icon::make('priority')->stored()->map([
     *   'high'   => ['icon' => 'fire', 'color' => 'danger'],
     *   'medium' => ['icon' => 'clock', 'color' => 'warning'],
     *   'low'    => 'check',  // shortcut: icon only
     * ])
     * ```
     *
     * @param  array<string, string|array{icon: string, color?: ?string}>  $map
     */
    public function map(array $map): static
    {
        $normalised = [];
        foreach ($map as $value => $entry) {
            if (is_string($entry)) {
                $normalised[(string) $value] = ['icon' => $entry];
            } elseif (is_array($entry) && isset($entry['icon'])) {
                $normalised[(string) $value] = [
                    'icon' => (string) $entry['icon'],
                    'color' => isset($entry['color']) ? (string) $entry['color'] : null,
                ];
            }
        }
        $this->map = $normalised;

        return $this;
    }

    /** @return array<string, array{icon: string, color?: ?string}> */
    public function getMap(): array
    {
        return $this->map;
    }

    /**
     * Restrict the picker to a whitelist of icon names.
     *
     * @param  list<string>  $palette
     */
    public function palette(array $palette): static
    {
        $this->palette = array_values(array_map('strval', $palette));

        return $this;
    }

    /** @return list<string> */
    public function getPalette(): array
    {
        return $this->palette;
    }

    /** Icon render size in pixels (uniform across contexts). */
    public function size(int $size): static
    {
        $this->size = max(8, min(64, $size));

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Mode C — provide a resolver receiving the model.
     *
     * Return either a string (`'check'`) or an associative array
     * (`['icon' => 'check', 'color' => 'success']`).
     */
    public function icon(Closure $resolver): static
    {
        $this->iconResolver = $resolver;

        return $this;
    }

    /**
     * Resolve the icon+color pair for a given model.
     *
     * @return array{icon: ?string, color: ?string}
     */
    public function resolveForModel(Model $model): array
    {
        // Mode C wins — an explicit resolver replaces every other rule.
        if ($this->iconResolver !== null) {
            $raw = ($this->iconResolver)($model);
            if (is_string($raw)) {
                return ['icon' => $raw, 'color' => $this->color];
            }
            if (is_array($raw) && isset($raw['icon'])) {
                return [
                    'icon' => (string) $raw['icon'],
                    'color' => isset($raw['color']) ? (string) $raw['color'] : $this->color,
                ];
            }
            return ['icon' => null, 'color' => null];
        }

        // Mode A — fixed icon, no model read.
        if ($this->fixedIcon !== null && ! $this->stored) {
            return [
                'icon' => $this->fixedIcon,
                'color' => $this->resolveColor($model),
            ];
        }

        // Mode B — stored.
        /** @var mixed $raw */
        $raw = $model->getAttribute($this->attribute);
        $iconName = is_string($raw) && $raw !== '' ? $raw : $this->fixedIcon;
        $color = $this->color;

        if ($iconName !== null && isset($this->map[$iconName])) {
            $entry = $this->map[$iconName];
            $iconName = $entry['icon'];
            if (isset($entry['color'])) {
                $color = $entry['color'];
            }
        }

        return [
            'icon' => $iconName,
            'color' => $this->resolveColor($model, $color),
        ];
    }

    /**
     * Raw value for form contexts (create/update) — the column string itself.
     * Display contexts (index/detail) call `resolveForDisplay()`, which
     * returns the richer `{icon, color}` pair.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        // Computed (Mode C) has no column — return the icon name only so the
        // form (if ever shown) has something to work with.
        if ($this->iconResolver !== null) {
            $pair = $this->resolveForModel($model);

            return $pair['icon'];
        }
        // Display-only (Mode A) fixed icon — return that name.
        if ($this->fixedIcon !== null && ! $this->stored) {
            return $this->fixedIcon;
        }

        // Mode B — the raw column value (icon name or null).
        return $model->getAttribute($this->attribute);
    }

    /**
     * Display value for index/detail — the `{icon, color}` pair resolved via
     * map / colorFrom / callback.
     *
     * @return array{icon: ?string, color: ?string}
     */
    public function resolveForDisplay(Model $model, ?string $attribute = null): mixed
    {
        return $this->resolveForModel($model);
    }

    public function fill(Model $model, mixed $value): void
    {
        // Only stored icons hydrate the model — every other mode is display
        // only and ignores incoming data silently.
        if ($this->readonly || ! $this->stored) {
            return;
        }
        if (is_string($value) && $value !== '') {
            if ($this->palette !== [] && ! in_array($value, $this->palette, true)) {
                // Silently drop values outside the palette rather than save
                // an icon the frontend refuses to render.
                return;
            }
            $model->setAttribute($this->attribute, $value);
        } elseif ($value === null || $value === '') {
            $model->setAttribute($this->attribute, null);
        }
    }

    /**
     * Compute the effective color for a model, applying colorFrom override.
     */
    private function resolveColor(Model $model, ?string $explicitColor = null): ?string
    {
        if ($this->colorFromAttribute !== null) {
            $value = $model->getAttribute($this->colorFromAttribute);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $explicitColor ?? $this->color;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'stored' => $this->stored ?: null,
            'fixedIcon' => $this->fixedIcon,
            'color' => $this->color,
            'colorFrom' => $this->colorFromAttribute,
            'map' => $this->map === [] ? null : $this->map,
            'palette' => $this->palette === [] ? null : $this->palette,
            'size' => $this->size !== 16 ? $this->size : null,
        ], fn ($v) => $v !== null);
    }

    private static function humanize(string $attribute): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $attribute));
    }
}
