<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Martis\Auth\ChallengeConfig;
use Martis\Support\TranslatedLine;

/**
 * `martis.challenge:<surface>` (v2.21.0): the challenge (captcha) hook of a
 * public auth form. While `martis.auth.challenge.enabled` is on and lists the
 * surface, the app's ChallengeVerifier decides before the controller runs.
 * Fail closed: a verifier that returns false or throws (an outage of the
 * captcha vendor included, reported through `report()`) refuses with a 422
 * carrying `errors.challenge_token`.
 *
 * A surface whose own flow is switched off (registration, password reset,
 * magic link, invitations) is not verified: the controller answers its usual
 * disabled response.
 *
 * A configuration that is set but unusable throws, naming the key.
 */
class VerifyChallenge
{
    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next, string $surface): mixed
    {
        if (! ChallengeConfig::covers($surface)) {
            return $next($request);
        }

        // The flow behind the surface is switched off: its controller answers
        // its usual 404 or 503, and no call to the captcha vendor is spent on
        // a request nothing will act on.
        if (! ChallengeConfig::flowEnabled($surface)) {
            return $next($request);
        }

        $class = ChallengeConfig::verifierClass();

        try {
            $passed = app($class)->verify($request) === true;
        } catch (\Throwable $e) {
            report($e);

            $passed = false;
        }

        if (! $passed) {
            throw ValidationException::withMessages([
                'challenge_token' => TranslatedLine::get('martis::auth.challenge_failed'),
            ]);
        }

        return $next($request);
    }
}
