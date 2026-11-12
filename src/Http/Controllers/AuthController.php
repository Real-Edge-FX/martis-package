<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Martis\Auth\AccountLoginThrottle;
use Martis\Auth\EmailVerificationRequirement;
use Martis\Auth\IntendedUrl;
use Martis\Auth\PanelAccess;
use Martis\Auth\PasswordBrokerConfigurationException;
use Martis\Auth\PasswordChangeRequirement;
use Martis\Auth\PasswordLogin;
use Martis\Auth\RememberedTwoFactor;
use Martis\Auth\TwoFactorPass;
use Martis\Contracts\ProfileResourceContract;
use Martis\Contracts\RegistersUsers;
use Martis\Contracts\ResetsUserPasswords;
use Martis\Contracts\SendsPasswordResetLinks;
use Martis\Http\Controllers\Concerns\AuthenticatesWithRememberMe;
use Martis\Profile\TwoFactorService;
use Martis\Sso\SsoSession;
use Martis\Support\Initials;

class AuthController extends MartisController
{
    use AuthenticatesWithRememberMe;

    /**
     * Return the currently authenticated user.
     *
     * Returns the authenticated user as JSON, or literal null when not logged in.
     * Note: the response is raw JSON null (not an empty object {}) so the React
     * frontend can reliably distinguish the unauthenticated state.
     *
     * When a user is authenticated but has a pending 2FA challenge, the response
     * includes `two_factor_pending: true` so the frontend can redirect to the
     * challenge page without waiting for a 423 from an API call.
     *
     * This route is public — it can be called without an active session.
     *
     * @response array<string, mixed>
     * @response null
     */
    public function user(Request $request): JsonResponse|Response
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        $user = auth()->guard($guardName)->user();

        if ($user === null) {
            // Laravel 12 response()->json(null) returns {} due to Symfony null coalescing.
            // Return raw JSON null to avoid frontend treating {} as authenticated.
            return response('null', 200)->header('Content-Type', 'application/json');
        }

        // Indicate pending 2FA challenge so the SPA can redirect without API calls.
        // Anything but a pass this user earned reads as "not yet passed": the
        // initial state is no pass at all, and a pass earned by another user
        // earlier in the same browser session does not count.
        $twoFactor = app(TwoFactorService::class);
        if ($twoFactor->isActive($user) && ! TwoFactorPass::holds($request->session(), $user)) {
            return response()->json([
                'two_factor_pending' => true,
                'message' => 'Two-factor authentication required.',
            ]);
        }

        // Indicate pending email verification so the SPA can redirect to
        // /email/verify on bootstrap (post-login refresh, deep-link reload).
        // Mirrors the two_factor_pending shape so the frontend handles both
        // gates the same way. v1.8.14+.
        if (EmailVerificationRequirement::requiredFor($user)) {
            return response()->json([
                'email_verification_pending' => true,
                'message' => 'Email verification required.',
            ]);
        }

        // A user the forced password change gate holds (v2.3.0): the SPA
        // bootstraps on the change page instead of the shell.
        if (PasswordChangeRequirement::requiredFor($request, $user)) {
            return response()->json([
                'password_change_pending' => true,
                'message' => 'Password change required.',
            ]);
        }

        // Include the avatar so the Topbar can show it on initial load.
        /** @var Model&Authenticatable $userModel */
        $userModel = $user;

        // The SPA signs in and navigates without reloading, so the 403 shell
        // of martis.authorize never reaches a refused user: this flag does.
        return response()->json(array_merge(
            $this->safeUserArray($userModel),
            $this->avatarPayload($userModel),
            ['panel_access' => PanelAccess::allows($userModel)],
        ));
    }

    /**
     * Log in with email and password and start a session.
     *
     * Authenticates the user via the configured guard and starts a Laravel session.
     * When the panel uses 2FA and the account is enrolled, returns a
     * `two_factor_required` flag instead of the user object — the frontend
     * must complete the challenge.
     *
     * This route is exempt from CSRF verification so it can be called from the Swagger playground.
     *
     * @body-param string email required The user's email address. Example: admin@example.com
     * @body-param string password required The user's password. Example: password
     *
     * @response 200 array<string, mixed>
     * @response 422 array{message: string, errors: array<string, string[]>}
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate($this->loginRules());

        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var StatefulGuard $auth */
        $auth = auth()->guard($guardName);

        // The per-account limit: wrong passwords for one account, from any
        // address, spend one bucket (AccountLoginThrottle).
        $email = (string) $request->input('email');
        $account = AccountLoginThrottle::userFor($auth, $email);
        if (AccountLoginThrottle::tooMany(AccountLoginThrottle::PASSWORD, $email, $account)) {
            throw AccountLoginThrottle::exception(AccountLoginThrottle::PASSWORD, $email, $account);
        }

        if (! $this->attemptLogin($auth, $request)) {
            AccountLoginThrottle::hit(AccountLoginThrottle::PASSWORD, $email, $account);

            return response()->json([
                'message' => __('auth.failed'),
                'errors' => ['email' => [__('auth.failed')]],
            ], 422);
        }

        AccountLoginThrottle::clear(AccountLoginThrottle::PASSWORD, $email, $account);

        $request->session()->regenerate();

        // Drop the SSO origin of an earlier SSO sign-in, in the session or
        // in the browser's cookie (SsoSession), and every cookie of it the
        // user kept, so a password sign-in is neither sent through the IdP's
        // federated logout nor exempt from the forced password change gate.
        SsoSession::forget($request, $auth->user());

        // Every sign-in starts without a 2FA pass (the Login listener
        // forgets it too; this keeps a guard that does not fire Login honest).
        TwoFactorPass::revoke($request->session());

        // Check if 2FA is active (the panel uses it and the account is
        // enrolled): the challenge is pending
        $user = $auth->user();
        if ($user && app(TwoFactorService::class)->isActive($user)) {
            return response()->json([
                'two_factor_required' => true,
                'message' => 'Two-factor authentication required.',
            ]);
        }

        // Email verification gate — when the workspace requires verification
        // and this user has not confirmed yet, return early with a
        // dedicated flag instead of the user payload. The session is still
        // alive (so the resend-link endpoint behind `auth:` works), but
        // the SPA navigates straight to /email/verify rather than
        // /martis/. Without this the user lands on the dashboard, every
        // protected API call 409s, and the experience reads as "the
        // gate doesn't work". v1.8.14+.
        if ($user && EmailVerificationRequirement::requiredFor($user)) {
            return response()->json([
                'email_verification_required' => true,
                'message' => 'Email verification required.',
            ]);
        }

        // The forced password change gate (v2.3.0): the SPA goes straight to
        // the change page, so the dashboard never paints behind the gate.
        if ($user && PasswordChangeRequirement::requiredFor($request, $user)) {
            return response()->json([
                'password_change_required' => true,
                'message' => 'Password change required.',
            ]);
        }

        /** @var Model&Authenticatable $loginUser */
        $loginUser = $user;

        // The page the person asked for before they were sent to the login
        // page, for the SPA to open instead of the dashboard (IntendedUrl).
        $intended = IntendedUrl::pullWhenSignedIn($request, $loginUser);

        return response()->json(array_merge(
            $this->safeUserArray($loginUser),
            $this->avatarPayload($loginUser),
            ['panel_access' => PanelAccess::allows($loginUser)],
            $intended !== null ? ['intended_url' => $intended] : [],
        ));
    }

    /**
     * Log out the currently authenticated user (API variant).
     *
     * Invalidates the current session and regenerates the CSRF token.
     * For JSON requests returns `{message: "Logged out"}`.
     * For non-JSON requests redirects to the login route.
     *
     * This route is public so it works even when the session/CSRF token is stale.
     *
     * @response array{message: string}
     */
    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var StatefulGuard $auth */
        $auth = auth()->guard($guardName);

        // Federated logout — when the user came in via SSO and the
        // matching provider declared `logout_url`, redirect them
        // through the IdP's logout endpoint so the IdP session is
        // also cleared. Without this the local session ends but the
        // user stays signed in at the IdP, and re-clicking "Sign in
        // with Microsoft" silently reuses the SSO session. Read before
        // signing out: after a remember-me re-login the origin is in
        // the SSO cookie of this user (SsoSession).
        $ssoProvider = (string) (SsoSession::provider($request, $auth->user()) ?? '');
        $loginUrl = (string) url('/'.ltrim((string) config('martis.path', 'martis'), '/').'/login');
        $logoutUrl = $ssoProvider !== ''
            ? $this->federatedLogoutUrl($request, $ssoProvider, $auth->user(), $loginUrl)
            : '';

        $auth->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        SsoSession::forget($request);
        RememberedTwoFactor::forget();

        if ($logoutUrl !== '') {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Logged out',
                    'logout_url' => $logoutUrl,
                ]);
            }

            return redirect()->away($logoutUrl);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Logged out']);
        }

        return redirect()->route('martis.login');
    }

    /**
     * The federated logout URL of an SSO provider, or '' for a local logout
     * only. `logout_url` is a URL, in which `{post_logout_redirect_uri}` is
     * replaced with the urlencoded Martis login page URL, or (v2.21.0) the name
     * of an invokable class, called as `__invoke(Request $request,
     * ?Authenticatable $user, string $postLogoutRedirectUri): ?string` with the
     * login page URL as it is (the class encodes it where its URL needs that)
     * and answering the URL, or null for a local logout only. It runs before
     * the local logout, while the user is still signed in.
     *
     * @throws InvalidArgumentException when the value names a class that is
     *                                  not invokable, or the class answers
     *                                  something that is neither a string nor null
     */
    private function federatedLogoutUrl(Request $request, string $provider, ?Authenticatable $user, string $loginUrl): string
    {
        $key = "martis.auth.sso.providers.{$provider}.logout_url";
        $configured = config($key);

        if ($configured === null || $configured === '') {
            return '';
        }

        if (! is_string($configured)) {
            throw new InvalidArgumentException("The [{$key}] config value must be a URL or the name of an invokable class, got ".get_debug_type($configured).'.');
        }

        // A URL never looks like a class name, so it never reaches the autoloader.
        if (preg_match('/^\\\\?[A-Za-z_]\w*(\\\\[A-Za-z_]\w*)*$/', $configured) !== 1 || ! class_exists($configured)) {
            return str_replace('{post_logout_redirect_uri}', rawurlencode($loginUrl), $configured);
        }

        $resolver = app($configured);

        if (! is_callable($resolver)) {
            throw new InvalidArgumentException("The [{$key}] config value names the class [{$configured}], which is not invokable: give it an __invoke(Request, ?Authenticatable, string): ?string method, or a plain URL.");
        }

        $url = $resolver($request, $user, $loginUrl);

        if ($url !== null && ! is_string($url)) {
            throw new InvalidArgumentException("The class [{$configured}] named by [{$key}] must return a URL string or null, got ".get_debug_type($url).'.');
        }

        return $url ?? '';
    }

    /**
     * Self-service registration.
     *
     * Resolves the bound `Martis\Contracts\RegistersUsers` implementation
     * and calls `register()`. The default impl is
     * `Martis\Auth\DefaultRegistersUsers`; consumers replace it via the
     * service container.
     *
     * Disabled by default. Toggle with
     * `MARTIS_AUTH_REGISTRATION_ENABLED=true`. Returns 404 when off so
     * the route does not silently accept payloads.
     *
     * @body-param string name required Example: Jane Doe
     * @body-param string email required Example: jane@example.com
     * @body-param string password required Example: secret-pass-1234
     * @body-param string password_confirmation required
     *
     * @response 201 array{ok: true, user: array<string, mixed>}
     * @response 404
     * @response 422 array{message: string, errors: array<string, string[]>}
     */
    public function register(Request $request, RegistersUsers $registrar): JsonResponse
    {
        if (! config('martis.auth.registration.enabled', false)) {
            return response()->json(['message' => 'Registration is disabled.'], 404);
        }

        $user = $registrar->register($request);

        if (! $user instanceof Model) {
            // Custom RegistersUsers implementations are free to return a
            // non-Eloquent Authenticatable; we only sanitise model arrays.
            return response()->json(['ok' => true], 201);
        }

        /** @var Model&Authenticatable $user */
        return response()->json([
            'ok' => true,
            'user' => $this->safeUserArray($user),
        ], 201);
    }

    /**
     * Send the password reset link to the address in the request body.
     *
     * Resolves the bound `Martis\Contracts\SendsPasswordResetLinks`
     * implementation. Maps Laravel's broker status constants to HTTP:
     *   - `RESET_LINK_SENT` → 200
     *   - every other status (`INVALID_USER`, `RESET_THROTTLED`) → the same
     *     200 outside `app.debug`, so the answer never tells an anonymous
     *     caller whether the address has an account (an unknown address and
     *     a known one asked twice within the throttle window would
     *     otherwise differ); with `app.debug` on, the detailed 422.
     *
     * @body-param string email required
     */
    public function sendPasswordResetLink(Request $request, SendsPasswordResetLinks $sender): JsonResponse
    {
        if (! PasswordLogin::passwordResetEnabled()) {
            return response()->json([
                'message' => __('auth.forgot_password_disabled'),
            ], 404);
        }

        // v1.8.0 — wrap the broker call so a misconfigured / down mailer
        // (SMTP timeout, missing API key, queue worker not running) shows
        // a Martis toast instead of a raw 500 to the guest. The original
        // exception still goes through `report()` for monitoring.
        try {
            $status = $sender->sendResetLink($request);
        } catch (ValidationException $e) {
            // Let the framework's 422 response shape through unchanged
            // so the form displays per-field errors correctly.
            throw $e;
        } catch (PasswordBrokerConfigurationException $e) {
            // A broker that does not read the Martis guard's users is a
            // configuration error, not an unavailable mailer: let it fail
            // loudly, naming the config key.
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => __('auth.forgot_password_mailer_unavailable'),
            ], 503);
        }

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'ok' => true,
                'status' => __($status),
            ]);
        }

        // Anything else says something about the address: no account, or a
        // link sent a moment ago. Outside debug it answers exactly like a
        // sent link, as the magic-link request does, so this guest endpoint
        // cannot confirm which addresses are panel accounts.
        if (! config('app.debug')) {
            return response()->json([
                'ok' => true,
                'status' => __(Password::RESET_LINK_SENT),
            ]);
        }

        return response()->json([
            'message' => __($status),
            'errors' => ['email' => [__($status)]],
        ], 422);
    }

    /**
     * Apply the new password using the token that landed in the user's
     * email.
     *
     * Resolves the bound `Martis\Contracts\ResetsUserPasswords`. Maps
     * status constants to HTTP:
     *   - `PASSWORD_RESET` → 200
     *   - `INVALID_TOKEN`, `INVALID_USER`, `RESET_THROTTLED` → 422. Outside
     *     `app.debug`, `INVALID_USER` answers as `INVALID_TOKEN`: an unknown
     *     address and a known one with a wrong token are otherwise told
     *     apart, which confirms the address has an account.
     *
     * @body-param string token required
     * @body-param string email required
     * @body-param string password required
     * @body-param string password_confirmation required
     */
    public function resetPassword(Request $request, ResetsUserPasswords $resetter): JsonResponse
    {
        if (! PasswordLogin::passwordResetEnabled()) {
            return response()->json(['message' => 'Password reset is disabled.'], 404);
        }

        $status = $resetter->reset($request);

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'ok' => true,
                'status' => __($status),
            ]);
        }

        if ($status === Password::INVALID_USER && ! config('app.debug')) {
            $status = Password::INVALID_TOKEN;
        }

        return response()->json([
            'message' => __($status),
            'errors' => ['email' => [__($status)]],
        ], 422);
    }

    /**
     * Return a sanitised user array safe for client responses.
     *
     * Strips sensitive fields (password hash, 2FA secret, recovery codes,
     * remember token) that must never be sent to the browser.
     *
     * @return array<string, mixed>
     */

    /**
     * Return a sanitised user array safe for client responses.
     *
     * Strips sensitive fields (password hash, 2FA secret, recovery codes,
     * remember token) that must never be sent to the browser.
     *
     * @return array<string, mixed>
     */
    private function safeUserArray(Model&Authenticatable $user): array
    {
        return array_diff_key(
            $user->toArray(),
            array_flip(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])
        );
    }

    /**
     * The avatar keys of the user payload, read from the profile resource
     * the profile page serves (`profile.resource`), so the Topbar shows the
     * avatar the profile page shows. The raw model only holds the stored
     * path; the resource resolves the public URL. The initials and palette
     * slot the avatar falls back to are the user's name's
     * ({@see Initials::forUser()}) when the resource gives none.
     *
     * @return array{avatar_url: mixed, avatar_initials: mixed, avatar_palette: mixed}
     */
    private function avatarPayload(Model&Authenticatable $user): array
    {
        $profile = app(ProfileResourceContract::class)->toArray($user) + Initials::forUser($user);

        return [
            'avatar_url' => $profile['avatar_url'] ?? null,
            'avatar_initials' => $profile['avatar_initials'],
            'avatar_palette' => $profile['avatar_palette'],
        ];
    }
}
