<?php

declare(strict_types=1);

namespace Martis\Impersonation\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by `ImpersonationManager::start()` after the auth guard has
 * been switched to the target. Subscribed by Martis's audit listener
 * (`Martis\Auth\Listeners\RecordImpersonation`) and available to
 * consumer service providers that want to add their own observers
 * (Slack notification on every impersonation, security webhook, etc).
 */
class ImpersonationStarted
{
    use Dispatchable;

    public function __construct(
        public Authenticatable $operator,
        public Authenticatable $target,
    ) {}
}
