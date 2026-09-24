<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\ConfigCallables;

/**
 * An Azure `role_source_callable` in each shape (see {@see PageTitle}).
 * Each form returns one role naming the form and the arguments it got.
 */
final class AzureRoleSource
{
    /**
     * @return array<int, string>
     */
    public function __invoke(string $externalId, string $accessToken): array
    {
        return ["invokable:{$externalId}:{$accessToken}"];
    }

    /**
     * @return array<int, string>
     */
    public static function resolve(string $externalId, string $accessToken): array
    {
        return ["static:{$externalId}:{$accessToken}"];
    }

    /**
     * @return array<int, string>
     */
    public function handle(string $externalId, string $accessToken): array
    {
        return ["handle:{$externalId}:{$accessToken}"];
    }
}
