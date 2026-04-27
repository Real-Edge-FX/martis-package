<?php

declare(strict_types=1);

namespace Martis\Sso\PermissionAdapters;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Martis\Sso\Contracts\PermissionAdapterContract;

/**
 * Spatie/laravel-permission adapter — the most common case in apps
 * that already manage roles via the Spatie package. Calls
 * `$user->syncRoles($collection)`, which the Spatie `HasRoles` trait
 * provides. Auto-detect via `class_exists()` so we never crash apps
 * that don't have Spatie installed.
 */
class SpatieAdapter implements PermissionAdapterContract
{
    public static function isAvailable(): bool
    {
        return class_exists('Spatie\\Permission\\Models\\Role')
            && trait_exists('Spatie\\Permission\\Traits\\HasRoles');
    }

    public function syncRoles(User $user, Collection $resolvedRoles): void
    {
        if (! method_exists($user, 'syncRoles')) {
            // User model does not use the Spatie HasRoles trait — fall
            // back silently rather than crash the login flow.
            return;
        }

        // Spatie's syncRoles accepts a collection / array of Role
        // models, role names, or ids. We pass the Eloquent collection
        // straight through.
        /** @phpstan-ignore-next-line — runtime trait method */
        $user->syncRoles($resolvedRoles);
    }
}
