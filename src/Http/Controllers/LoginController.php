<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Martis\Http\Controllers\Concerns\AuthenticatesWithRememberMe;

class LoginController extends MartisController
{
    use AuthenticatesWithRememberMe;

    /** Render the login page, or redirect to dashboard if already authenticated. */
    public function showLoginForm(): Response|RedirectResponse
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var Guard $auth */
        $auth = auth()->guard($guardName);

        if ($auth->check()) {
            return redirect()->route('martis.index');
        }

        return response(view('martis::app'));
    }

    /** Validate credentials, authenticate the user, and regenerate the session. */
    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate($this->loginRules());

        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var StatefulGuard $auth */
        $auth = auth()->guard($guardName);

        // "Keep me signed in" — attemptLogin() forwards the toggle to attempt()
        // so Laravel issues the long-lived remember-me cookie. Without it the
        // session only lives for config('session.lifetime') and the toggle is
        // a no-op. Shared with AuthController so the two cannot diverge again.
        if (! $this->attemptLogin($auth, $request)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('auth.failed'),
                    'errors' => ['email' => [__('auth.failed')]],
                ], 422);
            }

            return back()->withErrors(['email' => __('auth.failed')])->withInput($request->only('email'));
        }

        $request->session()->regenerate();

        if ($request->expectsJson()) {
            // Filter sensitive fields before returning user data to the client
            /** @var Model $loginUser */
            $loginUser = $auth->user();
            $safe = array_diff_key(
                $loginUser->toArray(),
                array_flip(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])
            );

            return response()->json($safe);
        }

        return redirect()->intended(route('martis.index'));
    }

    /** Log out the user, invalidate the session, and redirect to the login page. */
    public function logout(Request $request): RedirectResponse
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var StatefulGuard $auth */
        $auth = auth()->guard($guardName);
        $auth->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('martis.login');
    }
}
