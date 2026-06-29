<?php

namespace Martis\Http\Controllers;

use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Martis\Contracts\SendsEmailVerification;

/**
 * Handles the three email-verification surfaces:
 *
 *   GET  /{martis-path}/email/verify                  — themed notice page
 *   GET  /{martis-path}/email/verify/{id}/{hash}      — signed link, marks verified
 *   POST /{martis-path}/api/auth/email/verification-notification — re-send
 *
 * Every method short-circuits with 404 when
 * `martis.auth.email_verification.enabled` is false, so the routes
 * stay registered (predictable across environments) but inert.
 */
class EmailVerificationController extends MartisController
{
    /** GET /email/verify — render the themed notice SPA. */
    public function notice(): Response|RedirectResponse
    {
        if (! config('martis.auth.email_verification.enabled', false)) {
            abort(404);
        }

        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var Guard $auth */
        $auth = auth()->guard($guardName);

        // Not logged in → bounce to login.
        if (! $auth->check()) {
            return redirect()->route('martis.login');
        }

        // Already verified → bounce to dashboard.
        $user = $auth->user();
        if ($user instanceof MustVerifyEmail && $user->hasVerifiedEmail()) {
            return redirect()->route('martis.index');
        }

        return response(view('martis::app'));
    }

    /**
     * GET /email/verify/{id}/{hash} — signed link arrival.
     *
     * Authenticates the verification by the signed URL + the
     * `sha1(email)` hash in the path, NOT by an active session.
     * Laravel's bundled `EmailVerificationRequest::authorize()` calls
     * `$this->user()->getKey()`, which throws when the user clicks the
     * link from a logged-out browser tab — the most common case in
     * practice (user opens email on phone, clicks, gets bounced to
     * login, loses the signature). Both the signature (via the
     * `signed` middleware on the route) and the path hash are
     * unforgeable proofs of intent, so requiring auth on top adds no
     * security and breaks the flow.
     *
     * Resolution chain:
     *   1. Verify the URL signature (handled by middleware).
     *   2. Look up the user via the configured auth provider.
     *   3. Compare the path hash against `sha1(getEmailForVerification())`.
     *   4. Mark verified + fire `Verified`.
     *
     * Redirect target depends on session state:
     *   - Logged in → `/{martis}/` (dashboard).
     *   - Logged out → `/{martis}/login?verified=1` so the SPA can show
     *     a success toast on arrival.
     */
    public function verify(Request $request): RedirectResponse
    {
        if (! config('martis.auth.email_verification.enabled', false)) {
            abort(404);
        }

        $rawId = $request->route('id');
        $rawHash = $request->route('hash');
        if (! is_string($rawId) || ! is_string($rawHash)) {
            abort(400);
        }
        $id = $rawId;
        $hash = $rawHash;

        $userClass = $this->resolveUserModel();
        if ($userClass === null || ! is_subclass_of($userClass, Model::class)) {
            report(new \RuntimeException('Martis: auth user model is not configured — check auth.guards and auth.providers in your application config.'));
            abort(500);
        }

        /** @var Model|null $user */
        $user = $userClass::query()->find($id);
        if ($user === null) {
            abort(403);
        }

        $emailForVerification = method_exists($user, 'getEmailForVerification')
            ? (string) $user->getEmailForVerification()
            : (string) ($user->getAttribute('email') ?? '');

        if (! hash_equals(sha1($emailForVerification), $hash)) {
            abort(403);
        }

        if ($user instanceof MustVerifyEmail) {
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                event(new Verified($user));
            }
        } elseif ($user->getAttribute('email_verified_at') === null) {
            // Column-fallback path mirrors `EnsureEmailIsVerified`. The
            // standard Laravel users table ships the column even when
            // the model doesn't implement the contract — populate it
            // directly so the gate flips off without forcing the
            // consumer to add the trait. The `Verified` event isn't
            // fired here because its constructor requires
            // `MustVerifyEmail`; consumers wanting an audit signal in
            // the column-fallback case should listen for the model's
            // own `updated` event or add the contract.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        $authenticated = auth()->guard($guardName)->check()
            && (string) auth()->guard($guardName)->id() === (string) $user->getKey();

        if ($authenticated) {
            return redirect()->route('martis.index');
        }

        // Bounce to login with a verified marker so the page can show
        // a success toast and pre-fill nothing — the user has to enter
        // credentials again, but they no longer need to verify.
        return redirect()->route('martis.login', ['verified' => 1]);
    }

    /**
     * Resolve the host-app's User Eloquent model class via the
     * configured auth provider. Mirrors the resolution
     * `Martis\Auth\DefaultRegistersUsers` uses, so behaviour stays
     * consistent across the auth surfaces.
     *
     * @return class-string<Model>|null
     */
    private function resolveUserModel(): ?string
    {
        $guardName = config('martis.guard', 'web');
        $providerKey = is_string($guardName) ? $guardName : 'web';
        $provider = config("auth.guards.{$providerKey}.provider", 'users');
        $providerName = is_string($provider) ? $provider : 'users';
        $modelClass = config("auth.providers.{$providerName}.model");

        if (! is_string($modelClass) || $modelClass === '' || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        return $modelClass;
    }

    /** POST /api/auth/email/verification-notification — re-send. */
    public function send(Request $request, SendsEmailVerification $sender): JsonResponse
    {
        if (! config('martis.auth.email_verification.enabled', false)) {
            abort(404);
        }

        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $sender->send($user);

        return response()->json([
            'ok' => true,
            'message' => 'Verification link sent.',
        ]);
    }
}
