<?php

namespace Martis\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Martis\Resource;
use Throwable;

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
 *    panel as an Eloquent scope (`IndexScope::grouped()`), which wraps what they add
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
 *    rows there. The fresh query leaves out the global scopes the
 *    relationship removes, so the keys never hide a row it keeps.
 */
final class RelationScope
{
    /**
     * Apply the hooks to `$query` in place, grouped. A hook that returns
     * another builder than the one it received constrains `$query` by key.
     * The relation's query keeps its own trashed state (the relation's
     * definition, the panel's filter) and the global scopes it removes.
     *
     * @param  Builder<Model>  $query  A plain has-many / morph-many relation's query.
     * @param  class-string<resource>  $relatedResourceClass
     */
    public static function apply(Request $request, Builder $query, string $relatedResourceClass): void
    {
        // Grouped as Eloquent groups a local scope (IndexScope::grouped()):
        // an `orWhere()` in a hook, even a leading one, stays inside the
        // group and cannot OR the relation's own constraint away.
        IndexScope::grouped($query, function (Builder $scoped) use ($request, $relatedResourceClass): void {
            $result = IndexScope::hooks($request, $relatedResourceClass, $scoped);

            // A builder the hook made itself carries every global scope of
            // its model: the ones the relation removes do not filter the keys.
            if ($result !== $scoped) {
                $scoped->whereIn(self::keyColumn($scoped), self::keys(
                    $result->withoutGlobalScope(SoftDeletingScope::class)->withoutGlobalScopes($scoped->removedScopes())
                ));
            }
        });
    }

    /**
     * Keep the rows of `$query` whose key the hooks return, run on a fresh
     * query of the related model. The keys ignore the soft-delete scope:
     * `$query` already decides which trashed records it keeps (a relation
     * defined `withTrashed()`, the panel's `?trashed` filter). They ignore,
     * too, every global scope `$query` removes: a relation defined
     * `->withoutGlobalScope(Archived::class)` keeps its archived records, as
     * it does without hooks and as Nova's relationship index, which runs the
     * hooks on the relation's own query. The hooks receive the fresh query
     * without those scopes, and see the rows the relation reaches.
     *
     * @param  Builder<Model>  $query  Any query over the related model's table, carrying the relation's removed scopes.
     * @param  class-string<resource>  $relatedResourceClass
     */
    public static function constrainByKey(Request $request, Builder $query, string $relatedResourceClass): void
    {
        $removed = $query->removedScopes();
        $fresh = $relatedResourceClass::newModel()->newQuery()->withoutGlobalScopes($removed);

        // Dropped again from what the hooks return, the fresh query or one
        // they built themselves, which carries every scope of its model.
        $result = IndexScope::hooks($request, $relatedResourceClass, $fresh)
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->withoutGlobalScopes($removed);

        $query->whereIn(self::keyColumn($query), self::keys($result));
    }

    /**
     * The global scopes relation `$relationship` of `$model` removes
     * (`->withoutGlobalScope()`, `->withTrashed()`), read from the relation
     * as `withCount()` resolves it: on the model of the listing's query,
     * which holds no record, without its constraints. Null when the method
     * gives no relation there: it throws (it reads an attribute only a
     * loaded record has) or returns something else, so it cannot be counted
     * in the listing's query either.
     *
     * @return list<string>|null
     */
    public static function removedScopesOf(Model $model, string $relationship): ?array
    {
        try {
            $relation = Relation::noConstraints(fn () => $model->{$relationship}());
        } catch (Throwable) {
            return null;
        }

        return $relation instanceof Relation ? array_values($relation->getQuery()->removedScopes()) : null;
    }

    /**
     * The keys `$result` selects, as a subquery for `IN (...)`: its select,
     * order, limit and offset dropped (a `select()` would return several
     * columns, and MySQL refuses a LIMIT there). A lens action reads the
     * records its lens lists through it too.
     *
     * @param  Builder<Model>  $result
     */
    public static function keys(Builder $result): QueryBuilder
    {
        $keys = $result->toBase()->reorder();
        $keys->limit = null;
        $keys->offset = null;

        // A HAVING may read an alias the hook selects (`withCount()` then
        // `having('likes_count', ...)`): keep its select and read the key
        // from it as a derived table, which MySQL accepts in `IN (...)`.
        if (! empty($keys->havings)) {
            return $keys->newQuery()->fromSub($keys, 'martis_keys')->select('martis_keys.'.$result->getModel()->getKeyName());
        }

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
