<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Martis\Auth\PasswordChanger;
use Martis\Auth\PasswordPolicy;
use Martis\Auth\RecoveryCodesRegeneratedNotification;
use Martis\Auth\TwoFactorPass;
use Martis\Contracts\ProfileResourceContract;
use Martis\Impersonation\ImpersonationManager;
use Martis\Profile\AvatarService;
use Martis\Profile\BrowserSessionsService;
use Martis\Profile\EmailChange;
use Martis\Profile\TwoFactorService;
use Martis\Support\Initials;

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

        return response()->json($this->profilePayload($resource, $user));
    }

    /**
     * Update profile fields (name, email).
     *
     * The name is saved at once. A new email address is not (v2.4.0): it
     * takes the current password, and a confirmation link goes to the new
     * address (and a notice to the old one); the address switches when that
     * link is followed. The answer then carries `pending_email`. See
     * {@see EmailChange}.
     *
     * While `profile.account.email_editable` is false (v2.8.0) the address
     * is not validated and stays as it is: a request for another one answers
     * 422 and nothing is saved or mailed.
     *
     * @body-param string name required
     * @body-param string email required unless the address is locked
     * @body-param string current_password required when the email changes
     *
     * @response array<string, mixed>
     * @response 422 array{message: string, errors: array<string, string[]>}
     */
    public function update(Request $request, EmailChange $emailChange): JsonResponse
    {
        $user = $this->resolveUser($request);
        $resource = $this->resolveResource();

        $rules = $resource->updateRules($user);
        $emailLocked = array_key_exists('email', $rules) && ! EmailChange::allowed();

        if ($emailLocked) {
            // The switch holds on the server too: a hand-made request for
            // another address is refused, before anything is saved, rather
            // than mailed a confirmation link.
            if ($emailChange->isChange($user, $request->input('email'))) {
                throw ValidationException::withMessages([
                    'email' => [__('martis::profile.email_not_editable')],
                ]);
            }

            unset($rules['email']);
        }

        $changesEmail = array_key_exists('email', $rules) && $emailChange->isChange($user, $request->input('email'));

        if ($changesEmail) {
            // The address is the identity of the account: a session alone,
            // a browser left open, does not change it.
            $rules['current_password'] = ['required', 'string', 'current_password'];
        }

        $data = $request->validate($rules);
        unset($data['current_password']);

        // The request mails an address of the user's choosing: bound how many
        // it may mail, before anything is saved (see EmailChange::limitMail()).
        if ($changesEmail) {
            $emailChange->limitMail($user, (string) $data['email']);
        }

        $pendingEmail = null;
        if ($changesEmail) {
            $pendingEmail = trim((string) $data['email']);
            // The address stays as it is until the link is followed; the same
            // address in other letter case is no change.
            $data['email'] = $emailChange->currentEmail($user);
        } elseif (array_key_exists('email', $data) || $emailLocked) {
            // The resource saves the address it validates: hand it the
            // current one, also when the locked address was not validated.
            $data['email'] = $emailChange->currentEmail($user);
        }

        $resource->applyUpdate($user, $data);

        if ($pendingEmail !== null) {
            $emailChange->request($user, $pendingEmail);
        }

        return response()->json($this->profilePayload($resource, $user) + ($pendingEmail !== null ? ['pending_email' => $pendingEmail] : []));
    }

    /**
     * Change the authenticated user's password.
     *
     * @body-param string current_password required
     * @body-param string password required The new password, validated with the app's Password::defaults() (Password::min(8) without them)
     * @body-param string password_confirmation required
     *
     * @response array{message: string}
     * @response 403 array{message: string}
     */
    public function changePassword(Request $request, PasswordChanger $changer): JsonResponse
    {
        if ($refusal = $this->refuseWhileImpersonating()) {
            return $refusal;
        }

        $user = $this->resolveUser($request);

        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', PasswordPolicy::rule(), 'confirmed'],
        ]);

        assert($user instanceof Model);
        // The app's hasher, the user's reset tokens deleted, the forced
        // password change flag cleared, PasswordChanged fired (v2.3.0).
        $changer->change($user, (string) $request->input('password'), forced: false);

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
        $this->requireAvatar();

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
        $this->requireAvatar();

        $user = $this->resolveUser($request);
        $avatarService->remove($user);

        return response()->json(['message' => __('martis::profile.avatar_removed')]);
    }

    /**
     * Generate a new TOTP secret and return QR code SVG + secret for setup.
     *
     * @response array{secret: string, qr_code_svg: string, otpauth_uri: string}
     * @response 403 array{message: string}
     */
    public function twoFactorSetup(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $this->requireTwoFactor();

        if ($refusal = $this->refuseWhileImpersonating()) {
            return $refusal;
        }

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
     * @response 403 array{message: string}
     */
    public function twoFactorConfirm(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $this->requireTwoFactor();

        if ($refusal = $this->refuseWhileImpersonating()) {
            return $refusal;
        }

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

        // Mark session as 2FA-passed, for this user, so they are not forced to
        // challenge immediately after enabling 2FA (fresh session already
        // authenticated).
        TwoFactorPass::grant($request->session(), $user);

        return response()->json($result);
    }

    /**
     * Disable 2FA for the authenticated user.
     *
     * Requires the current password to prevent an attacker with a stolen
     * session from silently disabling 2FA.
     *
     * @body-param string current_password required
     *
     * @response array{message: string}
     * @response 403 array{message: string}
     */
    public function twoFactorDisable(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $this->requireTwoFactor();

        if ($refusal = $this->refuseWhileImpersonating()) {
            return $refusal;
        }

        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        $user = $this->resolveUser($request);
        $twoFactor->disable($user);

        // Clear the 2FA pass so it does not linger
        TwoFactorPass::revoke($request->session());

        return response()->json(['message' => __('martis::profile.2fa_disabled_success')]);
    }

    /**
     * Regenerate recovery codes for the authenticated user.
     *
     * Generates a new set of recovery codes (invalidating old ones) and returns
     * the plain-text codes for the user to save. Recovery codes stand in for
     * the TOTP factor at the challenge, so, like disabling 2FA, regenerating
     * them needs the current password: a stolen or left-open session must not
     * be able to mint itself a second factor and lock the owner out of theirs.
     * The user is told by email.
     *
     * @body-param string current_password required
     *
     * @response array{recovery_codes: list<string>}
     * @response 403 array{message: string}
     */
    public function twoFactorRegenerateCodes(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $this->requireTwoFactor();

        if ($refusal = $this->refuseWhileImpersonating()) {
            return $refusal;
        }

        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        $user = $this->resolveUser($request);

        try {
            $result = $twoFactor->regenerateRecoveryCodes($user);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $this->notifyRecoveryCodesRegenerated($user);

        return response()->json($result);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Browser sessions
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * List the active browser sessions for the current user. Reads from
     * the framework `sessions` table (Laravel database session driver)
     * and decorates each row with `is_current`, parsed user-agent
     * snippets, and `last_active`.
     *
     * Returns `{ sessions: [], driver: 'database', supported: true }`.
     * When the host app uses the `file` / `array` / `cookie` driver the
     * service returns `supported: false` so the React UI can render an
     * informational empty state instead of a confusing "no sessions".
     */
    public function sessions(Request $request, BrowserSessionsService $sessions): JsonResponse
    {
        $user = $this->resolveUser($request);

        return response()->json($sessions->forUser($user, $request));
    }

    /**
     * Revoke every session for the current user except the current one.
     * Useful as a single button on the Profile page (`Sign out everywhere
     * else`). When the driver does not store sessions (file / array)
     * returns `204` with `supported: false` so the UI can react.
     */
    public function destroyOtherSessions(Request $request, BrowserSessionsService $sessions): JsonResponse
    {
        $user = $this->resolveUser($request);
        $result = $sessions->revokeOthers($user, $request);

        return response()->json($result, $result['supported'] ? 200 : 204);
    }

    /**
     * Revoke a single session by the opaque `id` the session list gave it
     * (never the raw session id, which names nothing). The current session
     * is always preserved — pointing the endpoint at it is a no-op rather
     * than a footgun that signs the user out of the device they are using.
     */
    public function destroySession(Request $request, BrowserSessionsService $sessions, string $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        $result = $sessions->revoke($user, $request, $id);

        return response()->json($result, $result['supported'] ? 200 : 204);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * The avatar switch holds where its routes are still registered (a route
     * cache built while it was on, a config changed at runtime): off, the
     * endpoint answers 404 and stores nothing (v2.8.0).
     */
    private function requireAvatar(): void
    {
        abort_unless((bool) config('martis.profile.avatar.enabled', true), 404);
    }

    /**
     * The 2FA switch holds where its routes are still registered: off, the
     * endpoint answers 404 and writes nothing (v2.8.0).
     */
    private function requireTwoFactor(): void
    {
        abort_unless(TwoFactorService::featureEnabled(), 404);
    }

    /**
     * The answer to a factor- or identity-changing request made while an
     * operator impersonates the user, or null when none is running.
     *
     * The profile endpoints that change the account's second factor or
     * password (2FA setup, confirm, disable, the recovery codes, the password
     * change) act on whoever the guard signed in, which is the target while
     * an impersonation runs. Letting the operator through would hand them a
     * persistent takeover of the target's account that outlives the
     * impersonation: a recovery code, a new authenticator secret, a password
     * the target does not know.
     */
    private function refuseWhileImpersonating(): ?JsonResponse
    {
        if (! app(ImpersonationManager::class)->isActive()) {
            return null;
        }

        return response()->json([
            'message' => __('martis::profile.refused_while_impersonating'),
        ], 403);
    }

    /**
     * Tell the user, by email, that their recovery codes were regenerated, so
     * an owner whose session someone else used finds out. The mail is the
     * user's own when the model is Notifiable, else a mail to the address on
     * the account. A mailer that is down never breaks the request: the codes
     * are already regenerated, and the failure goes to the exception handler.
     */
    private function notifyRecoveryCodesRegenerated(Authenticatable $user): void
    {
        try {
            $notification = new RecoveryCodesRegeneratedNotification;

            if (method_exists($user, 'routeNotificationFor')) {
                Notification::send($user, $notification);

                return;
            }

            $email = $user instanceof Model ? $user->getAttribute('email') : null;
            if (is_string($email) && $email !== '') {
                Notification::route('mail', $email)->notify($notification);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

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
        return app(ProfileResourceContract::class);
    }

    /**
     * The resource's profile data, with the avatar initials and palette slot
     * of the user's name when the resource gives none, so the profile page
     * always has them (a resource that implements the contract directly may
     * not know the keys).
     *
     * @return array<string, mixed>
     */
    private function profilePayload(ProfileResourceContract $resource, Authenticatable $user): array
    {
        return $resource->toArray($user) + Initials::forUser($user);
    }
}
