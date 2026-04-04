<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends MartisController
{
    /**
     * Return the currently authenticated user.
     *
     * Returns the authenticated user as JSON, or literal `null` when not logged in.
     * Note: the response is raw JSON `null` (not an empty object `{}`) so the React
     * frontend can reliably distinguish the unauthenticated state.
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

        return response()->json($user);
    }

    /**
     * Log out the currently authenticated user (API variant).
     *
     * Invalidates the current session and regenerates the CSRF token.
     * For JSON requests returns `{ "message": "Logged out" }`.
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
}
