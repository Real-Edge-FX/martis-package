<?php

namespace Martis\Fields;

/**
 * Badge field — visual read-only indicator that maps model values to colored badges.
 *
 * Paridade com Laravel Nova v5: Badge field.
 * Referência: https://nova.laravel.com/docs/v5/resources/fields#badge-field
 *
 * Contextos:
 *  - index: sim (display-only)
 *  - detail: sim (display-only)
 *  - create: não (oculto por padrão — não é um input editável)
 *  - update: não (oculto por padrão — não é um input editável)
 *
 * Divergências intencionais do Nova:
 *  - Forms ocultados por padrão. Developer pode chamar ->showOnForms() se precisar
 *    renderizar o badge em contextos de formulário (read-only), mas Badge nunca
 *    deve ser tratado como input editável.
 *
 * API:
 *  - map(['value' => 'type'])         — mapeia valor do model para tipo de badge
 *  - types(['type' => 'color'])       — define tipos visuais (sobrescreve defaults)
 *  - addTypes(['type' => 'color'])    — adiciona tipos extras aos defaults
 *  - withIcons()                      — habilita ícones nos badges
 *  - icons(['type' => 'icon'])        — mapeia tipos para ícones
 *
 * Tipos padrão: info (azul), success (verde), warning (amarelo), danger (vermelho)
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
