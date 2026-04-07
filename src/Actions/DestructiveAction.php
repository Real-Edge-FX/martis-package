<?php

namespace Martis\Actions;

/**
 * Base class for destructive actions.
 *
 * Nova v5 parity: DestructiveAction extends Action and triggers delete-policy
 * authorization checks. The frontend displays a red confirm button.
 */
class DestructiveAction extends Action
{
    /** {@inheritDoc} */
    public function isDestructive(): bool
    {
        return true;
    }
}
