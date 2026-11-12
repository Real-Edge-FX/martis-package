<?php

declare(strict_types=1);

namespace Martis\Sso;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Find-or-create the local user that corresponds to an external SSO
 * identity. Supports three matching strategies:
 *
 *  • `email`       (default)   — `User::where('email', $identity->email)`.
 *  • `external_id`             — `User::where($column, $identity->externalId)`,
 *                                the column from `identity_external_id_column`
 *                                (default `{provider}_external_id`).
 *  • host-app override         — closure registered via
 *                                `MartisSso::resolveUserUsing(...)` replaces
 *                                the entire find-or-create logic.
 *
 * The `email` strategy adopts an existing local row only when it can trust
 * the row's email: it is verified (`email_verified_at` set) or self-registration
 * is off (`martis.auth.registration.enabled` false), so every row was
 * provisioned by an administrator. With registration open and the row
 * unverified, anyone could have registered the IdP user's address first and
 * kept the password; a model with no `email_verified_at` column cannot prove a
 * verified address at all, so it counts as unverified. The sign-in is refused with
 * {@see SsoAdoptionRefusedException} instead (v2.4.0).
 *
 * The `external_id` strategy matches an id the IdP issued, stores it in the
 * column when it creates a user, and refuses a sign-in with no id
 * ({@see SsoException::REASON_MISSING_EXTERNAL_ID}). A row that holds the
 * IdP's address but not its id is refused ({@see SsoException::REASON_UNLINKED_ACCOUNT},
 * v2.21.0; it was `account_unverified` before), unless the provider sets
 * `link_by_email` (default false): then a row that passes the same adoption
 * check and has an empty id column is linked (the id is written), and one that
 * holds a different id is refused
 * ({@see SsoException::REASON_EXTERNAL_ID_MISMATCH}), never overwritten.
 *
 * `sync_user_attributes` may list `external_id` on either strategy: the id is
 * written into an empty column, and a different non-empty value is refused the
 * same way.
 *
 * An identity the provider marks as having an unverified email
 * (`SsoIdentity::$emailVerified === false`) never adopts an existing row by its
 * email, on either strategy or with `link_by_email`
 * ({@see SsoException::REASON_EMAIL_UNVERIFIED}, v2.21.0).
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

    /**
     * The database connection the user model writes to: what a sign-in's
     * provisioning goes through, so a caller can wrap it in a transaction.
     * Falls back to the default connection when no user model is configured.
     */
    public function connection(): ConnectionInterface
    {
        $userClass = $this->resolveUserClass();

        if ($userClass === null) {
            return DB::connection();
        }

        /** @var class-string<User> $userClass */
        return (new $userClass)->getConnection();
    }

    public function resolve(SsoIdentity $identity, string $provider): ?User
    {
        if (static::$resolver !== null) {
            return (static::$resolver)($identity, $provider);
        }

        $cfg = config('martis.auth.sso.providers.'.$provider, []);
        $matchAttribute = $cfg['identity_match_attribute'] ?? 'email';
        $autoCreate = (bool) ($cfg['auto_create_user'] ?? true);

        if (! in_array($matchAttribute, ['email', 'external_id'], true)) {
            throw new InvalidArgumentException(
                "Unsupported [martis.auth.sso.providers.{$provider}.identity_match_attribute] value: expected 'email' or 'external_id'.",
            );
        }

        $userClass = $this->resolveUserClass();
        if ($userClass === null) {
            return null;
        }

        /** @var class-string<User> $userClass */
        $user = $matchAttribute === 'external_id'
            ? $this->findByExternalId($userClass, $identity, $provider, $cfg)
            : $this->findByEmail($userClass, $identity);

        if ($user === null) {
            if (! $autoCreate) {
                return null;
            }

            // The address may be held by a row this strategy did not match
            // (external_id without link_by_email, or an address in another
            // case): creating beside it would collide on the unique email or
            // duplicate the account.
            if ($identity->email !== null && $userClass::query()->where('email', $identity->email)->exists()) {
                // On the external_id strategy the cause is that nothing linked
                // the row, whatever its verification: say so (v2.21.0).
                throw $matchAttribute === 'external_id'
                    ? $this->unlinkedAccount($provider, $identity->email, $this->externalIdColumn($provider, $cfg))
                    : new SsoAdoptionRefusedException($provider, $identity->email);
            }

            $user = $this->create($userClass, $identity, $provider, $cfg, $matchAttribute === 'external_id');
        }

        $this->syncAttributes($user, $identity, $provider, $cfg);

        return $user;
    }

    /**
     * The refusal of an `external_id` sign-in whose address is held by a row
     * that carries no id: the log says why and what changes it, the login page
     * shows the generic text (no message key), so it does not tell whoever
     * holds an IdP account that a local row with that address exists.
     */
    protected function unlinkedAccount(string $provider, string $email, string $column): SsoException
    {
        return new SsoException(
            "SSO sign-in via [{$provider}] refused: a local account already holds [{$email}] but does not carry this identity's external id in [{$column}], and [martis.auth.sso.providers.{$provider}.link_by_email] is off, so the account is not linked and no second one is created beside it. Set link_by_email to true to link it by email, or store the IdP's id in [{$column}] for that account.",
            SsoException::REASON_UNLINKED_ACCOUNT,
        );
    }

    /**
     * @param  class-string<User>  $userClass
     */
    protected function findByEmail(string $userClass, SsoIdentity $identity): ?User
    {
        if ($identity->email === null) {
            return null;
        }

        /** @var User|null $user */
        $user = $userClass::query()->where('email', $identity->email)->first();

        if ($user !== null) {
            $this->assertEmailVerifiedForAdoption($identity);
        }

        if ($user !== null && ! $this->mayAdoptByEmail($user)) {
            throw new SsoAdoptionRefusedException($identity->provider, $identity->email);
        }

        return $user;
    }

    /**
     * external_id strategy: host app must have a column to store the
     * provider-specific external id (typically `azure_object_id`,
     * `google_subject`, etc). Column name is config-driven.
     *
     * @param  class-string<User>  $userClass
     * @param  array<string, mixed>  $cfg
     */
    protected function findByExternalId(string $userClass, SsoIdentity $identity, string $provider, array $cfg): ?User
    {
        // An empty id is no identity at all. Querying it would match the rows
        // whose column is empty, an arbitrary account.
        if (trim($identity->externalId) === '') {
            throw new SsoException(
                "SSO sign-in via [{$provider}] refused: the identity carries no external id and [martis.auth.sso.providers.{$provider}.identity_match_attribute] is 'external_id'.",
                SsoException::REASON_MISSING_EXTERNAL_ID,
            );
        }

        $column = $this->externalIdColumn($provider, $cfg);

        /** @var User|null $user */
        $user = $userClass::query()->where($column, $identity->externalId)->first();

        if ($user !== null || ! (bool) ($cfg['link_by_email'] ?? false) || $identity->email === null) {
            return $user;
        }

        // link_by_email: the row holding the IdP's address may be linked to
        // this id, under the same trust rule as the email strategy.
        /** @var User|null $row */
        $row = $userClass::query()->where('email', $identity->email)->first();

        if ($row === null) {
            return null;
        }

        $this->assertEmailVerifiedForAdoption($identity);

        if (! $this->mayAdoptByEmail($row)) {
            throw new SsoAdoptionRefusedException($provider, $identity->email);
        }

        $this->assertColumnFreeFor($row, $column, $identity, $provider);

        $row->forceFill([$column => $identity->externalId])->save();

        return $row;
    }

    /**
     * An identity whose provider says its email is NOT verified (`false`, not
     * `null`) never adopts an existing local row by that email: whoever
     * registered at the provider with an address they do not own would be
     * signed in as the local holder of it. Creating a new row and matching by
     * the external id do not adopt anything and are unaffected.
     *
     * @throws SsoException
     */
    protected function assertEmailVerifiedForAdoption(SsoIdentity $identity): void
    {
        if ($identity->emailVerified === false) {
            throw new SsoException(
                "SSO sign-in via [{$identity->provider}] refused: the provider says the identity's email is not verified, so the local account holding it is not adopted.",
                SsoException::REASON_EMAIL_UNVERIFIED,
            );
        }
    }

    /**
     * Refuse when the row already stores an id other than the identity's.
     * An empty column (null or '') is free.
     */
    protected function assertColumnFreeFor(User $user, string $column, SsoIdentity $identity, string $provider): void
    {
        $current = $user->getAttribute($column);

        if (($current === null || $current === '') || (string) $current === $identity->externalId) {
            return;
        }

        throw new SsoException(
            "SSO sign-in via [{$provider}] refused: local account [{$user->getKey()}] already stores a different external id in [{$column}]; it is never overwritten.",
            SsoException::REASON_EXTERNAL_ID_MISMATCH,
        );
    }

    /**
     * The column that stores the IdP's id for a provider.
     *
     * @param  array<string, mixed>  $cfg
     */
    protected function externalIdColumn(string $provider, array $cfg): string
    {
        $column = $cfg['identity_external_id_column'] ?? null;

        if ($column === null) {
            return $provider.'_external_id';
        }

        if (! is_string($column) || trim($column) === '') {
            throw new InvalidArgumentException(
                "[martis.auth.sso.providers.{$provider}.identity_external_id_column] must be a non-empty column name.",
            );
        }

        return $column;
    }

    /**
     * Whether a local row found by the IdP's address may be signed in as that
     * identity: its email is verified, or nobody can self-register rows. A
     * model with no `email_verified_at` column cannot prove a verified address,
     * so with registration open it is refused like an unverified row.
     */
    protected function mayAdoptByEmail(User $user): bool
    {
        if (! (bool) config('martis.auth.registration.enabled', false)) {
            return true;
        }

        $column = 'email_verified_at';
        $hasColumn = array_key_exists($column, $user->getAttributes())
            || Schema::connection($user->getConnectionName())->hasColumn($user->getTable(), $column);

        // With registration open a model with no verification column cannot
        // tell a self-registered row from a provisioned one, and cannot prove
        // the address was verified: refuse, as for an unverified row.
        if (! $hasColumn) {
            return false;
        }

        return $user->getAttribute($column) !== null;
    }

    /**
     * @param  class-string<User>  $userClass
     * @param  array<string, mixed>  $cfg
     */
    protected function create(string $userClass, SsoIdentity $identity, string $provider, array $cfg, bool $storeExternalId): User
    {
        /** @var User $user */
        $user = new $userClass;

        $attributes = [
            'name' => $identity->name ?? $identity->email ?? 'SSO User',
            'email' => $identity->email,
            // Random unguessable password — the user authenticates via
            // SSO, but Laravel's user table requires a non-null
            // password column on most installs.
            'password' => Hash::make(bin2hex(random_bytes(32))),
        ];

        if ($storeExternalId || $this->syncsExternalId($cfg)) {
            if (trim($identity->externalId) !== '') {
                $attributes[$this->externalIdColumn($provider, $cfg)] = $identity->externalId;
            }
        }

        $user->forceFill($attributes)->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    protected function syncAttributes(User $user, SsoIdentity $identity, string $provider, array $cfg): void
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
        if ($this->syncsExternalId($cfg) && trim($identity->externalId) !== '') {
            $column = $this->externalIdColumn($provider, $cfg);

            // Checked before anything is written: a refused id must not leave
            // the name or email of the row rewritten.
            $this->assertColumnFreeFor($user, $column, $identity, $provider);

            $current = $user->getAttribute($column);
            if ($current === null || $current === '') {
                $changes[$column] = $identity->externalId;
            }
        }

        if ($changes !== []) {
            $user->forceFill($changes)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    protected function syncsExternalId(array $cfg): bool
    {
        $sync = $cfg['sync_user_attributes'] ?? ['name', 'email'];

        return is_array($sync) && in_array('external_id', $sync, true);
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
