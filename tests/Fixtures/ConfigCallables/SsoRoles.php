<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\ConfigCallables;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;

/**
 * An SSO provider's `role_callable` in each shape (see {@see PageTitle}).
 * Each form maps every external role to one entry naming the form and
 * the provider.
 */
final class SsoRoles
{
    /**
     * @param  array<int, string>  $externalRoles
     * @return Collection<int, string>
     */
    public function __invoke(array $externalRoles, ?User $user, string $provider): Collection
    {
        return self::map('invokable', $externalRoles, $provider);
    }

    /**
     * @param  array<int, string>  $externalRoles
     * @return Collection<int, string>
     */
    public static function resolve(array $externalRoles, ?User $user, string $provider): Collection
    {
        return self::map('static', $externalRoles, $provider);
    }

    /**
     * @param  array<int, string>  $externalRoles
     * @return Collection<int, string>
     */
    public function handle(array $externalRoles, ?User $user, string $provider): Collection
    {
        return self::map('handle', $externalRoles, $provider);
    }

    /**
     * @param  array<int, string>  $externalRoles
     * @return Collection<int, string>
     */
    private static function map(string $form, array $externalRoles, string $provider): Collection
    {
        return new Collection(array_map(
            static fn (string $role): string => "{$form}:{$provider}:{$role}",
            $externalRoles,
        ));
    }
}
