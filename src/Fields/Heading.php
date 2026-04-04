<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;

/**
 * Heading / section divider field.
 *
 * Not a data field — used to visually group fields in detail/form views.
 * Hidden from index. Does not read or write model attributes.
 */
class Heading extends Field
{
    protected ?string $contentText = null;

    public function __construct(string $attribute, string $label)
    {
        parent::__construct($attribute, $label);
        $this->showOnIndex = false;
    }

    public function type(): string
    {
        return 'heading';
    }

    /**
     * Set the descriptive content text displayed below the heading.
     */
    public function content(string $text): static
    {
        $this->contentText = $text;

        return $this;
    }

    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        return null;
    }

    public function fill(Model $model, mixed $value): void
    {
        // Heading is not a data field — never writes to the model
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'content' => $this->contentText,
        ];
    }
}
