<?php

namespace Martis\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for the Martis profile resource.
 *
 * Publish and extend to customise which fields appear on the profile page,
 * how they are validated, and how the authenticated user is serialised.
 */
interface ProfileResourceContract
{
    /**
     * Return the profile data array for the given user.
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
