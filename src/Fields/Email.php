<?php

namespace Martis\Fields;

/**
 * Email input field.
 *
 * Renders as <input type="email"> in the React frontend.
 * Extends Text with email-specific validation.
 */
class Email extends Text
{
    public function type(): string
    {
        return 'email';
    }

    public function buildRules(): array
    {
        return array_merge(parent::buildRules(), ['email']);
    }
}
