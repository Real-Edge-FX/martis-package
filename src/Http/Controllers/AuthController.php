<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuthController extends MartisController
{
    public function user(Request $request): JsonResponse
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        return response()->json(auth()->guard($guardName)->user());
    }

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
