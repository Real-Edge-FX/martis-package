<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Martis\Contracts\ProfileResourceContract;
use Martis\Profile\AvatarService;
use Martis\Profile\ProfileResource;
use Martis\Profile\TwoFactorService;

class ProfileController extends MartisController
{
    /**
     * Return the authenticated user's profile data.
     *
     * @response array<string, mixed>
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $resource = $this->resolveResource();

        return response()->json($resource->toArray($user));
    }

    /**
     * Update profile fields (name, email).
     *
     * @body-param string name required
     * @body-param string email required
     *
     * @response array<string, mixed>
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $resource = $this->resolveResource();

        $data = $request->validate($resource->updateRules($user));
        $resource->applyUpdate($user, $data);

        return response()->json($resource->toArray($user));
    }

    /**
     * Change the authenticated user's password.
     *
     * @body-param string current_password required
     * @body-param string password required New password (min 8 chars)
     * @body-param string password_confirmation required
     *
     * @response array{message: string}
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        assert($user instanceof Model);
        // @phpstan-ignore-next-line property.notFound
        $user->password = bcrypt((string) $request->input('password'));
        $user->save();

        return response()->json(['message' => __('martis::profile.password_updated')]);
    }

    /**
     * Upload a new profile picture.
     *
     * @body-param file avatar required JPEG, PNG or WebP image.
     *
     * @response array{url: string}
     */
    public function uploadAvatar(Request $request, AvatarService $avatarService): JsonResponse
    {
        $maxKb = (int) config('martis.profile.avatar.max_size_kb', 2048);

        $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
        ]);

        $user = $this->resolveUser($request);

        /** @var UploadedFile $file */
        $file = $request->file('avatar');
        $url = $avatarService->upload($user, $file);

        return response()->json(['url' => $url]);
    }

    /**
     * Remove the current profile picture.
     *
     * @response array{message: string}
     */
    public function removeAvatar(Request $request, AvatarService $avatarService): JsonResponse
    {
        $user = $this->resolveUser($request);
        $avatarService->remove($user);

        return response()->json(['message' => __('martis::profile.avatar_removed')]);
    }

    /**
     * Generate a new TOTP secret and return QR code SVG + secret for setup.
     *
     * @response array{secret: string, qr_code_svg: string, otpauth_uri: string}
     */
    public function twoFactorSetup(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $this->resolveUser($request);
        $data = $twoFactor->generateSetup($user);

        return response()->json($data);
    }

    /**
     * Confirm 2FA setup by validating the OTP code.
     *
     * @body-param string code required 6-digit TOTP code from authenticator app.
     *
     * @response array{recovery_codes: list<string>}
     */
    public function twoFactorConfirm(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $this->resolveUser($request);

        try {
            $result = $twoFactor->confirm($user, (string) $request->input('code'));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['code' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Disable 2FA for the authenticated user.
     *
     * @response array{message: string}
     */
    public function twoFactorDisable(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $this->resolveUser($request);
        $twoFactor->disable($user);

        return response()->json(['message' => __('martis::profile.2fa_disabled_success')]);
    }

    /**
     * Regenerate recovery codes for the authenticated user.
     *
     * Generates a new set of recovery codes (invalidating old ones) and returns
     * the plain-text codes for the user to save.
     *
     * @response array{recovery_codes: list<string>}
     */
    public function twoFactorRegenerateCodes(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $this->resolveUser($request);

        try {
            $result = $twoFactor->regenerateRecoveryCodes($user);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function resolveUser(Request $request): Authenticatable
    {
        /** @var string|null $guard */
        $guard = config('martis.guard');

        /** @var Authenticatable $user */
        $user = auth()->guard($guard)->user();

        return $user;
    }

    private function resolveResource(): ProfileResourceContract
    {
        /** @var class-string<ProfileResourceContract>|null $class */
        $class = config('martis.profile.resource');

        if ($class && class_exists($class)) {
            return app($class);
        }

        return app(ProfileResource::class);
    }
}
