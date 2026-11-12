<?php

declare(strict_types=1);

namespace Martis\Sso\SignedToken;

use Martis\Events\SsoFailed;
use Martis\Sso\SsoException;

/**
 * The `reason` of the {@see SsoException} the signed-token driver
 * throws for a refused token (v2.21.0). The values reach the log line and the
 * {@see SsoFailed} event of the failed callback.
 */
final class Reason
{
    /** Not a v4.public token: wrong prefix, bad base64url, trailing data, a payload that is not a JSON object. */
    public const MALFORMED = 'malformed';

    /** No configured public key verifies the signature. */
    public const BAD_SIGNATURE = 'bad_signature';

    /** The `iss` claim is not the configured issuer. */
    public const ISSUER = 'issuer';

    /** The `aud` claim is not the configured audience. */
    public const AUDIENCE = 'audience';

    /** `exp` has passed, or `iat` is older than `max_age_seconds`, beyond the clock skew. */
    public const EXPIRED = 'expired';

    /** `iat` is in the future beyond the clock skew. */
    public const NOT_YET_VALID = 'not_yet_valid';

    /** The token comes from another environment than the one this verifier is locked to, or carries an environment while this verifier is not locked (set `environment`). */
    public const ENVIRONMENT = 'environment';

    /** The `state` of the browser session, the callback URL and the token do not all match. */
    public const STATE = 'state';

    /** The `jti` was already used. */
    public const REPLAYED = 'replayed';

    /** A required claim is missing. */
    public const MISSING_CLAIM = 'missing_claim';

    /** The one-time store (the cache) failed, so the token cannot be proven fresh: refused. */
    public const REPLAY_STORE = 'replay_store';
}
