<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\ConfigCallables;

/**
 * A `martis.profile.avatar.url_resolver` in each shape (see
 * {@see PageTitle}). Each form stamps its own path segment on the URL.
 */
final class AvatarUrl
{
    public function __invoke(string $path): string
    {
        return 'https://cdn.example.test/invokable/'.$path;
    }

    public static function resolve(string $path): string
    {
        return 'https://cdn.example.test/static/'.$path;
    }

    public function handle(string $path): string
    {
        return 'https://cdn.example.test/handle/'.$path;
    }
}
