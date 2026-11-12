<?php

declare(strict_types=1);

namespace Martis\Contracts;

use Illuminate\Http\Request;

/**
 * The server half of a challenge (captcha) on a public auth form (v2.21.0).
 *
 * Martis ships no captcha vendor: an app names a class implementing this
 * contract in `martis.auth.challenge.verifier`, and the
 * `martis.challenge:<surface>` route middleware calls it before the
 * controller of every listed surface. The token the browser's `auth:challenge`
 * component produced arrives in the `challenge_token` field of the request.
 *
 * Fail closed: returning false, or throwing, refuses the request with a 422.
 */
interface ChallengeVerifier
{
    /**
     * Whether the request carries a valid challenge token.
     */
    public function verify(Request $request): bool;
}
