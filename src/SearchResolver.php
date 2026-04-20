<?php

namespace Martis;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Contracts\FieldContract;

/**
 * Central resolver for resource search — decides between Scout and database.
 *
 * When a resource model uses Laravel\Scout\Searchable and the resource has
 * not overridden usesScout() to return false, searches go through Scout.
 * Otherwise, the standard database LIKE pipeline is used.
 *
 * This class is the SINGLE point of truth for the search decision. Controllers
 * must not duplicate this logic.
 *
 * @see \Martis\Resource::usesScout()
 * @see \Martis\Resource::scoutQuery()
 */
class SearchResolver
{
    /**
     * Execute search on the resource index query.
     *
     * When Scout is active for the resource:
     *   1. Runs the search term through Scout (model::search())
     *   2. Applies scoutQuery() customisation
     *   3. Applies scoutSearchResults limit
     *   4. Returns model IDs from Scout, then constrains the Eloquent builder
     *      to those IDs (preserving Scout relevance order)
     *
     * When Scout is NOT active:
     *   Falls back to the standard database LIKE search on searchable fields.
     *
     * @param  Builder<Model>  $query  The Eloquent query (already has indexQuery applied)
     * @param  class-string<\Martis\Resource>  $resourceClass
     * @param  string  $search  The trimmed search term
     * @return Builder<Model> The modified query
     */
    public static function apply(
        Request $request,
        Builder $query,
        string $resourceClass,
        string $search,
    ): Builder {
        if ($search === '') {
            return $query;
        }

        if ($resourceClass::usesScout()) {
            return static::applyScoutSearch($request, $query, $resourceClass, $search);
        }

        return static::applyDatabaseSearch($request, $query, $resourceClass, $search);
    }

    /**
     * Execute search via Laravel Scout.
     *
     * @param  Builder<Model>  $query
     * @param  class-string<\Martis\Resource>  $resourceClass
     * @return Builder<Model>
     */
    protected static function applyScoutSearch(
        Request $request,
        Builder $query,
        string $resourceClass,
        string $search,
    ): Builder {
        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();

        // Build the Scout query
        /** @phpstan-ignore staticMethod.notFound */
        $scoutBuilder = $modelClass::search($search);

        // Apply scoutSearchResults limit
        $limit = $resourceClass::$scoutSearchResults;
        if ($limit !== null) {
            $scoutBuilder->take($limit);
        }

        // Apply resource scoutQuery() customisation
        $scoutBuilder = $resourceClass::scoutQuery($request, $scoutBuilder);

        // Get IDs from Scout and constrain the Eloquent builder
        /** @var Collection<int, mixed> $scoutIds */
        $scoutIds = $scoutBuilder->keys();
        if ($scoutIds->isEmpty()) {
            // No results — force empty result set
            $query->whereRaw('1 = 0');

            return $query;
        }

        $keyName = $query->getModel()->getQualifiedKeyName();

        $query->whereIn($keyName, $scoutIds->all());

        // Preserve Scout relevance order via FIELD()
        // This works on MySQL; for other drivers, results will be unordered
        // but still correctly filtered.
        $connection = $query->getConnection();
        $driverName = $connection instanceof Connection
            ? $connection->getDriverName()
            : '';
        if ($driverName === 'mysql') {
            $ids = $scoutIds->implode(',');
            $query->orderByRaw("FIELD({$keyName}, {$ids})");
        }

        return $query;
    }

    /**
     * Execute search via database LIKE queries on searchable fields.
     *
     * @param  Builder<Model>  $query
     * @param  class-string<\Martis\Resource>  $resourceClass
     * @return Builder<Model>
     */
    protected static function applyDatabaseSearch(
        Request $request,
        Builder $query,
        string $resourceClass,
        string $search,
    ): Builder {
        $instance = new $resourceClass;
        $searchableFields = array_filter(
            $instance->fields($request),
            fn (FieldContract $field): bool => $field->isSearchable(),
        );

        if (empty($searchableFields)) {
            return $query;
        }

        $query->where(function (Builder $q) use ($searchableFields, $search): void {
            foreach ($searchableFields as $field) {
                $q->orWhere($field->attribute(), 'like', "%{$search}%");
            }
        });

        return $query;
    }

    /**
     * Check whether a given resource class is currently using Scout.
     *
     * Useful for schema/API metadata so the frontend can know
     * which search engine is active for a given resource.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     */
    public static function isUsingScout(string $resourceClass): bool
    {
        return $resourceClass::usesScout();
    }
}
