<?php

namespace Martis\Fields\Concerns;

use Closure;
use Illuminate\Http\Request;
use Martis\Actions\Action;

/**
 * Pivot actions declared on a many-to-many relationship field.
 *
 * `BelongsToMany` and `MorphToMany` use it. The actions the closure returns
 * belong to that relationship's panel: the pivot action routes list, describe
 * and run them for this relationship only, and `handle()` receives the
 * records attached through it, each carrying its `pivot` row. An action
 * declared here does not need `->pivotAction()`; the field marks it.
 *
 * Resource actions flagged `->pivotAction()` still appear on every
 * many-to-many panel of the resource, after the ones declared here.
 */
trait HasPivotActions
{
    /** Closure that returns the pivot actions of this relationship. */
    protected ?Closure $pivotActionsClosure = null;

    /**
     * Define the pivot actions for the records attached through this
     * relationship. The closure receives the current request.
     *
     * @param  Closure(Request): list<Action>  $closure
     */
    public function actions(Closure $closure): static
    {
        $this->pivotActionsClosure = $closure;

        return $this;
    }

    /**
     * Resolve the pivot actions declared with {@see self::actions()}.
     * Anything the closure returns that is not an `Action` is ignored.
     *
     * @return list<Action>
     */
    public function pivotActions(Request $request): array
    {
        if ($this->pivotActionsClosure === null) {
            return [];
        }

        $declared = ($this->pivotActionsClosure)($request);
        if (! is_iterable($declared)) {
            return [];
        }

        $actions = [];
        foreach ($declared as $action) {
            if ($action instanceof Action) {
                $actions[] = $action->pivotAction();
            }
        }

        return $actions;
    }
}
