<?php

namespace Martis;

use Martis\Enums\DrawerPosition;

/**
 * Typed override for the built-in Drawer components.
 *
 * Factory methods set the correct component key automatically.
 * Chainable config methods write to params — the frontend DrawerShell
 * reads them from props.params.
 *
 * Usage:
 *
 *     use Martis\DrawerOverride;
 *     use Martis\RedirectAfter;
 *
 *     public function overrideCreate(): ?OverrideContract
 *     {
 *         return DrawerOverride::create()
 *             ->width('520px')
 *             ->subtitle('Manage your projects')
 *             ->allowExpand()
 *             ->redirectAfter(RedirectAfter::INDEX);
 *     }
 */
class DrawerOverride extends Override
{
    // -------------------------------------------------------------------------
    // Factory methods
    // -------------------------------------------------------------------------

    /** Create override with the built-in drawer-create component. */
    public static function create(): self
    {
        return self::makeWithDefaults('martis:drawer-create');
    }

    /** Create override with the built-in drawer-update component. */
    public static function update(): self
    {
        return self::makeWithDefaults('martis:drawer-update');
    }

    /** Create override with the built-in drawer-detail component. */
    public static function detail(): self
    {
        return self::makeWithDefaults('martis:drawer-detail');
    }

    /**
     * Create override with the built-in drawer-quick component — a
     * lightweight read-only quick-look. Defaults to a narrow drawer
     * (480px) since the use case is a focused snapshot, not full
     * record review. Override with `->width(...)` if needed.
     */
    public static function quick(): self
    {
        return self::makeWithDefaults('martis:drawer-quick')
            ->width('480px');
    }

    /**
     * Build a drawer override with the package-wide width defaults pre-applied.
     * Explicit `->width()` / `->expandedWidth()` calls still win.
     */
    private static function makeWithDefaults(string $component): self
    {
        $instance = new self($component);
        $instance->params['width'] = (string) config('martis.drawer.width', '720px');
        $instance->params['expandedWidth'] = (string) config('martis.drawer.expanded_width', '960px');

        return $instance;
    }

    // -------------------------------------------------------------------------
    // Chainable configuration — all write to params
    // -------------------------------------------------------------------------

    /** Initial drawer width (default: '720px', inherits `config('martis.drawer.width')`). */
    public function width(string $width): static
    {
        $this->params['width'] = $width;

        return $this;
    }

    /** Width when expanded via the expand button (default: '960px', inherits `config('martis.drawer.expanded_width')`). */
    public function expandedWidth(string $width): static
    {
        $this->params['expandedWidth'] = $width;

        return $this;
    }

    /** Show the expand/collapse button in the header (default: true). */
    public function allowExpand(bool $value = true): static
    {
        $this->params['allowExpand'] = $value;

        return $this;
    }

    /** Show the fullscreen toggle button in the header (default: true). */
    public function allowFullscreen(bool $value = true): static
    {
        $this->params['allowFullscreen'] = $value;

        return $this;
    }

    /** Show the close (×) button in the header (default: true). */
    public function showCloseButton(bool $value = true): static
    {
        $this->params['showCloseButton'] = $value;

        return $this;
    }

    /** Drawer position. */
    public function position(DrawerPosition $position): static
    {
        $this->params['position'] = $position->value;

        return $this;
    }

    /** Show a dark backdrop overlay behind the drawer (default: true). */
    public function backdrop(bool $value = true): static
    {
        $this->params['backdrop'] = $value;

        return $this;
    }

    /** Custom subtitle displayed below the title in the header. */
    public function subtitle(string $subtitle): static
    {
        $this->params['subtitle'] = $subtitle;

        return $this;
    }

    /**
     * Show an icon next to the title in the header.
     *
     * Pass true to use the resource's own icon, or a Phosphor icon name
     * (e.g. 'rocket', 'star') to use a custom icon.
     */
    public function showIcon(bool|string $value = true): static
    {
        if (is_string($value)) {
            $this->params['showIcon'] = true;
            $this->params['icon'] = $value;
        } else {
            $this->params['showIcon'] = $value;
        }

        return $this;
    }

    /** Set a custom color for the icon badge (any CSS color value). */
    public function iconColor(string $color): static
    {
        $this->params['iconColor'] = $color;

        return $this;
    }
}
