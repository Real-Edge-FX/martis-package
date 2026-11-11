<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Martis\Auth\TwoFactorPass;
use Martis\Events\SsoFailed;
use Martis\Sso\IdentityResolver;
use Martis\Sso\RoleMapper;
use Martis\Sso\SsoException;
use Martis\Sso\SsoLogRedactor;
use Martis\Sso\SsoManager;
use Martis\Sso\SsoSession;
use Throwable;

/**
 * SSO entry points — generic over every registered provider.
 *
 *   GET  /{martis}/sso/{provider}/redirect   → kicks off OAuth/SAML flow
 *   GET  /{martis}/sso/{provider}/callback   → handles IdP callback,
 *                                              resolves user + roles,
 *                                              logs the user in
 *
 * The flow is:
 *   1. Provider exchanges the code for a token and returns SsoIdentity.
 *   2. RoleMapper resolves the local roles from the external groups.
 *   3. If empty AND on_no_role_match=deny, redirect to /login with error.
 *   4. IdentityResolver finds-or-creates the local user.
 *   5. PermissionAdapter syncs the resolved roles onto the user.
 *   6. AfterLogin hook fires (audit log, user-type rules, etc.).
 *   7. Auth::login() (remember-me only when the provider sets `remember`)
 *      + redirect to the configured target.
 */
class SsoController extends Controller
{
    public function __construct(
        private readonly SsoManager $manager,
        private readonly IdentityResolver $identityResolver,
        private readonly RoleMapper $roleMapper,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $driver = $this->manager->driver($provider);

        if ($driver === null) {
            return redirect()
                ->route('martis.login')
                ->withErrors(['sso' => __('martis::messages.sso_provider_disabled')]);
        }

        return $driver->redirect($request);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $driver = $this->manager->driver($provider);

        if ($driver === null) {
            return redirect()
                ->route('martis.login')
                ->withErrors(['sso' => __('martis::messages.sso_provider_disabled')]);
        }

        try {
            $identity = $driver->resolveIdentity($request);
        } catch (Throwable $e) {
            return $this->refuse($request, $provider, $e);
        }

        // Step 1: resolve user (find-or-create). Run BEFORE roles when
        // `auto_create_user = true` so the role mapper has the user
        // available for column lookups that depend on it.
        try {
            $user = $this->identityResolver->resolve($identity, $provider);
        } catch (SsoException $e) {
            return $this->refuse($request, $provider, $e);
        }

        if ($user === null) {
            return $this->refuse($request, $provider, null, SsoException::REASON_NOT_PROVISIONED, 'martis::messages.sso_user_not_provisioned');
        }

        // Step 2: resolve roles from the external group list.
        $roles = $this->roleMapper->map($identity->externalRoles, $user, $provider);

        if ($roles->isEmpty()) {
            $cfg = config("martis.auth.sso.providers.{$provider}", []);
            $strategy = (string) ($cfg['on_no_role_match'] ?? 'deny');

            // The custom no-role-match hook is consulted ONLY for the
            // 'callable' strategy (see SsoManager::onNoRoleMatchUsing). Firing
            // it unconditionally let a registered callback override an explicit
            // 'deny', and a 'callable' strategy whose callback returned null
            // fell through to the 'guest' path — logging the user in with no
            // roles. Both are now closed.
            if ($strategy === 'callable') {
                $hookResult = $this->manager->fireNoRoleMatch($identity, $provider);
                if ($hookResult instanceof RedirectResponse) {
                    $this->recordFailure($request, $provider, SsoException::REASON_NO_ROLE_MATCH, null, 'no local role matches the external roles; the no-role-match hook took over');

                    return $hookResult;
                }

                // No callback registered, or it returned null: deny. A
                // 'callable' strategy must never silently log in role-less.
                return $this->refuse($request, $provider, null, SsoException::REASON_NO_ROLE_MATCH, 'martis::messages.sso_no_role_match');
            }

            if ($strategy === 'deny') {
                return $this->refuse($request, $provider, null, SsoException::REASON_NO_ROLE_MATCH, 'martis::messages.sso_no_role_match');
            }
            // strategy === 'guest' falls through with empty role set —
            // user is logged in without any local role.
        }

        // Step 3: sync roles via the configured adapter.
        if ((bool) (config("martis.auth.sso.providers.{$provider}.sync_roles", true))) {
            $this->manager->adapterFor($provider)->syncRoles($user, $roles);
        }

        // Step 4: post-login side effects.
        $this->manager->fireAfterLogin($user, $identity, $provider);

        // Step 5: log the user in via the Martis-configured guard. The
        // remember-me cookie is the provider's opt-in (`remember`, default
        // false): with it, the panel stays open after the IdP session ends
        // or the IdP deprovisions the user, until the remember cookie
        // expires or its token is rotated, so the session follows the normal
        // session lifetime and a fresh IdP round-trip decides after it.
        $guard = config('martis.guard') ?: config('auth.defaults.guard');
        $remember = (bool) config("martis.auth.sso.providers.{$provider}.remember", false);
        Auth::guard($guard)->login($user, $remember);

        // Every sign-in starts without a 2FA pass: an SSO sign-in must not
        // inherit the pass of an earlier sign-in of this browser session.
        TwoFactorPass::revoke($request->session());

        // Record the SSO origin (SsoSession): a later logout redirects
        // through the IdP's federated logout URL (AuthController::logout)
        // and the forced password change gate never holds the session. With
        // remember-me it survives a remember-me re-login (the cookie), and
        // it is dropped on sign-out and on a non-SSO sign-in.
        SsoSession::start($request, $user, $provider, is_string($guard) ? $guard : null, $remember);

        $redirectTo = (string) (config("martis.auth.sso.providers.{$provider}.redirect_to") ?? '/'.config('martis.path', 'martis'));

        return redirect()->intended($redirectTo);
    }

    /**
     * Refuse the sign-in: log it, dispatch {@see SsoFailed} and send the person
     * back to the login page. Every refusal of the callback goes through here,
     * so each one carries a distinct `reason`: the exception's own for an
     * {@see SsoException}, `unknown` for any other Throwable, or the one the
     * caller names when there is no exception.
     *
     * The login page shows the exception's `messageKey` text when that key is
     * translated, else the generic `sso_callback_failed` text. A plain
     * Throwable never reaches the page with its message.
     */
    private function refuse(
        Request $request,
        string $provider,
        ?Throwable $exception,
        string $reason = SsoException::REASON_UNKNOWN,
        ?string $messageKey = null,
    ): RedirectResponse {
        if ($exception instanceof SsoException) {
            $reason = $exception->reason;
            $messageKey = $exception->messageKey;
        }

        $this->recordFailure($request, $provider, $reason, $exception, $exception?->getMessage() ?? "SSO sign-in refused: {$reason}");

        $key = $messageKey !== null && Lang::has($messageKey) ? $messageKey : 'martis::messages.sso_callback_failed';

        return redirect()
            ->route('martis.login')
            ->withErrors(['sso' => __($key)]);
    }

    /**
     * One warning log line and one {@see SsoFailed}. The message goes through
     * {@see SsoLogRedactor}: HTTP client exceptions embed request URLs and
     * response bodies that carry codes and tokens.
     */
    private function recordFailure(Request $request, string $provider, string $reason, ?Throwable $exception, string $message): void
    {
        $requestId = $request->headers->get('X-Request-Id');

        Log::channel(config('logging.default'))->warning('SSO callback failed', [
            'provider' => $provider,
            'reason' => $reason,
            'request_id' => is_string($requestId) && $requestId !== '' ? mb_substr($requestId, 0, 128) : (string) Str::uuid(),
            'exception' => $exception !== null ? $exception::class : null,
            'error' => SsoLogRedactor::redact($message),
        ]);

        SsoFailed::dispatch($provider, $reason, $exception);
    }
}
