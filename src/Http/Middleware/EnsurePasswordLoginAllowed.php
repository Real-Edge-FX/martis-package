<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Martis\Auth\PasswordLogin;
use Martis\Support\TranslatedLine;
use Symfony\Component\HttpFoundation\Response;

/**
 * `martis.password_login` (v2.21.0): while `martis.auth.password_login.enabled`
 * is false, the password sign-in routes refuse with a 403 before their
 * controller reads any credential, the same answer for every address. An
 * address of `martis.auth.password_login.break_glass_emails` passes to the
 * normal flow. The throttles of the route run before this.
 *
 * The list must not be an oracle: a break-glass sign-in that does not succeed
 * (an unknown account, a wrong password, a malformed body, the per-account
 * throttle) is answered with the very 403 any other address gets, and nothing
 * the controller flashed to the session is kept. Only a sign-in that
 * succeeded, a legitimate next step after correct credentials (the 2FA
 * challenge, the email verification or password change pages) included,
 * shows the controller's response. The address the list matched on (trimmed,
 * lower-cased) is the address the controller looks the account up by, so
 * `Admin@X.com ` cannot reach another account than `admin@x.com`.
 */
class EnsurePasswordLoginAllowed
{
    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (PasswordLogin::enabled()) {
            return $next($request);
        }

        $email = $request->input('email');

        if (! PasswordLogin::isBreakGlass($email)) {
            return $this->refuse($request);
        }

        $request->merge(['email' => PasswordLogin::normalise((string) $email)]);

        /** @var StatefulGuard $guard */
        $guard = auth()->guard(config('martis.guard'));
        $before = $guard->check() ? $guard->id() : null;

        // The router's pipeline renders an exception of the controller (the
        // validation 422, the per-account 429) as a response, so every failure
        // arrives here as a response and the guard says what happened.
        $response = $next($request);

        $signedIn = $guard->check() && ($before === null || $guard->id() !== $before);

        return $signedIn ? $response : $this->discard($request);
    }

    /** The 403 of a sign-in that did not succeed, without what the controller flashed. */
    private function discard(Request $request): Response
    {
        if ($request->hasSession()) {
            $session = $request->session();
            $session->forget(['errors', '_old_input']);
            $session->put('_flash.new', array_values(array_diff((array) $session->get('_flash.new', []), ['errors', '_old_input'])));
        }

        return $this->refuse($request);
    }

    private function refuse(Request $request): Response
    {
        $message = TranslatedLine::get('martis::auth.password_login_disabled');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, $message);
    }
}
