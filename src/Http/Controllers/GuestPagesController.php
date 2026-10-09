<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Martis\Support\TokenLink;

/**
 * Renders the SPA shell for the guest-only auth surfaces:
 *
 *   /register
 *   /forgot-password
 *   /reset-password                 (token and email in the URL fragment)
 *   /reset-password/{token}         (a link emailed before v2.6.0)
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

    /**
     * The page the emailed reset link opens. The link carries the token and
     * the email in the URL fragment, which never reaches the server; a
     * custom link builder that still puts them in the query string
     * (`?token=…` or the bare `?<token>` of `route($name, $token)`) is sent
     * on to the fragment form. A redirect away from the page drops the
     * fragment, so the token does not follow it.
     */
    public function showResetPassword(Request $request): Response|RedirectResponse
    {
        $response = $this->resolve('passwordReset');

        if ($response instanceof RedirectResponse) {
            return TokenLink::withoutFragment($response);
        }

        $token = TokenLink::queryToken($request);
        if ($token !== '') {
            return $this->toFragment($token, $request->query('email'));
        }

        return TokenLink::keepPrivate($response);
    }

    /**
     * A link emailed before v2.6.0 (`/reset-password/{token}?email=`): sent
     * on to the page with the token and email in the fragment.
     */
    public function legacyResetPasswordLink(Request $request, string $token): Response|RedirectResponse
    {
        $response = $this->resolve('passwordReset');

        if ($response instanceof RedirectResponse) {
            return TokenLink::withoutFragment($response);
        }

        return $this->toFragment($token, $request->query('email'));
    }

    private function toFragment(string $token, mixed $email): RedirectResponse
    {
        return TokenLink::redirect('martis.password.reset', array_filter([
            'token' => $token,
            'email' => is_string($email) ? $email : '',
        ], static fn (string $value): bool => $value !== ''));
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
