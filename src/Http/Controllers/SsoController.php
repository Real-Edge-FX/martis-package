<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Martis\Auth\TwoFactorPass;
use Martis\Events\SsoFailed;
use Martis\Events\SsoLoggedIn;
use Martis\Sso\IdentityResolver;
use Martis\Sso\RoleMapper;
use Martis\Sso\SsoDenial;
use Martis\Sso\SsoException;
use Martis\Sso\SsoIdentity;
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
 *
 * Steps 2 to 4 run roles first, so a refused identity never reaches the
 * database: when the role mapping does not need the user (the `column` and
 * `config` strategies, no `resolveRolesUsing` closure) the roles are mapped
 * before the user is resolved and a deny returns before IdentityResolver runs.
 * When it does need the user (the `callable` strategy, or a custom roles
 * closure), step 4 runs first inside a transaction on the user model's
 * connection and a deny rolls it back. `provision_on_deny = true` restores the
 * old order for apps that pre-provision rows on purpose: write, then deny.
 * A `resolveUserUsing` closure that writes outside the user model's
 * connection, and the model events a rolled-back row fired, are the app's to
 * handle.
 *
 * Every refusal is one log line, one SsoFailed event and a redirect to the
 * login page.
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

        // Steps 1 and 2: the user and the roles, deny before provisioning
        // (see the class docblock for the three orders).
        try {
            $admission = $this->admit($identity, $provider);
        } catch (SsoException $e) {
            return $this->refuse($request, $provider, $e);
        }

        if ($admission instanceof SsoDenial) {
            if ($admission->response !== null) {
                $this->recordFailure($request, $provider, $admission->reason, null, "SSO sign-in refused: {$admission->reason}; the no-role-match hook took over");

                return $admission->response;
            }

            return $this->refuse($request, $provider, null, $admission->reason, $admission->messageKey);
        }

        [$user, $roles] = $admission;

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
        // it is dropped on sign-out and on a non-SSO sign-in. The sign-in
        // time and the IdP's external id go with it (v2.21.0), for the
        // `martis.sso.session` middleware.
        SsoSession::start($request, $user, $provider, is_string($guard) ? $guard : null, $remember, $identity->externalId);

        SsoLoggedIn::dispatch($user, $identity, $provider);

        $redirectTo = (string) (config("martis.auth.sso.providers.{$provider}.redirect_to") ?? '/'.config('martis.path', 'martis'));

        return redirect()->intended($redirectTo);
    }

    /**
     * Resolve the local user and the local roles of an identity, or say why
     * the identity is refused. Three orders (see the class docblock):
     * `provision_on_deny` writes first, a role mapping that needs no user runs
     * before anything is written, and one that needs the user runs inside a
     * transaction that a denial rolls back.
     *
     * @return SsoDenial|array{0: User, 1: Collection<int, Model>}
     *
     * @throws SsoException
     */
    private function admit(SsoIdentity $identity, string $provider): SsoDenial|array
    {
        $cfg = config("martis.auth.sso.providers.{$provider}", []);

        if ((bool) ($cfg['provision_on_deny'] ?? false)) {
            return $this->provisionThenMap($identity, $provider);
        }

        if (! $this->mappingNeedsUser($provider)) {
            $roles = $this->roleMapper->map($identity->externalRoles, null, $provider);

            if ($roles->isEmpty()) {
                $denial = $this->noRoleMatch($identity, $provider);
                if ($denial !== null) {
                    return $denial;
                }
            }

            $user = $this->identityResolver->resolve($identity, $provider);

            return $user === null
                ? new SsoDenial(SsoException::REASON_NOT_PROVISIONED, 'martis::messages.sso_user_not_provisioned')
                : [$user, $roles];
        }

        $connection = $this->identityResolver->connection();
        $level = $connection->transactionLevel();
        $connection->beginTransaction();

        try {
            $result = $this->provisionThenMap($identity, $provider);

            if ($result instanceof SsoDenial) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }
        } catch (Throwable $e) {
            if ($connection->transactionLevel() > $level) {
                $connection->rollBack();
            }

            throw $e;
        }

        return $result;
    }

    /**
     * Write the user, then map the roles with it and decide.
     *
     * @return SsoDenial|array{0: User, 1: Collection<int, Model>}
     */
    private function provisionThenMap(SsoIdentity $identity, string $provider): SsoDenial|array
    {
        $user = $this->identityResolver->resolve($identity, $provider);

        if ($user === null) {
            return new SsoDenial(SsoException::REASON_NOT_PROVISIONED, 'martis::messages.sso_user_not_provisioned');
        }

        $roles = $this->roleMapper->map($identity->externalRoles, $user, $provider);

        if ($roles->isEmpty()) {
            $denial = $this->noRoleMatch($identity, $provider);
            if ($denial !== null) {
                return $denial;
            }
        }

        return [$user, $roles];
    }

    /**
     * Whether the role mapping reads the user: the `callable` strategy hands it
     * to the host's callable, and a `resolveRolesUsing` closure may read it.
     */
    private function mappingNeedsUser(string $provider): bool
    {
        if (RoleMapper::hasResolver()) {
            return true;
        }

        return (string) config("martis.auth.sso.providers.{$provider}.role_strategy", 'column') === 'callable';
    }

    /**
     * What an empty role set means: null for `guest` (sign in without a role),
     * else the denial. The custom no-role-match hook is consulted ONLY for the
     * 'callable' strategy (see SsoManager::onNoRoleMatchUsing). Firing it
     * unconditionally let a registered callback override an explicit 'deny',
     * and a 'callable' strategy whose callback returned null fell through to
     * the 'guest' path, logging the user in with no roles.
     */
    private function noRoleMatch(SsoIdentity $identity, string $provider): ?SsoDenial
    {
        $strategy = (string) config("martis.auth.sso.providers.{$provider}.on_no_role_match", 'deny');
        $key = 'martis::messages.sso_no_role_match';

        if ($strategy === 'guest') {
            return null;
        }

        if ($strategy === 'deny') {
            return new SsoDenial(SsoException::REASON_NO_ROLE_MATCH, $key);
        }

        if ($strategy !== 'callable') {
            throw new InvalidArgumentException(
                "Unsupported [martis.auth.sso.providers.{$provider}.on_no_role_match] value: expected 'deny', 'guest' or 'callable'.",
            );
        }

        $hookResult = $this->manager->fireNoRoleMatch($identity, $provider);

        // No callback registered, or it returned null: deny. A 'callable'
        // strategy must never silently log in role-less.
        return new SsoDenial(
            SsoException::REASON_NO_ROLE_MATCH,
            $key,
            $hookResult instanceof RedirectResponse ? $hookResult : null,
        );
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
