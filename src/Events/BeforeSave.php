<?php

namespace Martis\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Martis\Resource;

/**
 * Dispatched immediately before a resource model is saved (create or update).
 *
 * Listeners may inspect or mutate the model before it hits the database.
 * Throwing an exception in a listener will abort the save.
 *
 * Usage:
 *   Event::listen(BeforeSave::class, function (BeforeSave $event) {
 *       $event->model->slug = Str::slug($event->model->title);
 *   });
 */
class BeforeSave
{
    use Dispatchable;

    public function __construct(
        /** The resource class-string that owns this model. */
        public readonly string $resourceClass,

        /** The model being saved (unsaved — changes still apply). */
        public readonly Model $model,

        /** The originating HTTP request. */
        public readonly Request $request,

        /** Whether this is a create (true) or update (false) operation. */
        public readonly bool $creating,
    ) {}
}
