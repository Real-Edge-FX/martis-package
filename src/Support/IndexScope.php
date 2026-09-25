<?php

namespace Martis\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Resource;

/**
 * Confine a query of a resource's own model as its index confines it: the
 * resource's declarative `scopes()`, then `indexQuery()`, in the index's
 * order. The records an action runs on are looked up through it.
 *
 * The hooks run as Eloquent runs a local scope (`callScope()`), which wraps
 * the where clauses they add in one group when one of them is an `or`. A
 * constraint the caller adds afterwards (a key, the selected ids) then binds
 * to all of them: after an ungrouped `where(A)->orWhere(B)`, a
 * `whereIn($key, $ids)` would read `A or (B and id in (...))` and run an
 * action on every record of A. A hook that only adds `and` clauses leaves
 * the SQL as it was.
 *
 * @internal
 */
final class IndexScope
{
    /**
     * Run the resource's `scopes()`, then `indexQuery()`, on `$query`, a
     * fresh query of the resource's model, and return what `indexQuery()`
     * returns. A hook that returns another builder than the one it received
     * is used as it returns it.
     *
     * @param  class-string<resource>  $resourceClass
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function apply(Request $request, string $resourceClass, Builder $query): Builder
    {
        $hooks = static fn (Builder $scoped): Builder => $resourceClass::indexQuery($request, $resourceClass::applyScopes($request, $scoped));

        // callScope() is protected: run it as the builder itself.
        /** @var Builder<Model> $scoped */
        $scoped = (fn (): mixed => $this->callScope($hooks))->call($query);

        return $scoped;
    }
}
