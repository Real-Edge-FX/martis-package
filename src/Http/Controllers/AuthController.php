<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Martis\Profile\ProfileResource;
use Martis\Profile\TwoFactorService;

class AuthController extends MartisController
{
    /**
     * Return the currently authenticated user.
     *
     * Returns the authenticated user as JSON, or literal null when not logged in.
     * Note: the response is raw JSON null (not an empty object {}) so the React
     * frontend can reliably distinguish the unauthenticated state.
     *
     * When a user is authenticated but has a pending 2FA challenge, the response
     * includes `two_factor_pending: true` so the frontend can redirect to the
     * challenge page without waiting for a 423 from an API call.
     *
     * This route is public — it can be called without an active session.
     *
     * @response array<string, mixed>
     * @response null
     */
    public function user(Request $request): JsonResponse|Response
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        $user = auth()->guard($guardName)->user();

        if ($user === null) {
            // Laravel 12 response()->json(null) returns {} due to Symfony null coalescing.
            // Return raw JSON null to avoid frontend treating {} as authenticated.
            return response('null', 200)->header('Content-Type', 'application/json');
        }

        // Indicate pending 2FA challenge so the SPA can redirect without API calls
        $twoFactorPassed = $request->session()->get('martis_two_factor_passed');
        $twoFactor = app(TwoFactorService::class);
        if ($twoFactor->isEnabled($user) && $twoFactorPassed === false) {
            return response()->json([
                'two_factor_pending' => true,
                'message' => 'Two-factor authentication required.',
            ]);
        }

        // Include avatar_url so the Topbar can show the profile picture on initial load.
        // The raw Eloquent model only contains the storage path (profile_picture);
        // ProfileResource resolves it to a full public URL.
        /** @var Model&Authenticatable $userModel */
        $userModel = $user;
        $profileResource = app(ProfileResource::class);
        $profileData = $profileResource->toArray($userModel);

        return response()->json(array_merge($this->safeUserArray($userModel), [
            'avatar_url' => $profileData['avatar_url'],
        ]));
    }

    /**
     * Log in with email and password and start a session.
     *
     * Authenticates the user via the configured guard and starts a Laravel session.
     * When 2FA is enabled on the account, returns a `two_factor_required` flag
     * instead of the user object — the frontend must complete the challenge.
     *
     * This route is exempt from CSRF verification so it can be called from the Swagger playground.
     *
     * @body-param string email required The user's email address. Example: admin@example.com
     * @body-param string password required The user's password. Example: password
     *
     * @response 200 array<string, mixed>
     * @response 422 array{message: string, errors: array<string, string[]>}
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var StatefulGuard $auth */
        $auth = auth()->guard($guardName);

        if (! $auth->attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            return response()->json([
                'message' => __('auth.failed'),
                'errors' => ['email' => [__('auth.failed')]],
            ], 422);
        }

        $request->session()->regenerate();

        // Check if 2FA is active — reset the challenge flag on new login
        $user = $auth->user();
        if ($user && app(TwoFactorService::class)->isEnabled($user)) {
            $request->session()->put('martis_two_factor_passed', false);

            return response()->json([
                'two_factor_required' => true,
                'message' => 'Two-factor authentication required.',
            ]);
        }

        /** @var Model&Authenticatable $loginUser */
        $loginUser = $user;
        $profileResource = app(ProfileResource::class);
        $profileData = $profileResource->toArray($loginUser);

        return response()->json(array_merge($this->safeUserArray($loginUser), [
            'avatar_url' => $profileData['avatar_url'],
        ]));
    }

    /**
     * Log out the currently authenticated user (API variant).
     *
     * Invalidates the current session and regenerates the CSRF token.
     * For JSON requests returns `{message: "Logged out"}`.
     * For non-JSON requests redirects to the login route.
     *
     * This route is public so it works even when the session/CSRF token is stale.
     *
     * @response array{message: string}
     */
    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var StatefulGuard $auth */
        $auth = auth()->guard($guardName);
        $auth->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Logged out']);
        }

        return redirect()->route('martis.login');
    }

    /**
     * Return a sanitised user array safe for client responses.
     *
     * Strips sensitive fields (password hash, 2FA secret, recovery codes,
     * remember token) that must never be sent to the browser.
     *
     * @return array<string, mixed>
     */
    private function safeUserArray(Model&Authenticatable $user): array
    {
        return array_diff_key(
            $user->toArray(),
            array_flip(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])
        );
    }
}
