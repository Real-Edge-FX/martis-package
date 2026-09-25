<?php

namespace Martis\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * Helper that exposes the auth guards configured by the host app.
 *
 * Used by:
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
        $guard = config('martis.guard') ?: self::default();
        $provider = config("auth.guards.{$guard}.provider") ?: 'users';
        $model = config("auth.providers.{$provider}.model");

        /** @var class-string<Model> */
        return is_string($model) && $model !== '' ? $model : $fallback;
    }
}
