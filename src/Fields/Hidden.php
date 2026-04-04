<?php

namespace Martis\Fields;

/**
 * Hidden field.
 *
 * Renders as <input type="hidden"> on forms. Invisible in the UI —
 * never shown on index or detail views. Useful for passing internal
 * values (tenant IDs, default statuses, etc.) through forms without
 * user interaction.
 */
class Hidden extends Field
{
    public function __construct(string $attribute, ?string $label = null)
    {
        parent::__construct($attribute, $label ?? $attribute);
        $this->showOnIndex = false;
        $this->showOnDetail = false;
    }

    public function type(): string
    {
        return 'hidden';
    }
}
