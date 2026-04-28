<?php

namespace Martis\Fields;

/**
 * Auto-incrementing ID field.
 *
 * Shown on index and detail views, hidden from forms (read-only by nature).
 */
class Id extends Field
{
    /** Create an ID field, read-only and hidden from forms. */
    public function __construct(string $attribute = 'id', ?string $label = null)
    {
        parent::__construct($attribute, $label ?? 'ID');
        $this->readonly = true;
        $this->showOnForms = false;
        $this->sortable = true;
    }

    /** {@inheritdoc} */
    public static function make(string $attribute = 'id', ?string $label = null): static
    {
        return new static($attribute, $label);
    }

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'id';
    }

    /** {@inheritdoc} */
    protected function defaultColumnWidth(): array
    {
        return ['width' => '80px'];
    }
}
