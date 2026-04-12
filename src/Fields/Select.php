<?php

namespace Martis\Fields;

/**
 * Dropdown select field.
 *
 * Renders as a `<select>` in the React frontend.
 * Options may be a flat list of values or an associative label => value map.
 */
class Select extends Field
{
    /** @var list<array{label: string, value: scalar}> */
    protected array $options = [];

    /**
     * Type.
     */
    public function type(): string
    {
        return 'select';
    }

    /**
     * Set the available options for the select.
     *
     * Accepts two formats:
     *   - Associative: ['Active' => 1, 'Inactive' => 0]  (label => value)
     *   - Sequential:  ['draft', 'published', 'archived'] (value used as label too)
     *
     * @param  array<string, scalar>|list<scalar>  $options
     */
    public function options(array $options): static
    {
        $this->options = [];

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                // Sequential: value is both label and stored value
                $this->options[] = ['label' => (string) $value, 'value' => $value];
            } else {
                // Associative: key is label, value is stored value
                $this->options[] = ['label' => $key, 'value' => $value];
            }
        }

        return $this;
    }

    /**
     * Return the normalized options array.
     *
     * @return list<array{label: string, value: scalar}>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return ['options' => $this->options];
    }
}
