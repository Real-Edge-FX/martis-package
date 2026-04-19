<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use WeakMap;

/**
 * Pending HasMany repeater rows, indexed per-parent.
 *
 * The {@see Repeater} field's `fill()` registers the normalised rows here
 * because they can only be persisted after the parent has been saved (and
 * therefore has a primary key). The ResourceController flushes the queue
 * via {@see self::sync()} right after `$model->save()`.
 *
 * The underlying WeakMap releases each entry automatically once the parent
 * goes out of scope, so there is no long-lived state to clean up.
 */
class DeferredRepeaterSync
{
    /** @var WeakMap<Model, list<array{field: Repeater, rows: list<array<string, mixed>>}>>|null */
    private static ?WeakMap $pending = null;

    /**
     * Queue the rows to be written after the parent is saved.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function register(Model $parent, Repeater $field, array $rows): void
    {
        self::$pending ??= new WeakMap;

        $queue = self::$pending[$parent] ?? [];
        $queue[] = ['field' => $field, 'rows' => $rows];
        self::$pending[$parent] = $queue;
    }

    /** Flush every queued repeater sync for the given parent. */
    public static function sync(Model $parent): void
    {
        if (self::$pending === null) {
            return;
        }
        $queue = self::$pending[$parent] ?? [];
        foreach ($queue as $entry) {
            /** @var Repeater $field */
            $field = $entry['field'];
            /** @var list<array<string, mixed>> $rows */
            $rows = $entry['rows'];
            $field->saveRelated($parent, $rows);
        }

        unset(self::$pending[$parent]);
    }
}
