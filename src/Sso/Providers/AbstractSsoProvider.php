<?php

declare(strict_types=1);

namespace Martis\Sso\Providers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Martis\Sso\Contracts\SsoProviderContract;
use RuntimeException;

/**
 * Common base for Socialite-backed providers. Concrete subclasses
 * implement `name()` and `resolveIdentity()`.
 */
abstract class AbstractSsoProvider implements SsoProviderContract
{
    /**
     * Look up `martis.auth.sso.providers.{name}.{key}` with a default.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return config("martis.auth.sso.providers.{$this->name()}.{$key}", $default);
    }

    public function redirect(Request $request): RedirectResponse
    {
        $this->ensureSocialiteAvailable();

        $driver = (string) $this->config('driver', $this->name());
        $scopes = (array) $this->config('scopes', []);

        /** @var Provider $client */
        $client = Socialite::driver($driver);

        if (method_exists($client, 'scopes') && $scopes !== []) {
            /** @phpstan-ignore-next-line — runtime method on the OAuth2 base */
            $client->scopes($scopes);
        }

        /** @var RedirectResponse $response */
        $response = $client->redirect();

        return $response;
    }

    /**
     * Defensive check — Socialite is suggested, not required. Fail
     * loudly with the exact composer command rather than the cryptic
     * "Class not found" stack trace.
     */
    protected function ensureSocialiteAvailable(): void
    {
        if (! class_exists(Socialite::class)) {
            throw new RuntimeException(
                "SSO requires laravel/socialite. Install it with:\n\n".
                "    composer require laravel/socialite\n\n".
                'See docs/sso.md for the full setup.',
            );
        }
    }
}
