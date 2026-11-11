<?php

declare(strict_types=1);

namespace Martis\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Martis\Sso\SsoException;
use Throwable;

/**
 * Dispatched when an SSO sign-in is refused: the provider failed, the local
 * account may not be adopted, the user is not provisioned, or the external
 * roles map to no local role. Once per refused callback, after the refusal is
 * logged and after any provisioning it would have caused was rolled back, so a
 * listener may write to the same database.
 *
 * `reason` is {@see SsoException::REASON_UNKNOWN} for a plain
 * Throwable, the exception's own reason for a {@see SsoException},
 * and `not_provisioned` or `no_role_match` for the refusals that have no
 * exception.
 *
 * Usage:
 *   Event::listen(SsoFailed::class, function (SsoFailed $event) {
 *       AuditLog::record('sso.failed', ['provider' => $event->provider, 'reason' => $event->reason]);
 *   });
 */
class SsoFailed
{
    use Dispatchable;

    public function __construct(
        /** The provider name of the callback route. */
        public readonly string $provider,

        /** Short snake_case slug of why the sign-in was refused. */
        public readonly string $reason,

        /** What was thrown, when the refusal had an exception. The message is not redacted. */
        public readonly ?Throwable $exception = null,
    ) {}
}
