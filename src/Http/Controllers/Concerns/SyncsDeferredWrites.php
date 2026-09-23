<?php

namespace Martis\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\DeferredRelationSync;
use Martis\Fields\DeferredRepeaterSync;

/**
 * Write what the fields' `fill()` could only queue until the record had a
 * primary key: the related ids of a `Tag` field (`DeferredRelationSync`) and
 * the rows of a HasMany or polymorphic `Repeater` (`DeferredRepeaterSync`).
 *
 * Every controller that saves a record through its fields calls it right
 * after the save: the resource's own create and update and the HasMany /
 * HasOne / MorphMany / MorphOne inline forms. A write that skipped it saved
 * the record and dropped those values.
 */
trait SyncsDeferredWrites
{
    protected function syncDeferredWrites(Model $model): void
    {
        DeferredRelationSync::sync($model);
        DeferredRepeaterSync::sync($model);
    }
}
