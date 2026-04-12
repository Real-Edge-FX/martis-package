<?php

namespace Martis\Fields;

/**
 * Badge field — visual read-only indicator that maps model values to colored badges.
 *
 * Laravel Nova v5 parity: Badge field.
 * Reference: https://nova.laravel.com/docs/v5/resources/fields#badge-field
 *
 * Contexts:
 *  - index: yes (display-only)
 *  - detail: yes (display-only)
 *  - create: no (hidden by default — not an editable input)
 *  - update: no (hidden by default — not an editable input)
 *
 * Intentional divergences from Nova:
 *  - Forms hidden by default. Developer can call ->showOnForms() if needed
 *    to render the badge in form contexts (read-only), but Badge should never
 *    be treated as an editable input.
 *
 * API:
 *  - map(['value' => 'type'])         — maps model value to badge type
 *  - types(['type' => 'color'])       — defines visual types (overrides defaults)
 *  - addTypes(['type' => 'color'])    — adds extra types to the defaults
 *  - withIcons()                      — enables icons on badges
 *  - icons(['type' => 'icon'])        — maps types to icons
 *
 * Default types: info (blue), success (green), warning (yellow), danger (red)
 */
class Badge extends Field
{
    /** @var array<string, string> Maps model value → badge type */
    protected array $map = [];

    /**
     * Maps badge type → color class.
     * Defaults aligned with Nova: info/success/warning/danger.
     *
     * @var array<string, string>
     */
    protected array $types = [
        'info' => 'info',
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
    ];

    protected bool $withIcons = false;

    /** @var array<string, string> Maps badge type → icon name */
    protected array $icons = [];

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
     * Example:
     *   ->map(['draft' => 'warning', 'published' => 'success', 'archived' => 'danger'])
     *
     * @param  array<string, string>  $map  value → type
     */
    public function map(array $map): static
    {
        $this->map = $map;

        return $this;
    }

    /**
     * Override the full badge type map (replaces defaults).
     *
     * Example:
     *   ->types(['active' => 'success', 'inactive' => 'danger'])
     *
     * @param  array<string, string>  $types  type → color class
     */
    public function types(array $types): static
    {
        $this->types = $types;

        return $this;
    }

    /**
     * Add extra badge types without replacing the defaults.
     *
     * @param  array<string, string>  $types  type → color class
     */
    public function addTypes(array $types): static
    {
        $this->types = array_merge($this->types, $types);

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
     * Map badge types to icon names.
     *
     * @param  array<string, string>  $icons  type → icon name
     */
    public function icons(array $icons): static
    {
        $this->icons = $icons;
        $this->withIcons = true;

        return $this;
    }

    /** @return array<string, string> */
    public function getMap(): array
    {
        return $this->map;
    }

    /** @return array<string, string> */
    public function getTypes(): array
    {
        return $this->types;
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
        return $this->icons;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'map' => $this->map,
            'types' => $this->types,
            'withIcons' => $this->withIcons,
            'icons' => $this->icons ?: null,
        ], fn (mixed $v): bool => $v !== null);
    }
}
