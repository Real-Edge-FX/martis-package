<?php

namespace Martis\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Martis\Profile\ProfileResource;

/**
 * Contract for the Martis profile resource, the class `profile.resource`
 * names (null: {@see ProfileResource}).
 *
 * It serialises the authenticated user for the profile page (`GET` and
 * `PATCH /api/profile`), validates and applies the account update, and
 * gives the Topbar its avatar: `/api/auth/user` and the login response
 * take `avatar_url` from `toArray()`. Extend the default resource to
 * change one of them.
 */
interface ProfileResourceContract
{
    /**
     * Return the profile data array for the given user. The profile page
     * reads `name`, `email`, `avatar_url` and `two_factor_enabled`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Authenticatable $user): array;

    /**
     * Return the validation rules for a profile update request.
     *
     * @return array<string, mixed>
     */
    public function updateRules(Authenticatable $user): array;

    /**
     * Apply a validated profile update to the user model.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyUpdate(Authenticatable $user, array $data): void;
}
