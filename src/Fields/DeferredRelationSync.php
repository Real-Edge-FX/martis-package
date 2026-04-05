<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use WeakMap;

/**
 * Static registry for deferred many-to-many relationship syncs.
 *
 * BelongsTo fields in multiple mode call register() during fill().
 * The ResourceController calls sync() after the model is saved.
 *
 * Uses a WeakMap keyed by model instances so entries are automatically
 * garbage-collected when the model goes out of scope.
 */
class DeferredRelationSync
{
    /** @var WeakMap<Model, array<string, list<int|string>>> */
    private static ?WeakMap $pending = null;

    /**
     * Register a relationship sync to be executed after save.
     *
     * @param  list<int|string>  $ids
     */
    public static function register(Model $model, string $relationship, array $ids): void
    {
        self::$pending ??= new WeakMap;

        $existing = self::$pending[$model] ?? [];
        $existing[$relationship] = $ids;
        self::$pending[$model] = $existing;
    }

    /**
     * Execute all pending syncs for a model, then clear them.
     */
    public static function sync(Model $model): void
    {
        if (self::$pending === null) {
            return;
        }

        $deferred = self::$pending[$model] ?? [];

        foreach ($deferred as $relationship => $ids) {
            $camelRelationship = Str::camel($relationship);
            if (method_exists($model, $relationship)) {
                $model->{$relationship}()->sync($ids);
            } elseif ($camelRelationship !== $relationship && method_exists($model, $camelRelationship)) {
                $model->{$camelRelationship}()->sync($ids);
            }
        }

        unset(self::$pending[$model]);
    }
}
