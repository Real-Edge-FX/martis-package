<?php

namespace Martis\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

/**
 * Dispatched immediately before a resource model is deleted (or soft-deleted).
 *
 * Listeners may throw an exception to abort the deletion.
 *
 * Usage:
 *   Event::listen(BeforeDelete::class, function (BeforeDelete $event) {
 *       if ($event->model->is_protected) {
 *           throw new \RuntimeException('Cannot delete protected records.');
 *       }
 *   });
 */
class BeforeDelete
{
    use Dispatchable;

    /** Create a new before-delete event. */
    public function __construct(
        /** The resource class-string that owns this model. */
        public readonly string $resourceClass,

        /** The model about to be deleted (still in DB). */
        public readonly Model $model,

        /** The originating HTTP request. */
        public readonly Request $request,
    ) {}
}
