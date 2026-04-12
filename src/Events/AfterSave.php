<?php

namespace Martis\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

/**
 * Dispatched immediately after a resource model is saved (create or update).
 *
 * The model is already persisted at this point. Use for side effects like
 * sending notifications, updating caches, or triggering jobs.
 *
 * Usage:
 *   Event::listen(AfterSave::class, function (AfterSave $event) {
 *       Cache::forget('users:index');
 *   });
 */
class AfterSave
{
    use Dispatchable;

    /** Create a new after-save event. */
    public function __construct(
        /** The resource class-string that owns this model. */
        public readonly string $resourceClass,

        /** The model that was saved (already has an id). */
        public readonly Model $model,

        /** The originating HTTP request. */
        public readonly Request $request,

        /** Whether this was a create (true) or update (false) operation. */
        public readonly bool $creating,
    ) {}
}
