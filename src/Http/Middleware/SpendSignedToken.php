<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Martis\Sso\Providers\SignedTokenProvider;
use Martis\Sso\SsoManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uses the signed token up before the callback's throttle (v2.22.0), then
 * applies that throttle (`SpendSignedToken:<max attempts>,<decay minutes>`,
 * the same limit the callback had as `throttle:<max>,<decay>`).
 *
 * The callback route is rate limited, and a request the limit answers with 429
 * never reaches the provider. An attacker who lured a signed-in user into
 * fetching a token for the attacker's state (state fixation) can fill the
 * victim's throttle bucket first, so that the victim's callback is answered
 * 429, the one-time `jti` is never recorded, and the token stays good, in the
 * URL of an access log, for its lifetime. This middleware spends the token
 * first and only then hands the request to the throttle (it runs the app's
 * `throttle` alias itself, so the order does not depend on Laravel's
 * middleware priority list, which sorts `throttle`), and, only for a provider whose driver is `signed-token`, runs the
 * provider's steps up to and including the `jti` record
 * ({@see SignedTokenProvider::spend()}) and keeps the outcome on the request
 * for the controller. A provider of another driver is not touched, and the
 * provider stays usable on its own when this middleware did not run.
 *
 * A `HEAD` request on the callback of such a provider is answered 405 before
 * anything is read or recorded: a link scanner or a gateway that probes with
 * HEAD must not spend a token meant for the browser.
 *
 * The provider name is the canonical (lower case) one, as in the controller:
 * a path in another letter case is left for the controller to answer 404.
 * The work done here is bounded: a token over the fixed length is refused
 * before it is parsed, and nothing is written for a token whose signature
 * does not verify.
 */
final class SpendSignedToken
{
    public function __construct(private readonly SsoManager $manager) {}

    public function handle(Request $request, Closure $next, int|string $maxAttempts = 20, int|string $decayMinutes = 1): Response
    {
        $name = $request->route('provider');

        if (is_string($name) && $name === strtolower($name)) {
            $driver = $this->manager->driver($name);

            if ($driver instanceof SignedTokenProvider) {
                if ($request->isMethod('HEAD')) {
                    abort(405, '', ['Allow' => 'GET']);
                }

                $driver->spend($request);
            }
        }

        return $this->throttle($request, $next, $maxAttempts, $decayMinutes);
    }

    /**
     * The callback's rate limit, through the app's `throttle` alias (so
     * `throttleWithRedis()` and a replaced alias keep working).
     */
    private function throttle(Request $request, Closure $next, int|string $maxAttempts, int|string $decayMinutes): Response
    {
        $class = app('router')->getMiddleware()['throttle'] ?? ThrottleRequests::class;

        /** @var ThrottleRequests $throttle */
        $throttle = app($class);

        return $throttle->handle($request, $next, $maxAttempts, $decayMinutes);
    }
}
