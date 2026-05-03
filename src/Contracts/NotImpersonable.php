<?php

declare(strict_types=1);

namespace Martis\Contracts;

/**
 * Marker interface for users that must never be impersonated.
 *
 * `ImpersonationManager::start()` rejects any target that implements
 * this interface with a `RuntimeException`, which the controller
 * surfaces as a 422 (matching the existing self-impersonation /
 * chaining envelope). Use it on system accounts, API-only users,
 * super-admins, or any row where impersonation would be a security
 * footgun.
 *
 * The interface is empty by design — opting in is the entire signal.
 *
 * Example:
 *
 *     namespace App\Models;
 *
 *     use Illuminate\Foundation\Auth\User as Authenticatable;
 *     use Martis\Contracts\NotImpersonable;
 *
 *     class SystemAccount extends Authenticatable implements NotImpersonable
 *     {
 *     }
 */
interface NotImpersonable {}
