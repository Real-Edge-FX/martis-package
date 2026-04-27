<?php

declare(strict_types=1);

namespace Martis\Sso\PermissionAdapters;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Martis\Sso\Contracts\PermissionAdapterContract;

/**
 * Direct adapter that talks to a `model_has_roles` pivot table
 * without depending on Spatie's trait. Useful when the app has the
 * Spatie schema in place but does NOT use the package itself, or when
 * a custom roles table follows the same shape.
 *
 * The pivot table name and columns are config-driven so non-Spatie
 * schemas can also use this adapter.
 */
class NativeAdapter implements PermissionAdapterContract
{
    public function syncRoles(User $user, Collection $resolvedRoles): void
    {
        $pivotTable = (string) (config('martis.auth.sso.native_pivot.table') ?? 'model_has_roles');
        $modelTypeColumn = (string) (config('martis.auth.sso.native_pivot.model_type_column') ?? 'model_type');
        $modelIdColumn = (string) (config('martis.auth.sso.native_pivot.model_id_column') ?? 'model_id');
        $roleIdColumn = (string) (config('martis.auth.sso.native_pivot.role_id_column') ?? 'role_id');

        $modelType = $user::class;
        $modelId = $user->getKey();

        $resolvedIds = $resolvedRoles->pluck('id')->filter()->all();

        DB::transaction(function () use ($pivotTable, $modelTypeColumn, $modelIdColumn, $roleIdColumn, $modelType, $modelId, $resolvedIds): void {
            DB::table($pivotTable)
                ->where($modelTypeColumn, $modelType)
                ->where($modelIdColumn, $modelId)
                ->delete();

            if ($resolvedIds === []) {
                return;
            }

            $rows = array_map(static fn ($roleId) => [
                $roleIdColumn => $roleId,
                $modelTypeColumn => $modelType,
                $modelIdColumn => $modelId,
            ], $resolvedIds);

            DB::table($pivotTable)->insert($rows);
        });
    }
}
