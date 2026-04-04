<?php

namespace Martis\Fields;

/**
 * Multi-line text area field.
 *
 * Renders as `<textarea>` in the React frontend.
 * Use for longer text content: descriptions, notes, body copy.
 */
class Textarea extends Field
{
    protected int $rows = 5;

    public function type(): string
    {
        return 'textarea';
    }

    /**
     * Set the number of visible rows for the textarea.
     */
    public function rows(int $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return ['rows' => $this->rows];
    }
}
