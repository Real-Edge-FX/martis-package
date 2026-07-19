<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;
use Martis\Invitations\InvalidInvitationException;
use Martis\Invitations\InvitationManager;

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
 *   GET  /invitations/accept/{token}   -> show()
 *   POST /api/invitations/accept       -> accept()
 */
class InvitationController extends MartisController
{
    /**
     * Render the accept-screen SPA shell.
     *
     * Resolves the token so an unknown/expired/used one is exercised
     * through the same code path as a valid one, but the HTTP
     * response is deliberately IDENTICAL either way: 200 + the SPA
     * shell. Never let this GET reveal token validity via status
     * code or body — that would let an attacker enumerate live
     * invitations by probing links. The React accept screen (a later
     * task) decides what to render once mounted, and only the POST
     * accept endpoint below ever confirms or denies validity.
     */
    public function show(string $token): Response
    {
        $this->abortUnlessInvitationsEnabled();

        app(InvitationManager::class)->findByRawToken($token);

        return response(view('martis::app'));
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
     * always requiring `confirmed` + the same minimum strength as
     * the shared registration pipeline. `token` is always required —
     * it identifies which invitation is being claimed.
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

        $rules['password'] = ['required', 'string', 'confirmed', Password::min(8)];

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
