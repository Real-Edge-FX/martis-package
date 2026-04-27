<?php

declare(strict_types=1);

namespace Martis\Sso;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;

/**
 * Translates a list of external IdP group / role names into a
 * collection of local role models. Three strategies, all opt-in via
 * the per-provider `role_strategy` config:
 *
 *  • `column`   — `Role::query()->whereIn($roleColumn, $externalNames)->get()`.
 *                 Ideal when the host app stores the IdP group name on
 *                 the roles table directly (the most flexible setup).
 *
 *  • `config`   — uses a `role_map` array (`local_slug => env_value`)
 *                 declared in config. Maps the env-resolved group
 *                 identifier to a local role looked up by name.
 *
 *  • `callable` — defers entirely to a host-app closure registered via
 *                 `MartisSso::resolveRolesUsing(fn ($externalRoles, $user, $provider) => ...)`.
 *
 * The mapper does NOT touch the user's pivot table — that's the
 * `PermissionAdapter`'s job.
 */
class RoleMapper
{
    /** @var Closure(array<int, string>, User|null, string): Collection<int, mixed>|null */
    protected static ?Closure $resolver = null;

    /**
     * Override the entire resolution path with a host-app closure.
     * Bypasses every config-driven strategy.
     *
     * @param  Closure(array<int, string>, User|null, string): Collection<int, mixed>  $callback
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
     * @param  array<int, string>  $externalRoles
     * @return Collection<int, mixed>
     */
    public function map(array $externalRoles, ?User $user, string $provider): Collection
    {
        // Host-app override beats every built-in strategy.
        if (static::$resolver !== null) {
            return (static::$resolver)($externalRoles, $user, $provider);
        }

        $cfg = config('martis.auth.sso.providers.'.$provider, []);
        $strategy = $cfg['role_strategy'] ?? 'column';

        return match ($strategy) {
            'column'   => $this->mapByColumn($externalRoles, $cfg),
            'config'   => $this->mapByConfig($externalRoles, $cfg),
            'callable' => $this->mapByCallable($externalRoles, $cfg, $user, $provider),
            default    => new Collection,
        };
    }

    /**
     * @param  array<int, string>  $externalRoles
     * @param  array<string, mixed>  $cfg
     * @return Collection<int, mixed>
     */
    protected function mapByColumn(array $externalRoles, array $cfg): Collection
    {
        $column = $cfg['role_column'] ?? null;
        $modelClass = $cfg['role_model'] ?? $this->detectRoleModel();

        if ($column === null || $modelClass === null || ! class_exists($modelClass) || $externalRoles === []) {
            return new Collection;
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $result = $modelClass::query()->whereIn($column, $externalRoles)->get();

        /** @var Collection<int, mixed> $result */
        return $result;
    }

    /**
     * Config strategy: the `role_map` array is a `slug => env_value`
     * map, and we resolve local roles whose `name` (or configured
     * column) matches the slug for each env_value present in the
     * external list.
     *
     * @param  array<int, string>  $externalRoles
     * @param  array<string, mixed>  $cfg
     * @return Collection<int, mixed>
     */
    protected function mapByConfig(array $externalRoles, array $cfg): Collection
    {
        $map = $cfg['role_map'] ?? [];
        $modelClass = $cfg['role_model'] ?? $this->detectRoleModel();
        $column = $cfg['role_lookup_column'] ?? 'name';

        if (! is_array($map) || $modelClass === null || ! class_exists($modelClass) || $externalRoles === []) {
            return new Collection;
        }

        $localSlugs = [];
        foreach ($map as $slug => $envValue) {
            if ($envValue === null || $envValue === '') {
                continue;
            }
            if (in_array($envValue, $externalRoles, true)) {
                $localSlugs[] = $slug;
            }
        }

        if ($localSlugs === []) {
            return new Collection;
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $result = $modelClass::query()->whereIn($column, $localSlugs)->get();

        /** @var Collection<int, mixed> $result */
        return $result;
    }

    /**
     * Callable strategy declared INSIDE the provider config (separate
     * from the global `MartisSso::resolveRolesUsing`). Useful for the
     * common case of "I just want to wrap one provider" without
     * registering a global hook.
     *
     * @param  array<int, string>  $externalRoles
     * @param  array<string, mixed>  $cfg
     * @return Collection<int, mixed>
     */
    protected function mapByCallable(array $externalRoles, array $cfg, ?User $user, string $provider): Collection
    {
        $callable = $cfg['role_callable'] ?? null;
        if (! is_callable($callable)) {
            return new Collection;
        }

        /** @var Collection<int, mixed> $result */
        $result = $callable($externalRoles, $user, $provider);

        return $result;
    }

    /**
     * Auto-detect the role model. Prefers Spatie's when present (most
     * common host-app setup), falls back to `App\Models\Role` if it
     * exists. Apps with a custom location must set
     * `provider.role_model` explicitly.
     */
    protected function detectRoleModel(): ?string
    {
        $candidates = [
            'Spatie\\Permission\\Models\\Role',
            'App\\Models\\Role',
        ];

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }
}
