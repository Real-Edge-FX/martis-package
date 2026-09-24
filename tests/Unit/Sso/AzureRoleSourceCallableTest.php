<?php

declare(strict_types=1);

use Martis\Sso\Providers\AzureProvider;
use Martis\Tests\Fixtures\ConfigCallables\AzureRoleSource;
use Martis\Tests\TestCase;

uses(TestCase::class);

/**
 * @return array<int, string>
 */
function azureRolesFor(string $externalId, string $accessToken): array
{
    $provider = new class extends AzureProvider
    {
        /**
         * @return array<int, string>
         */
        public function rolesFor(string $externalId, string $accessToken): array
        {
            return $this->fetchExternalRoles($externalId, $accessToken);
        }
    };

    return $provider->rolesFor($externalId, $accessToken);
}

beforeEach(function () {
    config()->set('martis.auth.sso.providers.azure', ['role_source' => 'callable']);
});

it('reads the external roles through role_source_callable in each callable form', function (mixed $callable, array $expected) {
    config()->set('martis.auth.sso.providers.azure.role_source_callable', $callable);

    expect(azureRolesFor('user-1', 'token-1'))->toBe($expected);
})->with([
    'invokable class name' => [AzureRoleSource::class, ['invokable:user-1:token-1']],
    'static method array' => [[AzureRoleSource::class, 'resolve'], ['static:user-1:token-1']],
    'closure' => [fn (string $externalId, string $accessToken): array => ["closure:{$externalId}:{$accessToken}"], ['closure:user-1:token-1']],
]);

it('reads no external roles when role_source_callable is unset', function () {
    expect(azureRolesFor('user-1', 'token-1'))->toBe([]);
});

it('rejects a role_source_callable that is not a callable', function () {
    config()->set('martis.auth.sso.providers.azure.role_source_callable', [AzureRoleSource::class, 'handle']);

    expect(fn () => azureRolesFor('user-1', 'token-1'))->toThrow(
        InvalidArgumentException::class,
        'The [martis.auth.sso.providers.azure.role_source_callable] config value is not a callable: '.AzureRoleSource::class.'::handle() is not a public static method.',
    );
});
