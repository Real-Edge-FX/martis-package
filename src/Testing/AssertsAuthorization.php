<?php

declare(strict_types=1);

namespace Martis\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;

/**
 * Pest / PHPUnit trait that adds expressive helpers for asserting
 * Laravel policy decisions in tests.
 *
 * The methods are thin wrappers over `$user->can(...)` / `$user->cannot(...)`
 * with friendly failure messages — instead of "Failed asserting that
 * false is true." the trait reports "Expected user 12 to be allowed
 * to update Post #5, but the policy denied.".
 *
 * Usage in a Pest test:
 *
 *     uses(\Martis\Testing\AssertsAuthorization::class);
 *
 *     it('admins can edit any post', function () {
 *         $admin = User::factory()->admin()->create();
 *         $post = Post::factory()->create();
 *         $this->assertCanUpdate($admin, $post);
 *     });
 *
 *     it('readonly users cannot delete', function () {
 *         $reader = User::factory()->create();
 *         $post = Post::factory()->create();
 *         $this->assertCannotDelete($reader, $post);
 *     });
 *
 * Cross-checks the same Gate Martis uses internally, so a passing
 * test guarantees the admin UI hides the matching control too.
 */
trait AssertsAuthorization
{
    public function assertCan(Authenticatable $user, string $ability, mixed ...$arguments): void
    {
        Assert::assertTrue(
            $user->can($ability, $arguments),
            $this->describeAuthFailure($user, $ability, $arguments, expected: 'allowed'),
        );
    }

    public function assertCannot(Authenticatable $user, string $ability, mixed ...$arguments): void
    {
        Assert::assertFalse(
            $user->can($ability, $arguments),
            $this->describeAuthFailure($user, $ability, $arguments, expected: 'denied'),
        );
    }

    public function assertCanView(Authenticatable $user, Model $model): void
    {
        $this->assertCan($user, 'view', $model);
    }

    public function assertCannotView(Authenticatable $user, Model $model): void
    {
        $this->assertCannot($user, 'view', $model);
    }

    public function assertCanViewAny(Authenticatable $user, string $modelClass): void
    {
        $this->assertCan($user, 'viewAny', $modelClass);
    }

    public function assertCannotViewAny(Authenticatable $user, string $modelClass): void
    {
        $this->assertCannot($user, 'viewAny', $modelClass);
    }

    public function assertCanCreate(Authenticatable $user, string $modelClass): void
    {
        $this->assertCan($user, 'create', $modelClass);
    }

    public function assertCannotCreate(Authenticatable $user, string $modelClass): void
    {
        $this->assertCannot($user, 'create', $modelClass);
    }

    public function assertCanUpdate(Authenticatable $user, Model $model): void
    {
        $this->assertCan($user, 'update', $model);
    }

    public function assertCannotUpdate(Authenticatable $user, Model $model): void
    {
        $this->assertCannot($user, 'update', $model);
    }

    public function assertCanDelete(Authenticatable $user, Model $model): void
    {
        $this->assertCan($user, 'delete', $model);
    }

    public function assertCannotDelete(Authenticatable $user, Model $model): void
    {
        $this->assertCannot($user, 'delete', $model);
    }

    public function assertCanRestore(Authenticatable $user, Model $model): void
    {
        $this->assertCan($user, 'restore', $model);
    }

    public function assertCanForceDelete(Authenticatable $user, Model $model): void
    {
        $this->assertCan($user, 'forceDelete', $model);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected function describeAuthFailure(
        Authenticatable $user,
        string $ability,
        array $arguments,
        string $expected,
    ): string {
        $userId = $user->getAuthIdentifier();
        $target = '';
        foreach ($arguments as $arg) {
            if ($arg instanceof Model) {
                $target = ' on '.$arg::class.' #'.$arg->getKey();
                break;
            }
            if (is_string($arg)) {
                $target = ' on '.$arg;
                break;
            }
        }

        return sprintf(
            'Expected user %s to be %s to %s%s, but the policy %s.',
            (string) $userId,
            $expected,
            $ability,
            $target,
            $expected === 'allowed' ? 'denied' : 'allowed',
        );
    }
}
