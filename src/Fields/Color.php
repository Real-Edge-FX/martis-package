<?php

namespace Martis\Fields;

/**
 * Color picker field — HTML5 color input with swatch preview.
 *
 * Paridade com Laravel Nova v5: Color field.
 * Referência: https://nova.laravel.com/docs/v5/resources/fields
 *
 * Contextos:
 *  - index: colored swatch + hex value
 *  - detail: colored swatch + hex value
 *  - create: color picker input
 *  - update: color picker input
 *
 * Stores the raw hex string (e.g., "#ff5733") in the database.
 */
class Color extends Field
{
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
