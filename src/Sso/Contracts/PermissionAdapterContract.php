<?php

declare(strict_types=1);

namespace Martis\Sso\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;

/**
 * Strategy for syncing local roles onto a user. Three built-in
 * implementations:
 *
 *  • `SpatieAdapter`   — calls `$user->syncRoles($roles)` (laravel-permission).
 *  • `NativeAdapter`   — direct attach/detach against `model_has_roles`.
 *  • `CallableAdapter` — defers to a host-app closure for full control.
 */
interface PermissionAdapterContract
{
    /**
     * Replace the user's local roles with the resolved set.
     *
     * Implementations should detach any role on the user that isn't in
     * `$resolvedRoles` and attach any new one. Idempotent.
     *
     * @param  Collection<int, mixed>  $resolvedRoles  Eloquent collection
     *                                                 of role models (Spatie or app-defined). The exact class is
     *                                                 adapter-specific.
     */
    public function syncRoles(User $user, Collection $resolvedRoles): void;
}
