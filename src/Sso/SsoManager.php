<?php

declare(strict_types=1);

namespace Martis\Sso;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Martis\Auth\GuardCatalog;
use Martis\Sso\Contracts\PermissionAdapterContract;
use Martis\Sso\Contracts\SsoProviderContract;
use Martis\Sso\PermissionAdapters\CallableAdapter;
use Martis\Sso\PermissionAdapters\NativeAdapter;
use Martis\Sso\PermissionAdapters\SpatieAdapter;
use Martis\Sso\Providers\AzureProvider;

/**
 * Central SSO subsystem orchestrator.
 *
 * Holds a registry of available providers, knows how to resolve which
 * adapter to use for permission sync, and exposes the host-app hook
 * surface (`resolveUserUsing`, `resolveRolesUsing`, `syncRolesUsing`,
 * `afterLogin`, `onNoRoleMatch`).
 *
 * Resolved as a singleton from the container — host apps interact via
 * the `MartisSso` facade.
 */
class SsoManager
{
    /** @var array<string, class-string<SsoProviderContract>> */
    protected array $providers = [];

    /**
     * Registration order is the run order. `provider` null means every provider.
     *
     * @var list<array{callback: Closure(User, SsoIdentity, string): mixed, provider: string|null}>
     */
    protected array $afterLoginCallbacks = [];

    /** @var (Closure(SsoIdentity, string): mixed)|null */
    protected ?Closure $onNoRoleMatchCallback = null;

    /** @var array<string, Closure(SsoIdentity, string): mixed> */
    protected array $onNoRoleMatchProviderCallbacks = [];

    public function __construct()
    {
        $this->registerBuiltInProviders();
    }

    /**
     * Register a provider class. The class must implement
     * `SsoProviderContract`. Host apps can also register custom
     * providers via `MartisSso::extend('okta', OktaProvider::class)`.
     *
     * @param  class-string<SsoProviderContract>  $providerClass
     */
    public function extend(string $name, string $providerClass): void
    {
        $this->providers[strtolower($name)] = $providerClass;
    }

    /**
     * Provider resolved by name. Returns null when the provider is not
     * registered or is disabled in config.
     */
    public function driver(string $name): ?SsoProviderContract
    {
        $name = strtolower($name);

        if (! $this->isEnabled($name)) {
            return null;
        }

        $class = $this->providers[$name] ?? null;
        if ($class === null) {
            return null;
        }

        return app($class);
    }

    /**
     * The class registered for a provider name, whether or not the provider is
     * enabled in config. Null when none is registered.
     *
     * @return class-string<SsoProviderContract>|null
     */
    public function providerClass(string $name): ?string
    {
        return $this->providers[strtolower($name)] ?? null;
    }

    /** Whether the master switch AND the per-provider switch are on. */
    public function isEnabled(string $provider): bool
    {
        if (! (bool) config('martis.auth.sso.enabled', false)) {
            return false;
        }

        return (bool) config("martis.auth.sso.providers.{$provider}.enabled", false);
    }

    /** All currently enabled provider names. */
    public function enabledProviders(): array
    {
        if (! (bool) config('martis.auth.sso.enabled', false)) {
            return [];
        }

        $providers = (array) config('martis.auth.sso.providers', []);
        $out = [];
        foreach ($providers as $name => $cfg) {
            if (is_string($name) && (bool) ($cfg['enabled'] ?? false) && isset($this->providers[$name])) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Pick the permission adapter for a provider. Resolution order:
     *  1. config `permission_adapter` is `'callable'` → `CallableAdapter`
     *     (host app must have called `MartisSso::syncRolesUsing(...)`).
     *  2. config `permission_adapter` is `'spatie'` → `SpatieAdapter`.
     *  3. config `permission_adapter` is `'native'` → `NativeAdapter`.
     *  4. config `permission_adapter` is `'auto'` (default) →
     *     SpatieAdapter when laravel-permission is installed,
     *     NativeAdapter otherwise.
     */
    public function adapterFor(string $provider): PermissionAdapterContract
    {
        $strategy = (string) (config("martis.auth.sso.providers.{$provider}.permission_adapter") ?? 'auto');

        return match ($strategy) {
            'callable' => app(CallableAdapter::class),
            'spatie' => app(SpatieAdapter::class),
            'native' => app(NativeAdapter::class),
            default => SpatieAdapter::isAvailable()
                ? app(SpatieAdapter::class)
                : app(NativeAdapter::class),
        };
    }

    // -------------------------------------------------------------------------
    // Host-app hooks
    // -------------------------------------------------------------------------

    /**
     * Override the entire user-resolution flow. Closure receives the
     * resolved external identity and the provider name; must return a
     * Laravel User instance (or null to deny login).
     *
     * @param  Closure(SsoIdentity, string): ?User  $callback
     */
    public function resolveUserUsing(Closure $callback): void
    {
        IdentityResolver::resolveUsing($callback);
    }

    /**
     * Override the entire role-mapping flow. Closure receives the list
     * of external group/role names, the local user (may be null
     * before a user exists when `auto_create_user = true` and the
     * resolver runs before user creation), and the provider name.
     *
     * @param  Closure(array<int, string>, User|null, string): Collection<int, mixed>  $callback
     */
    public function resolveRolesUsing(Closure $callback): void
    {
        RoleMapper::resolveUsing($callback);
    }

    /**
     * Override the role-sync step. Activates the `CallableAdapter`
     * automatically — there's no need to also flip the config flag.
     *
     * @param  Closure(User, Collection<int, mixed>): mixed  $callback
     */
    public function syncRolesUsing(Closure $callback): void
    {
        CallableAdapter::setCallback($callback);
    }

    /**
     * Side-effect hook fired after a successful SSO login + role sync.
     * Use it for audit logging, welcome emails, denormalized counters.
     *
     * Callbacks accumulate: every registration is kept and they run in the
     * order they were registered. Pass a provider name to run the callback for
     * that provider only; without one it runs for every provider.
     *
     * @param  Closure(User, SsoIdentity, string): mixed  $callback
     */
    public function afterLogin(Closure $callback, ?string $provider = null): void
    {
        $this->afterLoginCallbacks[] = [
            'callback' => $callback,
            'provider' => $provider === null ? null : strtolower($provider),
        ];
    }

    /**
     * Fires when the role mapper returned an empty collection AND the
     * provider's `on_no_role_match` is `'callable'`. Closure can
     * return a redirect / response, or a string to flash, or null to
     * use the default deny page.
     *
     * Single-valued per scope: one global slot and one slot per provider, the
     * last registration for a scope replaces the earlier one. For a provider
     * its own slot wins; the global one answers every provider without a slot.
     *
     * @param  Closure(SsoIdentity, string): mixed  $callback
     */
    public function onNoRoleMatchUsing(Closure $callback, ?string $provider = null): void
    {
        if ($provider === null) {
            $this->onNoRoleMatchCallback = $callback;

            return;
        }

        $this->onNoRoleMatchProviderCallbacks[strtolower($provider)] = $callback;
    }

    /**
     * Run, in registration order, every afterLogin callback that is global or
     * scoped to this provider.
     */
    public function fireAfterLogin(User $user, SsoIdentity $identity, string $provider): void
    {
        $scope = strtolower($provider);

        foreach ($this->afterLoginCallbacks as $entry) {
            if ($entry['provider'] === null || $entry['provider'] === $scope) {
                ($entry['callback'])($user, $identity, $provider);
            }
        }
    }

    public function fireNoRoleMatch(SsoIdentity $identity, string $provider): mixed
    {
        $callback = $this->onNoRoleMatchProviderCallbacks[strtolower($provider)] ?? $this->onNoRoleMatchCallback;

        if ($callback === null) {
            return null;
        }

        return $callback($identity, $provider);
    }

    // -------------------------------------------------------------------------
    // Revocation
    // -------------------------------------------------------------------------

    /**
     * End every SSO session of a user, now and for good (v2.21.0): the
     * `martis.sso.session` middleware ends any session that SSO opened for the
     * user and whose sign-in is not later than this call, whatever the session
     * driver and whether or not `max_session_minutes` is set; the SSO origin
     * cookies of the user stop counting; and the user's remember token is
     * cycled, so no remember cookie signs them back in. A sign-in after the call
     * works normally.
     *
     * On the `database` session driver the user's session rows are deleted
     * too, which also ends sessions opened with a password, a magic link or an
     * invitation: those carry no SSO origin for the middleware to find. The
     * rows are matched by `sessions.user_id`, so they are left alone (with a
     * warning) when the app's session guards sign in users of more than one
     * table.
     *
     * Throws when the revocation cannot be recorded (the cache refused the
     * write), so a revocation that did not stick never looks as if it had.
     */
    public function revokeFor(int|string $userId): void
    {
        $id = (string) $userId;

        SsoSession::revokeUser($id);
        $this->cycleRememberToken($id);
        $this->deleteSessionRows($id);
    }

    /** Give the user a new remember token: the remember cookies of every browser stop signing them in. */
    protected function cycleRememberToken(string $userId): void
    {
        $guard = Auth::guard(GuardCatalog::martis());

        if (! method_exists($guard, 'getProvider')) {
            return;
        }

        $provider = $guard->getProvider();
        $user = $provider->retrieveById($userId);

        if ($user !== null) {
            $provider->updateRememberToken($user, Str::random(60));
        }
    }

    /** Delete the user's rows of the `sessions` table, on the database session driver. */
    protected function deleteSessionRows(string $userId): void
    {
        if ((string) config('session.driver', 'file') !== 'database') {
            return;
        }

        $connection = config('session.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;
        $table = (string) config('session.table', 'sessions');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        if (GuardCatalog::sessionUserIdsAreAmbiguous()) {
            Log::warning('Martis: the session rows of a revoked SSO user were not deleted. The session guards of config/auth.php sign in users of more than one table and sessions.user_id stores no table, so the rows of the user cannot be told apart from those of another user with the same id. Their SSO sessions end through the revocation time.', [
                'user_id' => $userId,
                'tables' => GuardCatalog::sessionUserTables(),
            ]);

            return;
        }

        DB::connection($connection)->table($table)->where('user_id', $userId)->delete();
    }

    // -------------------------------------------------------------------------
    // Hook lifecycle
    // -------------------------------------------------------------------------

    /**
     * Drop every host-app hook: the afterLogin and no-role-match callbacks
     * (global and per provider) and the user, role and sync overrides.
     * For tests and for apps that rebuild their hooks (Octane workers).
     */
    public function forgetHooks(): void
    {
        $this->afterLoginCallbacks = [];
        $this->onNoRoleMatchCallback = null;
        $this->onNoRoleMatchProviderCallbacks = [];
        IdentityResolver::forgetResolver();
        RoleMapper::forgetResolver();
        CallableAdapter::forgetCallback();
    }

    // -------------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------------

    public function flushHooksForTesting(): void
    {
        $this->forgetHooks();
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    protected function registerBuiltInProviders(): void
    {
        // The Azure provider class lives in the package; only loaded
        // when actually instantiated, so apps that never enable Azure
        // pay no overhead.
        $this->providers['azure'] = AzureProvider::class;
    }
}
