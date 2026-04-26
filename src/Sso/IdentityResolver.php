<?php

declare(strict_types=1);

namespace Martis\Sso;

use Closure;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;

/**
 * Find-or-create the local user that corresponds to an external SSO
 * identity. Supports three matching strategies:
 *
 *  • `email`       (default)   — `User::where('email', $identity->email)`.
 *  • `external_id`             — `User::where($column, $identity->externalId)`.
 *  • host-app override         — closure registered via
 *                                `MartisSso::resolveUserUsing(...)` replaces
 *                                the entire find-or-create logic.
 *
 * `auto_create_user` is opt-in per-provider. When `false`, missing
 * users get a `null` return and the controller short-circuits with a
 * 403 (defaults to a friendly "your account isn't provisioned" page).
 */
class IdentityResolver
{
    /** @var Closure(SsoIdentity, string): ?User|null */
    protected static ?Closure $resolver = null;

    /**
     * Override the entire user-resolution flow with a host-app closure.
     * Bypasses every find-or-create config strategy.
     *
     * @param  Closure(SsoIdentity, string): ?User  $callback
     */
    public static function resolveUsing(Closure $callback): void
    {
        static::$resolver = $callback;
    }

    public static function forgetResolver(): void
    {
        static::$resolver = null;
    }

    public function resolve(SsoIdentity $identity, string $provider): ?User
    {
        if (static::$resolver !== null) {
            return (static::$resolver)($identity, $provider);
        }

        $cfg = config('martis.auth.sso.providers.'.$provider, []);
        $matchAttribute = $cfg['identity_match_attribute'] ?? 'email';
        $autoCreate = (bool) ($cfg['auto_create_user'] ?? true);

        $userClass = $this->resolveUserClass();
        if ($userClass === null) {
            return null;
        }

        /** @var class-string<User> $userClass */
        $user = $this->find($userClass, $identity, $matchAttribute);

        if ($user === null) {
            if (! $autoCreate) {
                return null;
            }
            $user = $this->create($userClass, $identity);
        }

        $this->syncAttributes($user, $identity, $cfg);

        return $user;
    }

    /**
     * @param  class-string<User>  $userClass
     */
    protected function find(string $userClass, SsoIdentity $identity, string $matchAttribute): ?User
    {
        if ($matchAttribute === 'email') {
            if ($identity->email === null) {
                return null;
            }

            /** @var User|null $user */
            $user = $userClass::query()->where('email', $identity->email)->first();

            return $user;
        }

        // external_id strategy — host app must have a column to store the
        // provider-specific external id (typically `azure_object_id`,
        // `google_subject`, etc). Column name is config-driven.
        if ($matchAttribute === 'external_id') {
            $column = config('martis.auth.sso.providers.'.$identity->provider.'.identity_external_id_column')
                ?? $identity->provider.'_external_id';

            /** @var User|null $user */
            $user = $userClass::query()->where($column, $identity->externalId)->first();

            return $user;
        }

        return null;
    }

    /**
     * @param  class-string<User>  $userClass
     */
    protected function create(string $userClass, SsoIdentity $identity): User
    {
        /** @var User $user */
        $user = new $userClass;

        $user->forceFill([
            'name' => $identity->name ?? $identity->email ?? 'SSO User',
            'email' => $identity->email,
            // Random unguessable password — the user authenticates via
            // SSO, but Laravel's user table requires a non-null
            // password column on most installs.
            'password' => bcrypt(bin2hex(random_bytes(32))),
        ])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    protected function syncAttributes(User $user, SsoIdentity $identity, array $cfg): void
    {
        $sync = $cfg['sync_user_attributes'] ?? ['name', 'email'];
        if (! is_array($sync) || $sync === []) {
            return;
        }

        $changes = [];
        if (in_array('name', $sync, true) && $identity->name !== null && $user->getAttribute('name') !== $identity->name) {
            $changes['name'] = $identity->name;
        }
        if (in_array('email', $sync, true) && $identity->email !== null && $user->getAttribute('email') !== $identity->email) {
            $changes['email'] = $identity->email;
        }

        if ($changes !== []) {
            $user->forceFill($changes)->save();
        }
    }

    protected function resolveUserClass(): ?string
    {
        $guard = config('martis.guard') ?: config('auth.defaults.guard');
        $provider = config("auth.guards.{$guard}.provider", config('auth.defaults.provider'));
        $userClass = config("auth.providers.{$provider}.model");

        if (is_string($userClass) && class_exists($userClass)) {
            return $userClass;
        }

        return null;
    }
}
