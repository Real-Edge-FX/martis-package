<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\ConfigCallables;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A `martis.gates.plan_resolver` in each shape (see {@see PageTitle}).
 * Every form reads the plan from the user it receives, so a test tells
 * a resolver that ran apart from the fail-closed null of one that did not.
 */
final class PlanResolver
{
    public const PRO_USER_ID = 7;

    public function __invoke(?Authenticatable $user): ?string
    {
        return self::planOf($user);
    }

    public static function resolve(?Authenticatable $user): ?string
    {
        return self::planOf($user);
    }

    public function handle(?Authenticatable $user): ?string
    {
        return self::planOf($user);
    }

    private static function planOf(?Authenticatable $user): ?string
    {
        return $user?->getAuthIdentifier() === self::PRO_USER_ID ? 'pro' : 'free';
    }
}
