<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Renders the SPA shell for the guest-only auth surfaces:
 *
 *   /register
 *   /forgot-password
 *   /reset-password/{token}
 *
 * Always-registered. Behaviour at request time:
 *
 *   - If user is already authenticated     → 302 to dashboard.
 *   - If `auth.{flow}.enabled` is false    → 302 to /login.
 *   - If `auth.{flow}.url` is set          → 302 to the off-platform URL.
 *   - Otherwise                            → render the SPA shell, the
 *                                            React side then mounts
 *                                            pages/Register.tsx,
 *                                            pages/ForgotPassword.tsx, or
 *                                            pages/ResetPassword.tsx.
 */
class GuestPagesController extends MartisController
{
    public function showRegister(): Response|RedirectResponse
    {
        return $this->resolve('registration');
    }

    public function showForgotPassword(): Response|RedirectResponse
    {
        return $this->resolve('passwordReset');
    }

    public function showResetPassword(): Response|RedirectResponse
    {
        return $this->resolve('passwordReset');
    }

    private function resolve(string $flow): Response|RedirectResponse
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var Guard $auth */
        $auth = auth()->guard($guardName);

        if ($auth->check()) {
            return redirect()->route('martis.index');
        }

        if (! config("martis.auth.{$flow}.enabled", false)) {
            return redirect()->route('martis.login');
        }

        $offPlatformUrl = (string) config("martis.auth.{$flow}.url", '');
        if ($offPlatformUrl !== '') {
            return redirect()->away($offPlatformUrl);
        }

        return response(view('martis::app'));
    }
}
