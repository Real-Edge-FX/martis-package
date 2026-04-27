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
    public function buildRules(?string $context = null): array
    {
        return array_merge(parent::buildRules($context), ['email']);
    }

    /** {@inheritDoc} */
    protected function defaultColumnWidth(): array
    {
        return ['maxWidth' => '280px', 'truncate' => true];
    }
}
