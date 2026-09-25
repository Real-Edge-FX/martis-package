<?php

namespace Martis\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
 */
final class RelationScope
{
    /**
     * Apply the hooks to `$query` in place. A hook that returns another
     * builder than the one it received constrains `$query` by key.
     *
     * @param  Builder<Model>  $query
     * @param  class-string<resource>  $relatedResourceClass
     */
    public static function apply(Request $request, Builder $query, string $relatedResourceClass): void
    {
        $scoped = $relatedResourceClass::indexQuery($request, $relatedResourceClass::applyScopes($request, $query));

        if ($scoped === $query) {
            return;
        }

        $keys = $scoped->toBase()->reorder();
        $keys->limit = null;
        $keys->offset = null;

        $query->whereIn($query->getModel()->getQualifiedKeyName(), $keys->select($scoped->getModel()->getQualifiedKeyName()));
    }
}
