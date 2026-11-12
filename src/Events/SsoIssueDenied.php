<?php

declare(strict_types=1);

namespace Martis\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by the issuing side of the signed-token SSO (v2.22.0) when the
 * issue route refuses to sign: once per refused request, after the warning
 * log line `SSO token issue denied` (same `request_id`) and before the HTTP
 * answer, which does not change. A disabled issuer answers a silent 404 and
 * dispatches nothing; so does an audience name the route's own shape
 * constraint rejects, and so does every refusal of the route's middleware (not
 * signed in, 2FA, email verification, the panel gate, throttle): none of those
 * requests reaches the controller. Listeners run inside the request.
 *
 * `reason` is one of the six constants below. Neither a token nor the `state`
 * is on the event or in the log: nothing was issued, and the state is the
 * caller's secret.
 *
 * Usage:
 *   Event::listen(SsoIssueDenied::class, function (SsoIssueDenied $event) {
 *       AuditLog::record('sso.issue_denied', $event->user, [
 *           'audience' => $event->audience,
 *           'reason' => $event->reason,
 *           'request_id' => $event->requestId,
 *       ]);
 *   });
 */
class SsoIssueDenied
{
    use Dispatchable;

    /** The issuer is on and the audience in the URL is not one of its audiences (404). */
    public const UNKNOWN_AUDIENCE = 'unknown_audience';

    /** The browser's Fetch Metadata says the request is not a top-level navigation: an XHR, an iframe, an image (403). */
    public const NOT_NAVIGATION = 'not_navigation';

    /** The `state` parameter is missing or not 16 to 128 characters of A-Z, a-z, 0-9, `-` and `_` (422). */
    public const INVALID_STATE = 'invalid_state';

    /** An impersonation is active: the guard holds the impersonated user, and no token is signed for them (403). */
    public const IMPERSONATING = 'impersonating';

    /** The audience's `ability` does not allow this user (403). */
    public const FORBIDDEN = 'forbidden';

    /** Signing failed (a user with no email, a `claims` class that throws, an unusable issuer block): nothing was issued, and the exception goes on as a 500. */
    public const CANNOT_SIGN = 'cannot_sign';

    public function __construct(
        /** The signed-in panel user, when the request had one. */
        public readonly ?Authenticatable $user,

        /** The audience of the URL, cut to 64 characters and with anything but printable ASCII replaced by `?`. */
        public readonly string $audience,

        /** One of the constants of this class. */
        public readonly string $reason,

        /** The request's `X-Request-Id` (at most 128 characters), else a UUID: the same value as the log line's `request_id`. */
        public readonly string $requestId,
    ) {}
}
