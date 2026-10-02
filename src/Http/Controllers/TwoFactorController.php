<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Martis\Auth\PasswordChangeRequirement;
use Martis\Auth\TwoFactorPass;
use Martis\Profile\TwoFactorService;

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
     * @response 422 array{message: string, errors: array<string, string[]>}
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

        $code = (string) $request->input('code');
        $useRecovery = (bool) $request->input('use_recovery_code', false);

        $valid = $useRecovery
            ? $twoFactor->verifyRecoveryCode($user, $code)
            : $twoFactor->verifyForUser($user, $code);

        if (! $valid) {
            return response()->json([
                'message' => __('martis::profile.2fa_challenge_failed'),
                'errors' => ['code' => [__('martis::profile.2fa_challenge_failed')]],
            ], 422);
        }

        // Mark this session as 2FA-passed, for this user
        TwoFactorPass::grant($request->session(), $user);

        $payload = ['message' => 'Authenticated.'];

        // A user the forced password change gate holds goes there next (v2.3.0).
        if (PasswordChangeRequirement::requiredFor($request, $user)) {
            $payload['password_change_required'] = true;
        }

        return response()->json($payload);
    }
}
