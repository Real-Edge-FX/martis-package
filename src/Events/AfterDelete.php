<?php

namespace Martis\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

/**
 * Dispatched immediately after a resource model is deleted (or soft-deleted).
 *
 * Use for post-deletion side effects: cascade cleanups, audit logs, cache busting.
 *
 * Usage:
 *   Event::listen(AfterDelete::class, function (AfterDelete $event) {
 *       AuditLog::record('deleted', $event->resourceClass, $event->model->id);
 *   });
 */
class AfterDelete
{
    use Dispatchable;

    /** Create the AfterDelete event. */
    public function __construct(
        /** The resource class-string that owns this model. */
        public readonly string $resourceClass,

        /** The model that was deleted (may be soft-deleted — check $model->trashed()). */
        public readonly Model $model,

        /** The originating HTTP request. */
        public readonly Request $request,
    ) {}
}
