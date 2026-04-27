<?php

declare(strict_types=1);

namespace Martis\Sso\PermissionAdapters;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Martis\Sso\Contracts\PermissionAdapterContract;

/**
 * Defers role synchronization to a host-app closure registered via
 * `MartisSso::syncRolesUsing(...)`. Use when the app has bespoke
 * permission rules that don't fit the Spatie or native shapes (e.g.
 * dual-table model_has_roles + main_role flag).
 */
class CallableAdapter implements PermissionAdapterContract
{
    /** @var Closure(User, Collection<int, mixed>): void|null */
    protected static ?Closure $callback = null;

    /** @param Closure(User, Collection<int, mixed>): void $callback */
    public static function setCallback(Closure $callback): void
    {
        static::$callback = $callback;
    }

    public static function forgetCallback(): void
    {
        static::$callback = null;
    }

    public function syncRoles(User $user, Collection $resolvedRoles): void
    {
        if (static::$callback === null) {
            return;
        }

        (static::$callback)($user, $resolvedRoles);
    }
}
