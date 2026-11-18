<?php

namespace Martis\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

/**
 * Dispatched immediately before a soft-deleted resource model is restored.
 *
 * Listeners may throw an exception to abort the restore. A
 * `Martis\Exceptions\ValidationException` (or Laravel's own) answers 422
 * with field errors; any other throwable answers 500 and is logged.
 *
 * Usage:
 *   Event::listen(BeforeRestore::class, function (BeforeRestore $event) {
 *       if ($event->model->slot_taken) {
 *           throw ValidationException::fromFieldErrors(['kind' => ['Taken.']]);
 *       }
 *   });
 */
class BeforeRestore
{
    use Dispatchable;

    /** Create the BeforeRestore event. */
    public function __construct(
        /** The resource class-string that owns this model. */
        public readonly string $resourceClass,

        /** The trashed model about to be restored. */
        public readonly Model $model,

        /** The originating HTTP request. */
        public readonly Request $request,
    ) {}
}
