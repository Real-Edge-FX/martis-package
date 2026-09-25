<?php

namespace Martis\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Resource;

/**
 * Confine a query of a resource's own model as its index confines it: the
 * resource's declarative `scopes()`, then `indexQuery()`, in the index's
 * order. The index and its menu badge, the global search, the records an
 * action runs on and the parent record of the BelongsToMany panel and of
 * the pivot routes go through it; relationship panels go through
 * `RelationScope`, which runs the same hooks (`hooks()`) the same way
 * (`grouped()`).
 *
 * The hooks run as Eloquent runs a local scope (`grouped()`), which wraps
 * the where clauses they add in one group when one of them is an `or`. A
 * constraint the caller adds afterwards (a filter, a search term, a key,
 * the selected ids) then binds to all of them: after an ungrouped
 * `where(A)->orWhere(B)`, a filter `F` would read `A or (B and F)` and list
 * every record of A, and a `whereKey($id)` would resolve any record of A,
 * or run an action on all of them. A hook that only adds `and` clauses
 * leaves the SQL as it was.
 *
 * @internal
 */
final class IndexScope
{
    /**
     * Run the resource's `scopes()`, then `indexQuery()`, on `$query`,
     * grouped, and return what `indexQuery()` returns. A hook that returns
     * another builder than the one it received is used as it returns it.
     *
     * @param  class-string<resource>  $resourceClass
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function apply(Request $request, string $resourceClass, Builder $query): Builder
    {
        /** @var Builder<Model> $scoped */
        $scoped = self::grouped($query, static fn (Builder $scoped): Builder => self::hooks($request, $resourceClass, $scoped));

        return $scoped;
    }

    /**
     * The resource's `scopes()`, then `indexQuery()`, run on `$query` as
     * they are (ungrouped). Callers group them with `grouped()`, or run them
     * on a fresh query whose keys they read (`RelationScope::constrainByKey()`).
     *
     * @param  class-string<resource>  $resourceClass
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function hooks(Request $request, string $resourceClass, Builder $query): Builder
    {
        return $resourceClass::indexQuery($request, $resourceClass::applyScopes($request, $query));
    }

    /**
     * Run `$callback` (a user hook: a scope, a filter, a relatable query) on
     * `$query` as Eloquent runs a local scope (`Builder::callScope()`): the
     * where clauses it adds become one nested group when one of them is an
     * `or`, and the clauses `$query` already holds become another, so no
     * `or` on either side widens the other. Returns what `$callback`
     * returns, `null` included.
     *
     * The group joins the query with the boolean of the first clause the
     * callback adds: one that starts with `orWhere()` would otherwise OR the
     * whole group with the clauses before it (a relation's own constraint,
     * `onlyTrashed()`). It is read as `and`, which is what the grammar makes
     * of it on a query with no clause before it.
     *
     * @param  Builder<Model>  $query
     * @param  Closure(Builder<Model>): mixed  $callback
     */
    public static function grouped(Builder $query, Closure $callback): mixed
    {
        $before = count($query->getQuery()->wheres);
        $result = null;

        $scope = function (Builder $scoped) use ($callback, $before, &$result): void {
            $result = $callback($scoped);

            $wheres = &$scoped->getQuery()->wheres;
            if (isset($wheres[$before]) && str_starts_with((string) $wheres[$before]['boolean'], 'or')) {
                $wheres[$before]['boolean'] = 'and'.substr((string) $wheres[$before]['boolean'], 2);
            }
        };

        // callScope() is protected: run it as the builder itself.
        (fn () => $this->callScope($scope))->call($query);

        return $result;
    }
}
