<?php

namespace Martis\Fields;

/**
 * Color picker field — HTML5 color input with swatch preview.
 *
 * Contexts:
 *  - index: colored swatch + hex value
 *  - detail: colored swatch + hex value
 *  - create: color picker input
 *  - update: color picker input
 *
 * Stores the raw hex string (e.g., "#ff5733") in the database.
 */
class Color extends Field
{
    /**
     * Type.
     */
    public function type(): string
    {
        return 'color';
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [];
    }
}
