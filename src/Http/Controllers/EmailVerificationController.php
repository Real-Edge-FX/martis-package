<?php

namespace Martis\Http\Controllers;

use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
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
     * Uses Laravel's `EmailVerificationRequest` so the signed-URL +
     * id/hash matching live in the framework rather than here.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if (! config('martis.auth.email_verification.enabled', false)) {
            abort(404);
        }

        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->route('martis.index');
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
