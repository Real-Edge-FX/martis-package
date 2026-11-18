<?php

namespace Martis\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

/**
 * Dispatched immediately after a soft-deleted resource model is restored.
 *
 * Runs inside the same guard as the restore itself, so a throwable raised
 * here is translated like one raised by the restore.
 *
 * Usage:
 *   Event::listen(AfterRestore::class, function (AfterRestore $event) {
 *       Log::info('Restored', ['id' => $event->model->getKey()]);
 *   });
 */
class AfterRestore
{
    use Dispatchable;

    /** Create the AfterRestore event. */
    public function __construct(
        /** The resource class-string that owns this model. */
        public readonly string $resourceClass,

        /** The model that was just restored. */
        public readonly Model $model,

        /** The originating HTTP request. */
        public readonly Request $request,
    ) {}
}
