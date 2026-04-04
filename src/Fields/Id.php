<?php

namespace Martis\Fields;

/**
 * Auto-incrementing ID field.
 *
 * Shown on index and detail views, hidden from forms (read-only by nature).
 * Equivalent to Nova's ID field.
 */
class Id extends Field
{
    public function __construct(string $attribute = 'id', ?string $label = null)
    {
        parent::__construct($attribute, $label ?? 'ID');
        $this->readonly = true;
        $this->showOnForms = false;
        $this->sortable = true;
    }

    public static function make(string $attribute = 'id', ?string $label = null): static
    {
        return new static($attribute, $label);
    }

    public function type(): string
    {
        return 'id';
    }
}
