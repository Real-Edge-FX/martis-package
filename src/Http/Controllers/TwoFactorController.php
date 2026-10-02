<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Martis\Auth\PasswordChangeRequirement;
use Martis\Auth\TwoFactorChallengeLockout;
use Martis\Auth\TwoFactorPass;
use Martis\Profile\TwoFactorService;
use Martis\Sso\SsoSession;

/**
 * Handles the 2FA challenge flow after login.
 */
class TwoFactorController extends MartisController
{
    /**
     * Validate an OTP or recovery code to complete the 2FA challenge.
     *
     * @body-param string code required The 6-digit OTP or a recovery code.
     * @body-param bool use_recovery_code Whether to treat code as a recovery code.
     *
     * @response 200 array{message: string, password_change_required?: bool}
     * @response 403 array{message: string, two_factor_locked: true}
     * @response 422 array{message: string, errors: array<string, string[]>}
     * @response 429 array{message: string}
     */
    public function challenge(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'use_recovery_code' => ['sometimes', 'boolean'],
        ]);

        /** @var string|null $guard */
        $guard = config('martis.guard');

        /** @var Authenticatable $user */
        $user = auth()->guard($guard)->user();

        // A user locked out by consecutive wrong codes gets no more guesses,
        // not even the right code, until the lockout passes.
        if (TwoFactorChallengeLockout::locked($user)) {
            return $this->lockedOut($request, $guard, $user);
        }

        $code = (string) $request->input('code');
        $useRecovery = (bool) $request->input('use_recovery_code', false);

        $valid = $useRecovery
            ? $twoFactor->verifyRecoveryCode($user, $code)
            : $twoFactor->verifyForUser($user, $code);

        if (! $valid) {
            // The wrong code that reaches the limit ends the pending session:
            // a fresh password sign-in is needed, once the lockout has passed.
            if (TwoFactorChallengeLockout::recordFailure($user)) {
                return $this->lockedOut($request, $guard, $user);
            }

            return response()->json([
                'message' => __('martis::profile.2fa_challenge_failed'),
                'errors' => ['code' => [__('martis::profile.2fa_challenge_failed')]],
            ], 422);
        }

        TwoFactorChallengeLockout::clear($user);

        // Mark this session as 2FA-passed, for this user
        TwoFactorPass::grant($request->session(), $user);

        $payload = ['message' => 'Authenticated.'];

        // A user the forced password change gate holds goes there next (v2.3.0).
        if (PasswordChangeRequirement::requiredFor($request, $user)) {
            $payload['password_change_required'] = true;
        }

        return response()->json($payload);
    }

    /**
     * End the pending session of a user the challenge locked out and say so:
     * 403 with `two_factor_locked`, which the SPA answers by going to the
     * login page.
     */
    private function lockedOut(Request $request, ?string $guard, Authenticatable $user): JsonResponse
    {
        Log::warning('Martis: the 2FA challenge locked a user out after consecutive wrong codes.', [
            'user' => $user->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        /** @var StatefulGuard $auth */
        $auth = auth()->guard($guard);
        $auth->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        SsoSession::forget($request);

        return response()->json([
            'message' => __('martis::profile.2fa_challenge_locked'),
            'two_factor_locked' => true,
        ], 403);
    }
}
