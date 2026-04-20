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
    /**
     * Type.
     */
    public function type(): string
    {
        return 'email';
    }

    /**
     * Build rules.
     */
    public function buildRules(): array
    {
        return array_merge(parent::buildRules(), ['email']);
    }

    /** {@inheritDoc} */
    protected function defaultColumnWidth(): array
    {
        return ['maxWidth' => '280px', 'truncate' => true];
    }
}
