<?php

namespace Martis\Fields;

/**
 * Single-line text input field.
 *
 * Renders as `<input type="text">` in the React frontend.
 * Use for short strings: titles, slugs, emails, URLs.
 */
class Text extends Field
{
    /** {@inheritdoc} */
    public function type(): string
    {
        return 'text';
    }
}
