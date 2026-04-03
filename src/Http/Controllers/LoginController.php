<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LoginController extends MartisController
{
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

    public function login(Request $request): JsonResponse|RedirectResponse
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
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'As credenciais fornecidas estão incorretas.',
                    'errors' => ['email' => [__('auth.failed')]],
                ], 422);
            }

            return back()->withErrors(['email' => __('auth.failed')])->withInput($request->only('email'));
        }

        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json($auth->user());
        }

        return redirect()->intended(route('martis.index'));
    }

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
