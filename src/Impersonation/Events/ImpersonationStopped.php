<?php

declare(strict_types=1);

namespace Martis\Impersonation\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by `ImpersonationManager::stop()` after the auth guard has
 * been restored to the operator. Same pattern as
 * `ImpersonationStarted` — subscribed by the Martis audit listener
 * and available to consumer side-effects.
 */
class ImpersonationStopped
{
    use Dispatchable;

    public function __construct(
        public Authenticatable $operator,
        public Authenticatable $target,
    ) {}
}
