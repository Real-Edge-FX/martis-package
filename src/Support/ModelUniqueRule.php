<?php

declare(strict_types=1);

namespace Martis\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * A `unique` rule on the table of a model.
 *
 * Laravel's `Rule::unique(Model::class)` resolves the class to its
 * connection and table only when the class name has a namespace: a model
 * declared without one (an inline model of a test suite) is taken for a
 * table name. This builds, from the model itself, the `connection.table`
 * Laravel builds for a namespaced class.
 */
final class ModelUniqueRule
{
    public static function make(Model $model, string $column): Unique
    {
        $table = $model->getTable();

        // A schema-qualified table keeps Laravel's resolution of the class,
        // which reads the connection and the table from the model.
        if (str_contains($table, '.')) {
            return Rule::unique($model::class, $column);
        }

        $connection = $model->getConnectionName();

        return Rule::unique($connection !== null && $connection !== '' ? $connection.'.'.$table : $table, $column);
    }
}
