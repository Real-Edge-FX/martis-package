<?php

declare(strict_types=1);

namespace Martis\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by the issuing side of the signed-token SSO (v2.22.0) once per
 * token signed, right after the token is made and before the redirect to the
 * audience's `callback_url`. The issuer's log line `SSO token issued` carries
 * the same `request_id`.
 *
 * Neither the token nor the `state` is on the event: both are secrets in
 * flight, and a listener that stored them would hold something usable.
 *
 * Usage:
 *   Event::listen(SsoTokenIssued::class, function (SsoTokenIssued $event) {
 *       AuditLog::record('sso.issued', $event->user, ['audience' => $event->audience, 'request_id' => $event->requestId]);
 *   });
 */
class SsoTokenIssued
{
    use Dispatchable;

    public function __construct(
        /** The panel user the token was signed for. */
        public readonly Authenticatable $user,

        /** The configured audience of the request (the name in the issue URL). */
        public readonly string $audience,

        /** The request's `X-Request-Id` (at most 128 characters), else a UUID: the same value as the log line's `request_id`. */
        public readonly string $requestId,
    ) {}
}
