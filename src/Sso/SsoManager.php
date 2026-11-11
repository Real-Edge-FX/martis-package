<?php

declare(strict_types=1);

namespace Martis\Sso;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Martis\Sso\Contracts\PermissionAdapterContract;
use Martis\Sso\Contracts\SsoProviderContract;
use Martis\Sso\PermissionAdapters\CallableAdapter;
use Martis\Sso\PermissionAdapters\NativeAdapter;
use Martis\Sso\PermissionAdapters\SpatieAdapter;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\Providers\SignedTokenProvider;

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
     * Built-in drivers a provider block names with `driver`, whatever the block
     * is called (v2.21.0). Only a driver that takes its name from the block
     * belongs here: the Azure provider reads `providers.azure.*` itself, so it
     * is registered under its own name only.
     *
     * @var array<string, class-string<SsoProviderContract>>
     */
    protected array $namedDrivers = [];

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
        if ($class !== null) {
            return app($class);
        }

        $class = $this->namedDriverClass($name);
        if ($class === null) {
            return null;
        }

        return app()->make($class, ['name' => $name]);
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
            if (is_string($name) && (bool) ($cfg['enabled'] ?? false) && (isset($this->providers[$name]) || $this->namedDriverClass($name) !== null)) {
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
        $this->namedDrivers['signed-token'] = SignedTokenProvider::class;
    }

    /**
     * The class of the built-in driver the block `$name` selects with its
     * `driver` key, or null (no block, no such driver).
     *
     * @return class-string<SsoProviderContract>|null
     */
    protected function namedDriverClass(string $name): ?string
    {
        $driver = config("martis.auth.sso.providers.{$name}.driver");

        return is_string($driver) ? ($this->namedDrivers[strtolower($driver)] ?? null) : null;
    }
}
