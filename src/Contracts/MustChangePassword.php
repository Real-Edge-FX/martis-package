<?php

declare(strict_types=1);

namespace Martis\Contracts;

/**
 * A user the app can require to choose a new password before using the
 * panel: the forced password change gate (`martis.auth.password_change`).
 *
 * Without it, Martis reads and clears the boolean column named by
 * `martis.auth.password_change.column` (`must_change_password`).
 *
 *     class User extends Authenticatable implements MustChangePassword
 *     {
 *         public function mustChangePassword(): bool
 *         {
 *             return $this->password_expires_at?->isPast() ?? false;
 *         }
 *
 *         public function markPasswordChanged(): void
 *         {
 *             $this->password_expires_at = now()->addDays(90);
 *         }
 *     }
 */
interface MustChangePassword
{
    /** Whether the user must choose a new password before using the panel. */
    public function mustChangePassword(): bool;

    /**
     * Record that the user chose a new password. Martis saves the user
     * right after this call, together with the new password hash.
     */
    public function markPasswordChanged(): void;
}
