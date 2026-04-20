<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Martis\Enums\BadgeType;
use ReflectionFunction;

/**
 * Badge field — visual read-only indicator that maps model values to colored badges.
 *
 * Contexts:
 *  - index: yes (display-only)
 *  - detail: yes (display-only)
 *  - create: no (hidden by default — not an editable input)
 *  - update: no (hidden by default — not an editable input)
 *
 * Notes:
 *  - Forms hidden by default. Developer can call ->showOnForms() if needed
 *    to render the badge in form contexts (read-only), but Badge should never
 *    be treated as an editable input.
 *
 * ⭐ Martis differentials:
 *  - `map()`, `labels()`, `types()` and `icons()` accept a **zero-arg
 *    Closure** that's resolved once at schema build time (useful for
 *    enum-backed maps or config-driven palettes).
 *  - `map()` and `labels()` also accept a **one-arg Closure** (`fn ($value, $model)`)
 *    that runs per row and returns the badge type / label for that specific
 *    value. Ideal for convention-based i18n lookups, e.g.:
 *    `->labels(fn ($v) => __("statuses.$v"))`.
 *  - `resolveBadgeUsing()` hands full control over the rendered badge per row.
 *    The closure receives the raw value and the model, and must return an
 *    array `['type' => ..., 'label' => ..., 'icon' => ...]`. Any missing key
 *    falls back to the static maps.
 *
 * API:
 *  - map(array|Closure $map)           — value → badge type
 *  - labels(array|Closure $labels)     — value → translated display label
 *  - types(array|Closure $types)       — replaces built-in types (name/hex)
 *  - addTypes(array $types)            — appends to the current types
 *  - withIcons()                       — enables icons on badges
 *  - icons(array|Closure $icons)       — type → icon name
 *  - resolveBadgeUsing(Closure $fn)    — per-row override returning {type,label,icon}
 *
 * Default types: info (blue), success (green), warning (yellow), danger (red)
 */
class Badge extends Field
{
    /** @var array<string, string>|Closure Maps model value → badge type */
    protected array|Closure $map = [];

    /**
     * Maps badge type → color class.
     * Default types: info/success/warning/danger.
     *
     * @var array<string, string>|Closure
     */
    protected array|Closure $types = [
        BadgeType::Info->value => BadgeType::Info->value,
        BadgeType::Success->value => BadgeType::Success->value,
        BadgeType::Warning->value => BadgeType::Warning->value,
        BadgeType::Danger->value => BadgeType::Danger->value,
    ];

    protected bool $withIcons = false;

    /** @var array<string, string>|Closure Maps model value → display label */
    protected array|Closure $labels = [];

    /** @var array<string, string>|Closure Maps badge type → icon name */
    protected array|Closure $icons = [];

    /**
     * Per-row resolver. When set, its return value ({type, label, icon}) is
     * shipped to the frontend verbatim for each row.
     */
    protected ?Closure $resolveBadgeCallback = null;

    /**
     * Type.
     */
    public function type(): string
    {
        return 'badge';
    }

    /**
     * Override make() to default to display-only (hidden from forms).
     * Badge is not an input — it is a read-only visual indicator.
     */
    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromForms();
    }

    /**
     * Map model values to badge types.
     *
     * Three flavours:
     *
     *   1. Array — static map. Shipped as-is in the field schema.
     *      `->map(['draft' => 'warning', 'published' => 'success'])`
     *
     *   2. Zero-arg Closure — resolved once at schema build time. Returns
     *      an array. Ideal for enum-backed palettes or config lookups.
     *      `->map(fn () => StatusEnum::badgeMap())`
     *
     *   3. One-arg Closure ⭐ — resolved per row. Receives the raw value
     *      (and optionally the model as second arg) and returns the badge
     *      type string for that value. Perfect for convention-driven
     *      palettes. Shipped to the frontend via the `__martisBadge`
     *      per-row wrapper, not as a static map.
     *      `->map(fn ($v) => $v === 'active' ? 'success' : 'danger')`
     *
     * @param  array<string, string>|Closure  $map
     */
    public function map(array|Closure $map): static
    {
        $this->map = $map;

        return $this;
    }

    /**
     * Map model values to translated display labels.
     *
     * Supports the same three flavours as {@see self::map()}:
     *   - array of value → label
     *   - zero-arg Closure returning the full array (resolved once)
     *   - one-arg Closure receiving the value and returning its label
     *     (resolved per row) — ideal for `fn ($v) => __("statuses.$v")`
     *
     * @param  array<string, string>|Closure  $labels
     */
    public function labels(array|Closure $labels): static
    {
        $this->labels = $labels;

        return $this;
    }

    /**
     * Override the full badge type map (replaces defaults).
     *
     * Values can be built-in type names (info, success, warning, danger)
     * OR custom hex/rgb colors for automatic palette generation.
     * Accepts an array or a Closure (resolved once at schema build).
     *
     * @param  array<string, string>|Closure  $types
     */
    public function types(array|Closure $types): static
    {
        $this->types = $types;

        return $this;
    }

    /**
     * Add extra badge types without replacing the defaults.
     * (Static setter — use `types(Closure)` for dynamic computation.)
     *
     * @param  array<string, string>  $types  type → color class or hex color
     */
    public function addTypes(array $types): static
    {
        $resolved = is_array($this->types) ? $this->types : ($this->types)();
        $this->types = array_merge($resolved, $types);

        return $this;
    }

    /**
     * Enable icon rendering in badges.
     */
    public function withIcons(): static
    {
        $this->withIcons = true;

        return $this;
    }

    /**
     * Map badge types to icon names. Accepts array or Closure.
     *
     * @param  array<string, string>|Closure  $icons
     */
    public function icons(array|Closure $icons): static
    {
        $this->icons = $icons;
        $this->withIcons = true;

        return $this;
    }

    /**
     * ⭐ Martis differential — per-row badge resolver.
     *
     * When set, the closure runs for every row and its return value
     * (an associative array with any subset of `type`, `label` and `icon`)
     * is shipped verbatim to the frontend for THAT row. Missing keys fall
     * back to the static `map`/`labels`/`icons` lookup.
     *
     * Closure signature: `fn (mixed $value, Model $model): array`
     *
     * Example — VIP clients get a gold badge regardless of their status:
     *   ->resolveBadgeUsing(function (?string $value, Model $model) {
     *       if ($model->is_vip) {
     *           return ['type' => 'vip-gold', 'label' => 'VIP ⭐', 'icon' => 'crown'];
     *       }
     *       return []; // fall back to map()/labels()
     *   })
     */
    public function resolveBadgeUsing(Closure $callback): static
    {
        $this->resolveBadgeCallback = $callback;

        return $this;
    }

    /** @return array<string, string> */
    public function getMap(): array
    {
        return $this->resolveStatic($this->map);
    }

    /** @return array<string, string> */
    public function getTypes(): array
    {
        return $this->resolveStatic($this->types);
    }

    /**
     * Has icons.
     */
    public function hasIcons(): bool
    {
        return $this->withIcons;
    }

    /** @return array<string, string> */
    public function getIcons(): array
    {
        return $this->resolveStatic($this->icons);
    }

    /**
     * Override the per-row display resolver so any of the per-row hooks —
     * `resolveBadgeUsing()`, a per-value `map()` closure or a per-value
     * `labels()` closure — take precedence when set. The frontend detects
     * the `__martisBadge` wrapper and uses its keys directly, falling
     * back to the static maps for any key the resolver didn't fill.
     */
    public function resolveForDisplay(Model $model, ?string $attribute = null): mixed
    {
        $value = parent::resolveForDisplay($model, $attribute);

        $needsPerRow = $this->resolveBadgeCallback !== null
            || $this->isPerValueClosure($this->map)
            || $this->isPerValueClosure($this->labels)
            || $this->isPerValueClosure($this->icons);

        if (! $needsPerRow) {
            return $value;
        }

        $resolved = [];
        if ($this->resolveBadgeCallback !== null) {
            $out = ($this->resolveBadgeCallback)($value, $model);
            if (is_array($out)) {
                $resolved = $out;
            }
        }

        $type = $resolved['type'] ?? null;
        if ($type === null && $this->isPerValueClosure($this->map)) {
            $type = ($this->map)($value, $model);
        }

        $label = $resolved['label'] ?? null;
        if ($label === null && $this->isPerValueClosure($this->labels)) {
            $label = ($this->labels)($value, $model);
        }

        $icon = $resolved['icon'] ?? null;
        if ($icon === null && $this->isPerValueClosure($this->icons)) {
            $icon = ($this->icons)($value, $model);
        }

        if ($type === null && $label === null && $icon === null) {
            return $value;
        }

        return [
            '__martisBadge' => true,
            'value' => $value,
            'type' => $type,
            'label' => $label,
            'icon' => $icon,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        // Per-value closures can't be serialised into a static schema —
        // the frontend receives the resolved payload per row instead via
        // the `__martisBadge` wrapper in resolveForDisplay().
        $map = $this->isPerValueClosure($this->map) ? [] : $this->resolveStatic($this->map);
        $labels = $this->isPerValueClosure($this->labels) ? [] : $this->resolveStatic($this->labels);
        $icons = $this->isPerValueClosure($this->icons) ? [] : $this->resolveStatic($this->icons);

        return array_filter([
            'map' => $map,
            'labels' => $labels !== [] ? $labels : null,
            'types' => $this->resolveStatic($this->types),
            'withIcons' => $this->withIcons,
            'icons' => $icons !== [] ? $icons : null,
        ], fn (mixed $v): bool => $v !== null);
    }

    /**
     * Resolve a static setter that may be an array or a zero-arg Closure.
     * One-arg Closures (per-row resolvers) return [] here — they are
     * dispatched via {@see self::resolveForDisplay()} instead.
     *
     * @param  array<string, string>|Closure  $value
     * @return array<string, string>
     */
    private function resolveStatic(array|Closure $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($this->isPerValueClosure($value)) {
            return [];
        }

        return (array) ($value)();
    }

    /**
     * A Closure is "per-value" when it declares at least one parameter —
     * it is meant to run per row with the resolved value.
     */
    private function isPerValueClosure(array|Closure $value): bool
    {
        if (! $value instanceof Closure) {
            return false;
        }

        return (new ReflectionFunction($value))->getNumberOfParameters() >= 1;
    }

    /** {@inheritDoc} */
    protected function defaultColumnWidth(): array
    {
        return ['width' => '120px'];
    }
}
