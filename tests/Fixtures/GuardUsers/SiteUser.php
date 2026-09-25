<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\GuardUsers;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The site's user, signed in by the app's default guard (`web`, the `users`
 * provider) beside a custom MARTIS_GUARD.
 */
class SiteUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
