<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\GuardUsers;

use Illuminate\Auth\MustVerifyEmail as VerifiesEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The user of a custom MARTIS_GUARD whose provider has its own table, as an
 * app declares it: a namespaced model (Laravel resolves `Rule::unique()` and
 * relations from the class), without Notifiable.
 */
class Admin extends Authenticatable implements MustVerifyEmail
{
    use VerifiesEmail;

    protected $table = 'martis_test_guard_users_admins';

    protected $guarded = [];
}
