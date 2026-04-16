<?php

namespace Martis\Fields;

use Illuminate\Contracts\Validation\Rule;

/**
 * URL field — renders clickable links on index/detail and a text input on forms.
 *
 * Laravel Nova v5 parity: URL field.
 * Reference: https://nova.laravel.com/docs/v5/resources/fields
 *
 * Contexts:
 *  - index: clickable link
 *  - detail: clickable link
 *  - create: editable URL input
 *  - update: editable URL input
 *
 * Link text can be customised via displayUsing() (inherited) or displayText() (static).
 * Computed values are supported via resolveUsing().
 */
class Url extends Field
{
    /** Custom display text for the link (static string). */
    protected ?string $displayText = null;

    /**
     * Type.
     */
    public function type(): string
    {
        return 'url';
    }

    /**
     * Set a static display text for the URL link.
     *
     * For dynamic text based on the model, use the inherited displayUsing() callback.
     * This is a convenience method for simple static text.
     */
    public function displayText(string $text): static
    {
        $this->displayText = $text;

        return $this;
    }

    /**
     * Get display text.
     */
    public function getDisplayText(): ?string
    {
        return $this->displayText;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'displayText' => $this->displayText,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return list<string|Rule>
     */
    public function buildRules(): array
    {
        return array_merge(parent::buildRules(), ['url']);
    }
}
