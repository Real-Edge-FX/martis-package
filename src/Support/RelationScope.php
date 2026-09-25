<?php

namespace Martis\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Martis\Resource;

/**
 * Scope the records of a relationship as the related resource's index
 * scopes them: its declarative `scopes()`, then `indexQuery()`, in the
 * index's order. Relationship panels, their counts and the one-record
 * cards use it, so a row the related index hides (tenancy, visibility) is
 * hidden, and not counted, wherever the relationship shows it. Nova runs
 * the related resource's `indexQuery()` on its relationship index
 * (nova-issues #971, #3655, #337).
 *
 * The hooks are written for the related resource's own index query, so
 * they are never simply run on the relationship's query:
 *
 *  - `apply()` runs them on the query of a plain `hasMany` / `morphMany`
 *    panel as an Eloquent scope (`callScope()`), which wraps what they add
 *    in its own group: an `orWhere()` in a hook cannot widen the panel to
 *    another parent's rows (nova-issues #3655). The hook's order, joins and
 *    select aliases stay, as on the index.
 *  - `constrainByKey()` runs them on a fresh query of the related model
 *    and keeps the relationship's rows whose key it returns. Through and
 *    pivot panels join another table, and every count runs inside another
 *    query (the parent's index, the one-of-many card), where a hook's
 *    `select()`, a join (to the parent, the pivot or the intermediate
 *    table) or a column the joined table also has would break the query or
 *    its link to the parent. The hook's order and aliases do not reach the
 *    rows there.
 */
final class RelationScope
{
    /**
     * Apply the hooks to `$query` in place, grouped. A hook that returns
     * another builder than the one it received constrains `$query` by key.
     *
     * @param  Builder<Model>  $query  A plain has-many / morph-many relation's query.
     * @param  class-string<resource>  $relatedResourceClass
     * @param  bool  $withTrashed  Whether the panel lists trashed records (`?trashed=with|only`).
     */
    public static function apply(Request $request, Builder $query, string $relatedResourceClass, bool $withTrashed = false): void
    {
        $scope = function (Builder $scoped) use ($request, $relatedResourceClass, $withTrashed): void {
            $result = $relatedResourceClass::indexQuery($request, $relatedResourceClass::applyScopes($request, $scoped));

            if ($result !== $scoped) {
                if ($withTrashed) {
                    $result->withoutGlobalScope(SoftDeletingScope::class);
                }

                $scoped->whereIn(self::keyColumn($scoped), self::keys($result));
            }
        };

        // callScope() is how Eloquent runs a local scope: it groups the
        // wheres the scope adds, so their `or` stays inside the group.
        (fn () => $this->callScope($scope))->call($query);
    }

    /**
     * Keep the rows of `$query` whose key the hooks return, run on a fresh
     * query of the related model.
     *
     * @param  Builder<Model>  $query  Any query over the related model's table.
     * @param  class-string<resource>  $relatedResourceClass
     * @param  bool  $withTrashed  Whether the caller lists trashed records.
     */
    public static function constrainByKey(Request $request, Builder $query, string $relatedResourceClass, bool $withTrashed = false): void
    {
        $fresh = $relatedResourceClass::newModel()->newQuery();

        if ($withTrashed) {
            $fresh->withoutGlobalScope(SoftDeletingScope::class);
        }

        $result = $relatedResourceClass::indexQuery($request, $relatedResourceClass::applyScopes($request, $fresh));

        if ($withTrashed) {
            $result->withoutGlobalScope(SoftDeletingScope::class);
        }

        $query->whereIn(self::keyColumn($query), self::keys($result));
    }

    /**
     * The keys `$result` selects, as a subquery for `IN (...)`: its select,
     * order, limit and offset dropped (a `select()` would return several
     * columns, and MySQL refuses a LIMIT there).
     *
     * @param  Builder<Model>  $result
     */
    private static function keys(Builder $result): QueryBuilder
    {
        $keys = $result->toBase()->reorder();
        $keys->limit = null;
        $keys->offset = null;

        return $keys->select($result->getModel()->getQualifiedKeyName());
    }

    /**
     * The key column of `$query`'s table, under the alias it is read from
     * (a self-referencing count reads the related table under an alias).
     *
     * @param  Builder<Model>  $query
     */
    private static function keyColumn(Builder $query): string
    {
        $from = $query->getQuery()->from;
        $table = is_string($from) && preg_match('/\s+as\s+(\S+)$/i', $from, $match) === 1
            ? $match[1]
            : $query->getModel()->getTable();

        return $table.'.'.$query->getModel()->getKeyName();
    }
}
