<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Martis\Auth\DefaultRegistersUsers;
use Martis\Auth\PasswordPolicy;
use Martis\Auth\TwoFactorPass;
use Martis\Contracts\RegistersUsers;
use Martis\Invitations\InvalidInvitationException;
use Martis\Invitations\InvitationManager;
use Martis\Sso\SsoSession;
use Martis\Support\TokenLink;

/**
 * The PUBLIC (token-authorized, unauthenticated) surfaces of the
 * invitation feature: the accept-screen shell and the accept POST.
 * Both are default-off — every action 503s unless
 * `martis.invitations.enabled` is true, and the routes stay
 * always-registered (mirrors the register/reset-password pattern
 * elsewhere in this file) so the route table is predictable across
 * environments regardless of the flag.
 *
 * This is deliberately thin: all business rules (single-use claim,
 * expiry, anti-takeover, role assignment, audit) live in
 * `Martis\Invitations\InvitationManager`. The controller only maps
 * HTTP <-> manager calls and picks response shapes.
 *
 * Accept is TOKEN-authorized, not session/gate-authorized — the
 * invitee has no account yet, so neither `martis.auth` nor the
 * `martis-invite` Gate apply here. The `martis-invite` Gate only
 * guards the privileged "issue an invitation" action (elsewhere).
 *
 * Routes:
 *   GET  /invitations/accept           -> show()       (token in the URL fragment)
 *   GET  /invitations/accept/{token}   -> legacyLink() (a link emailed before v2.6.0)
 *   POST /api/invitations/accept       -> accept()
 */
class InvitationController extends MartisController
{
    /**
     * Render the accept-screen SPA shell.
     *
     * The emailed link carries the token in the URL fragment, which the
     * browser never sends, so this GET never sees it and cannot reveal its
     * validity: the response is the same 200 + SPA shell for every link.
     * Only the POST accept endpoint below ever confirms or denies validity.
     * A custom link builder that still puts the token in the query string
     * is sent on to the fragment form: `?token=…`, or the bare `?<token>`
     * that `route('martis.invitations.accept', $rawToken)` (the default
     * before v2.6.0) now produces on this parameterless route.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $this->abortUnlessInvitationsEnabled();

        $token = TokenLink::queryToken($request);
        if ($token !== '') {
            return TokenLink::redirect('martis.invitations.accept', ['token' => $token]);
        }

        return TokenLink::keepPrivate(response(view('martis::app')));
    }

    /**
     * A link emailed before v2.6.0 (`/invitations/accept/{token}`): sent on
     * to the accept screen with the token in the fragment, whether or not
     * the token is valid (no enumeration via this GET either).
     */
    public function legacyLink(string $token): RedirectResponse
    {
        $this->abortUnlessInvitationsEnabled();

        return TokenLink::redirect('martis.invitations.accept', ['token' => $token]);
    }

    /**
     * Complete an invitation: validate the signup payload, delegate
     * the atomic claim + user creation to `InvitationManager::accept()`,
     * then either log the new user in or send them to /login.
     *
     * Every unacceptable token state (unknown, expired, revoked,
     * already-used, email already registered) surfaces as
     * `InvalidInvitationException` and is turned into the SAME neutral
     * response regardless of which of those it was — no enumeration.
     *
     * A bad/mismatched password is a `ValidationException` and is
     * deliberately let through unchanged (normal 422 shape): the
     * invitee needs the real field error to retry, and the invitation
     * stays pending because `accept()` rolled the claim back.
     */
    public function accept(Request $request): JsonResponse|RedirectResponse
    {
        $this->abortUnlessInvitationsEnabled();

        $request->validate($this->acceptRules());

        try {
            $user = app(InvitationManager::class)->accept(
                (string) $request->input('token'),
                $request->all(),
            );
        } catch (InvalidInvitationException $e) {
            return $this->neutralInvitationResponse($request, $e->getMessage());
        }

        $loginAfterAccept = (bool) config('martis.invitations.login_after_accept', true);

        if ($loginAfterAccept) {
            /** @var string|null $guardName */
            $guardName = config('martis.guard');

            /** @var StatefulGuard $auth */
            $auth = auth()->guard($guardName);
            $auth->login($user);
            $request->session()->regenerate();

            // Every sign-in starts without a 2FA pass: the new account must
            // not inherit the pass of an earlier sign-in of this browser
            // session.
            TwoFactorPass::revoke($request->session());

            // A password, magic-link or invitation sign-in is not an SSO one: drop
            // the SSO origin an earlier SSO sign-in left in this session or in
            // the browser's cookie (SsoSession), and every cookie of it the user
            // kept, so the forced password change gate and the federated logout
            // do not read it.
            SsoSession::forget($request, $user);
        }

        $redirectTo = $loginAfterAccept
            ? $this->postAcceptRedirect()
            : route('martis.login', ['invitation' => 'accepted']);

        if ($request->expectsJson()) {
            /** @var array<string, mixed> $payload */
            $payload = ['ok' => true, 'redirect' => $redirectTo];

            if ($loginAfterAccept && $user instanceof Model) {
                $payload['user'] = array_diff_key(
                    $user->toArray(),
                    array_flip(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])
                );
            }

            return response()->json($payload);
        }

        return redirect($redirectTo);
    }

    /**
     * Validation rules for the accept payload: only the configured
     * `signup_fields` (default `name`, `password`) plus `password`
     * always requiring `confirmed`. `token` is always required — it
     * identifies which invitation is being claimed.
     *
     * The app's password policy (`PasswordPolicy`) is checked once: by
     * `DefaultRegistersUsers`, which the accept hands the signup to, or
     * here when the app binds a registrar of its own, which may not check
     * it. Checking it twice asked Have I Been Pwned twice per accept under
     * `uncompromised()`.
     *
     * @return array<string, list<mixed>>
     */
    private function acceptRules(): array
    {
        /** @var list<string> $signupFields */
        $signupFields = (array) config('martis.invitations.signup_fields', ['name', 'password']);

        $rules = ['token' => ['required', 'string']];

        foreach ($signupFields as $field) {
            if ($field === 'password') {
                continue; // handled below with its own rule set
            }

            $rules[$field] = ['required', 'string', 'max:255'];
        }

        $rules['password'] = ['required', 'string', 'confirmed'];

        if (get_class(app(RegistersUsers::class)) !== DefaultRegistersUsers::class) {
            $rules['password'][] = PasswordPolicy::rule();
        }

        return $rules;
    }

    /**
     * The neutral, enumeration-safe response for an
     * `InvalidInvitationException` — same shape whether the token was
     * unknown, expired, revoked, or already used.
     */
    private function neutralInvitationResponse(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => ['token' => [$message]],
            ], 422);
        }

        return redirect()->route('martis.login')->withErrors(['token' => $message]);
    }

    /** Where a freshly-logged-in invitee lands: config override, else the dashboard. */
    private function postAcceptRedirect(): string
    {
        $configured = config('martis.invitations.redirect_after_accept');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route('martis.index');
    }

    /** 503 (feature off) short-circuit shared by both public actions. */
    private function abortUnlessInvitationsEnabled(): void
    {
        if (! (bool) config('martis.invitations.enabled', false)) {
            abort(503);
        }
    }
}
