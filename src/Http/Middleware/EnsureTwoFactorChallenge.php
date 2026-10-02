<?php

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Martis\Auth\Listeners\ResetTwoFactorPass;
use Martis\Auth\TwoFactorPass;
use Martis\Profile\TwoFactorService;

/**
 * Middleware that intercepts authenticated requests when 2FA is enabled
 * but not yet challenged in the current session.
 *
 * A user who has 2FA active meets the challenge on every sign-in: the session
 * holds no pass for them ({@see TwoFactorPass}, reset by
 * {@see ResetTwoFactorPass} on every login of the Martis guard).
 * This middleware returns 423 for API requests or redirects to the 2FA
 * challenge SPA page for browser requests.
 *
 * The challenge route itself is registered outside this middleware group so
 * it remains reachable during the pending state. The SPA catch-all that serves
 * the /2fa/challenge page is within this group but is explicitly exempted to
 * prevent redirect loops.
 */
class EnsureTwoFactorChallenge
{
    /** Create the middleware and inject the two-factor service. */
    public function __construct(
        private readonly TwoFactorService $twoFactor,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->requiresChallenge($request)) {
            return $next($request);
        }

        $basePath = trim((string) config('martis.path', 'admin'), '/');

        // Pass through the 2FA challenge SPA page to prevent redirect loops
        if ($request->is("{$basePath}/2fa/challenge")) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'two_factor_required' => true,
                'message' => 'Two-factor authentication is required.',
            ], Response::HTTP_LOCKED); // 423
        }

        // For browser (SPA) requests, redirect to the 2FA challenge page
        return redirect("/{$basePath}/2fa/challenge");
    }

    /**
     * Determine whether the request must complete a 2FA challenge.
     */
    private function requiresChallenge(Request $request): bool
    {
        /** @var string|null $guard */
        $guard = config('martis.guard');
        $user = auth()->guard($guard)->user();

        if (! $user) {
            return false;
        }

        // If 2FA is not enabled on this account, skip
        if (! $this->twoFactor->isEnabled($user)) {
            return false;
        }

        // If the session holds a pass this user earned, skip. A pass of
        // another user (earned earlier in the same browser session) does not
        // count.
        if (TwoFactorPass::holds($request->session(), $user)) {
            return false;
        }

        return true;
    }
}
