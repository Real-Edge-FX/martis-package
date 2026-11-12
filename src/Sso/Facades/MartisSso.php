<?php

declare(strict_types=1);

namespace Martis\Sso\Facades;

use Illuminate\Support\Facades\Facade;
use Martis\Sso\SsoManager;

/**
 * Static facade for the SSO subsystem. Use from `MartisServiceProvider`
 * or any boot hook in the host app:
 *
 *     use Martis\Sso\Facades\MartisSso;
 *
 *     MartisSso::resolveUserUsing(fn ($identity, $provider) => User::firstOrCreate(...));
 *     MartisSso::resolveRolesUsing(fn ($externalRoles, $user, $provider) => Role::whereIn(...)->get());
 *     MartisSso::syncRolesUsing(fn ($user, $roles) => $user->customSyncRolesMethod($roles));
 *     MartisSso::afterLogin(fn ($user, $identity, $provider) => AuditLog::record(...));            // appends, runs in registration order
 *     MartisSso::afterLogin(fn ($user, $identity, $provider) => Welcome::send($user), 'azure');    // this provider only
 *     MartisSso::onNoRoleMatchUsing(fn ($identity, $provider) => redirect('/login')->withErrors(...));
 *     MartisSso::onNoRoleMatchUsing(fn ($identity, $provider) => null, 'azure');                   // one slot per provider, wins over the global one
 *     MartisSso::revokeFor($user->getKey());                                                         // end every SSO session of this user (v2.21.0)
 *
 * @method static \Martis\Sso\Contracts\SsoProviderContract|null driver(string $name)
 * @method static bool isEnabled(string $provider)
 * @method static array enabledProviders()
 * @method static \Martis\Sso\Contracts\PermissionAdapterContract adapterFor(string $provider)
 * @method static void extend(string $name, string $providerClass)
 * @method static void resolveUserUsing(\Closure $callback)
 * @method static void resolveRolesUsing(\Closure $callback)
 * @method static void syncRolesUsing(\Closure $callback)
 * @method static void afterLogin(\Closure $callback, ?string $provider = null)
 * @method static void onNoRoleMatchUsing(\Closure $callback, ?string $provider = null)
 * @method static void revokeFor(int|string $userId)
 * @method static class-string<\Martis\Sso\Contracts\SsoProviderContract>|null providerClass(string $name)
 * @method static void forgetHooks()
 * @method static void flushHooksForTesting()
 *
 * @see SsoManager
 */
class MartisSso extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SsoManager::class;
    }
}
