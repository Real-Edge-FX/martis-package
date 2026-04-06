<?php

namespace Martis;

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
        return new self('martis:drawer-create');
    }

    /** Create override with the built-in drawer-update component. */
    public static function update(): self
    {
        return new self('martis:drawer-update');
    }

    /** Create override with the built-in drawer-detail component. */
    public static function detail(): self
    {
        return new self('martis:drawer-detail');
    }

    // -------------------------------------------------------------------------
    // Chainable configuration — all write to params
    // -------------------------------------------------------------------------

    /** Initial drawer width (default: '520px'). */
    public function width(string $width): static
    {
        $this->params['width'] = $width;

        return $this;
    }

    /** Width when expanded via the expand button (default: '800px'). */
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

    /** Drawer position: 'right' (default) or 'left'. */
    public function position(string $position): static
    {
        $this->params['position'] = $position;

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

    /** Show the resource icon next to the title in the header (default: false). */
    public function showIcon(bool $value = true): static
    {
        $this->params['showIcon'] = $value;

        return $this;
    }
}
