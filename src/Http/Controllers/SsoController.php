<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Martis\Sso\IdentityResolver;
use Martis\Sso\RoleMapper;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;

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
 *   7. Auth::login() + redirect to the configured target.
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
        } catch (\Throwable $e) {
            Log::channel(config('logging.default'))->warning('SSO callback failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('martis.login')
                ->withErrors(['sso' => __('martis::messages.sso_callback_failed')]);
        }

        // Step 1: resolve user (find-or-create). Run BEFORE roles when
        // `auto_create_user = true` so the role mapper has the user
        // available for column lookups that depend on it.
        $user = $this->identityResolver->resolve($identity, $provider);

        if ($user === null) {
            return redirect()
                ->route('martis.login')
                ->withErrors(['sso' => __('martis::messages.sso_user_not_provisioned')]);
        }

        // Step 2: resolve roles from the external group list.
        $roles = $this->roleMapper->map($identity->externalRoles, $user, $provider);

        if ($roles->isEmpty()) {
            $cfg = config("martis.auth.sso.providers.{$provider}", []);
            $strategy = (string) ($cfg['on_no_role_match'] ?? 'deny');

            $hookResult = $this->manager->fireNoRoleMatch($identity, $provider);
            if ($hookResult instanceof RedirectResponse) {
                return $hookResult;
            }

            if ($strategy === 'deny') {
                return redirect()
                    ->route('martis.login')
                    ->withErrors(['sso' => __('martis::messages.sso_no_role_match')]);
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

        // Step 5: log the user in via the Martis-configured guard.
        $guard = config('martis.guard') ?: config('auth.defaults.guard');
        Auth::guard($guard)->login($user, true);

        // Stash the provider name in the session so a later logout can
        // optionally redirect through the IdP's federated logout URL
        // (see AuthController::logout for the consumption side). Stays
        // around for the lifetime of the session — cleared on logout
        // and on a non-SSO login.
        $request->session()->put('martis_sso_provider', $provider);

        $redirectTo = (string) (config("martis.auth.sso.providers.{$provider}.redirect_to") ?? '/'.config('martis.path', 'martis'));

        return redirect()->intended($redirectTo);
    }
}
