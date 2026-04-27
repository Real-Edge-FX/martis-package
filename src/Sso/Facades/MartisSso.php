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
 *     MartisSso::afterLogin(fn ($user, $identity, $provider) => AuditLog::record(...));
 *     MartisSso::onNoRoleMatchUsing(fn ($identity, $provider) => redirect('/login')->withErrors(...));
 *
 * @method static \Martis\Sso\Contracts\SsoProviderContract|null driver(string $name)
 * @method static bool isEnabled(string $provider)
 * @method static array enabledProviders()
 * @method static \Martis\Sso\Contracts\PermissionAdapterContract adapterFor(string $provider)
 * @method static void extend(string $name, string $providerClass)
 * @method static void resolveUserUsing(\Closure $callback)
 * @method static void resolveRolesUsing(\Closure $callback)
 * @method static void syncRolesUsing(\Closure $callback)
 * @method static void afterLogin(\Closure $callback)
 * @method static void onNoRoleMatchUsing(\Closure $callback)
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
