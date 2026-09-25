<?php

namespace Martis\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Helper that exposes the auth guards configured by the host app, and the
 * Martis guard among them: its name, its user model, its user in a panel
 * request, and whether the session table can tell its users apart from the
 * other guards'.
 *
 * The guard list is used by:
 *   - `Martis\Fields\GuardSelect` — populates its options at schema-render
 *     time so PermissionResource / RoleResource forms list the guards
 *     defined in `config/auth.guards` instead of forcing the dev to type
 *     a free-text value.
 *   - `Martis\Http\Controllers\MetaController` — exposes the same list
 *     as a JSON endpoint (`GET /martis/api/_meta/guards`) so consumers
 *     can render their own selectors without round-tripping through PHP.
 *
 * Centralised here so the two callers cannot drift on what counts as a
 * "valid guard" (it's whatever Laravel's `config/auth.php` says).
 */
class GuardCatalog
{
    /**
     * @return list<string> Sorted list of guard names from `config/auth.guards`.
     */
    public static function available(): array
    {
        $guards = (array) config('auth.guards', []);
        $names = array_keys($guards);

        // Stable order so the dropdown always shows guards in the same
        // sequence regardless of array insertion. Casts to string in
        // case a numeric key sneaks in.
        $names = array_map(static fn ($name) => (string) $name, $names);
        sort($names);

        /** @var list<string> $sorted */
        $sorted = $names;

        return $sorted;
    }

    /**
     * Default guard name as configured by Laravel
     * (`config/auth.defaults.guard`), normalised to a string.
     * Returns the literal `'web'` if the key is missing — Laravel's
     * own fallback in fresh installs.
     */
    public static function default(): string
    {
        $default = config('auth.defaults.guard', 'web');

        return is_string($default) && $default !== '' ? $default : 'web';
    }

    /**
     * The guard the panel signs in with: MARTIS_GUARD (`martis.guard`), else
     * the app's default guard.
     */
    public static function martis(): string
    {
        $guard = config('martis.guard');

        return is_string($guard) && $guard !== '' ? $guard : self::default();
    }

    /**
     * The Eloquent model of the users the Martis guard signs in: the model
     * of its provider (`auth.guards.{MARTIS_GUARD}.provider`), the `users`
     * provider's when the guard names none, and `$fallback` when that
     * provider has no model. A relation to "the user" (the audit log, the
     * preferences) points at it, so it names the person who acted in the
     * panel whichever guard signs them in.
     *
     * @return class-string<Model>
     */
    public static function martisUserModel(string $fallback = 'App\\Models\\User'): string
    {
        $provider = config('auth.guards.'.self::martis().'.provider') ?: 'users';
        $model = config("auth.providers.{$provider}.model");

        /** @var class-string<Model> */
        return is_string($model) && $model !== '' ? $model : $fallback;
    }

    /**
     * The user the Martis guard signed in, while that guard is the request's
     * guard: a panel request (MartisAuthenticate calls shouldUse()), or any
     * request when the Martis guard is the app's default. Null elsewhere: a
     * request of another guard (the site's), a job, a command. The audit
     * log's `user()` resolves the Martis guard's model, so a writer records
     * this user as the actor and never the user of another guard, whose id
     * the log would resolve to someone else.
     */
    public static function panelUser(): ?Authenticatable
    {
        return self::requestUsesMartisGuard() ? Auth::guard(self::martis())->user() : null;
    }

    /**
     * Whether the Martis guard is the request's guard: in a panel request
     * (MartisAuthenticate calls shouldUse()), and in every request when the
     * Martis guard is the app's default.
     */
    public static function requestUsesMartisGuard(): bool
    {
        return Auth::getDefaultDriver() === self::martis();
    }

    /**
     * Whether two guards sign in users of the same table (their providers
     * name one connection and table), so that an id of one names the same
     * person in the other.
     */
    public static function sameUsers(string $guard, string $other): bool
    {
        if ($guard === $other) {
            return true;
        }

        $table = self::guardTable($guard);

        return $table !== null && $table === self::guardTable($other);
    }

    /**
     * Whether an id in Laravel's `sessions.user_id` can name users of more
     * than one table. The database session handler writes that column with
     * the id of the request's guard and no table or model: a panel request
     * writes the Martis guard's user, a site request the site guard's. When
     * those guards sign in users of different tables (an `admins` guard for
     * the panel beside the site's `users`), the same id is an admin in one
     * row and a site user in another, and a row cannot be attributed to a
     * person by its id. See {@see sessionUserTables()}.
     */
    public static function sessionUserIdsAreAmbiguous(): bool
    {
        return count(self::sessionUserTables()) > 1;
    }

    /**
     * The tables whose ids the `sessions.user_id` column can hold: those of
     * the providers of the guards that write it (every `session` guard, the
     * Martis guard and the app's default guard), one entry per table
     * (`{connection}|{table}`). A provider whose table cannot be told (a
     * custom driver, a model class that does not exist) counts as a table of
     * its own, so the answer errs on the ambiguous side.
     *
     * @return list<string>
     */
    public static function sessionUserTables(): array
    {
        $guards = (array) config('auth.guards', []);

        $names = [self::martis(), self::default()];
        foreach ($guards as $name => $guard) {
            if (is_array($guard) && ($guard['driver'] ?? null) === 'session') {
                $names[] = (string) $name;
            }
        }

        $tables = [];
        foreach (array_unique($names) as $name) {
            $guard = $guards[$name] ?? null;
            $provider = is_array($guard) ? ($guard['provider'] ?? null) : null;
            if (is_string($provider) && $provider !== '') {
                $tables[self::providerTable($provider)] = true;
            }
        }

        return array_keys($tables);
    }

    /** The `{connection}|{table}` of a guard's users, null when it names no provider. */
    private static function guardTable(string $guard): ?string
    {
        $provider = config("auth.guards.{$guard}.provider");

        return is_string($provider) && $provider !== '' ? self::providerTable($provider) : null;
    }

    /**
     * The `{connection}|{table}` of a user provider's users, or
     * `provider:{name}` when the provider does not say (a custom driver).
     */
    private static function providerTable(string $provider): string
    {
        $config = config("auth.providers.{$provider}");
        $connection = config('database.default');
        $connection = is_string($connection) ? $connection : '';

        if (is_array($config) && ($config['driver'] ?? null) === 'eloquent'
            && is_string($config['model'] ?? null) && is_subclass_of($config['model'], Model::class)) {
            /** @var Model $model */
            $model = new $config['model'];

            return ($model->getConnectionName() ?? $connection).'|'.$model->getTable();
        }

        if (is_array($config) && ($config['driver'] ?? null) === 'database' && is_string($config['table'] ?? null)) {
            $tableConnection = $config['connection'] ?? null;

            return (is_string($tableConnection) && $tableConnection !== '' ? $tableConnection : $connection).'|'.$config['table'];
        }

        return 'provider:'.$provider;
    }
}
