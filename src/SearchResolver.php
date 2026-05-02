<?php

namespace Martis;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Contracts\FieldContract;
use Martis\Fields\Field;

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
            // Bind the id list rather than concatenating into the SQL string
            // both to satisfy PHPStan's `literal-string` requirement on
            // orderByRaw() and to avoid a manual quoting hazard.
            $ids = $scoutIds->all();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query->orderByRaw("FIELD({$keyName}, {$placeholders})", $ids);
        }

        return $query;
    }

    /**
     * Execute search via database LIKE queries on searchable fields.
     *
     * Three pipeline stages:
     *   1. Parse `field:value` tokens out of the query and turn each
     *      into a `where(attribute, like, %value%)` constraint scoped
     *      to a single matching field. This trims them from the free
     *      text so the rest of the resolver only sees what's left.
     *   2. Run the remaining free-text against every `searchable()`
     *      field on the resource (LIKE %term%), plus optionally any
     *      attribute reachable via a `searchableRelations()` dot
     *      path (`whereHas` against the related model).
     *   3. Rank by `searchPriority()` so high-weight field hits
     *      bubble above low-weight ones (MySQL only — other drivers
     *      keep the unranked LIKE result set).
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

        // `fields()` may return Section / Panel / TabGroup layout
        // wrappers alongside real FieldContract instances. Flatten the
        // tree FIRST so searchable fields nested inside a layout are
        // discoverable. Iterating the raw array with a top-level
        // `instanceof` guard would silently drop every nested field,
        // turning the resource's full-text search into a no-op (the
        // exact bug reported on the Tasks index, where `title` lives
        // inside a `Section::make('Linkage', [...])`).
        $searchableFields = array_filter(
            Field::flattenLayoutFields($instance->fields($request)),
            fn (FieldContract $field): bool => $field->isSearchable(),
        );

        // Stage 1 — extract `field:value` tokens. Anything that survives
        // the regex is the free-text term.
        [$freeText, $tokens] = static::splitFieldTokens($search);

        $byAttribute = [];
        foreach ($searchableFields as $field) {
            $byAttribute[$field->attribute()] = $field;
        }

        $appliedTokens = 0;
        foreach ($tokens as $token) {
            // Only apply the token when the resource declares the
            // attribute as searchable; unknown field:value pairs are
            // silently dropped (we'd rather return "no matches" than
            // accidentally match everything from a typo).
            if (! isset($byAttribute[$token['field']])) {
                continue;
            }
            $value = $token['value'];
            $query->where(function (Builder $q) use ($byAttribute, $token, $value): void {
                $q->where($byAttribute[$token['field']]->attribute(), 'like', "%{$value}%");
            });
            $appliedTokens++;
        }

        // Refuse to return anything when the user typed only field:value
        // tokens, none of them applied, and there's no free-text fallback.
        // Without this guard the unfiltered query would dump every row.
        if ($appliedTokens === 0 && $tokens !== [] && $freeText === '') {
            return $query->whereRaw('1 = 0');
        }

        if ($searchableFields === [] && $freeText === '') {
            return $query;
        }

        $relations = $resourceClass::searchableRelations();

        // Stage 2 — free-text LIKE across the resource's own searchable
        // fields plus any declared relation paths. The whole disjunction
        // sits inside one `where(Closure)` so it composes correctly with
        // any AND filters already on the builder.
        if ($freeText !== '' && ($searchableFields !== [] || $relations !== [])) {
            $query->where(function (Builder $q) use ($searchableFields, $relations, $freeText): void {
                foreach ($searchableFields as $field) {
                    $q->orWhere($field->attribute(), 'like', "%{$freeText}%");
                }

                foreach ($relations as $path) {
                    $segments = explode('.', $path);
                    if (count($segments) < 2) {
                        continue;
                    }
                    $attribute = array_pop($segments);
                    $relation = implode('.', $segments);

                    $q->orWhereHas($relation, function (Builder $sub) use ($attribute, $freeText): void {
                        $sub->where($attribute, 'like', "%{$freeText}%");
                    });
                }
            });
        }

        // Stage 3 — priority ranking. Build a single `CASE` expression
        // that scores each row by the highest matching field's weight,
        // then ORDER BY that score DESC. MySQL-only because other
        // drivers tolerate the bind list shape less reliably; the
        // result set is unchanged on other engines, just unranked.
        if ($freeText !== '' && $searchableFields !== []) {
            $connection = $query->getConnection();
            $driver = method_exists($connection, 'getDriverName')
                ? $connection->getDriverName()
                : '';

            if ($driver === 'mysql') {
                $cases = [];
                $bindings = [];
                $like = '%'.$freeText.'%';
                foreach ($searchableFields as $field) {
                    $cases[] = 'WHEN '.$connection->getQueryGrammar()->wrap($field->attribute()).' LIKE ? THEN '.((int) $field->getSearchPriority());
                    $bindings[] = $like;
                }
                if ($cases !== []) {
                    $expr = 'CASE '.implode(' ', $cases).' ELSE 0 END';
                    $query->orderByRaw($expr.' DESC', $bindings);
                }
            }
        }

        return $query;
    }

    /**
     * Pull `field:value` tokens out of the query and return the
     * remainder as free-text along with the parsed tokens.
     *
     * Handles unquoted (`status:open`) and quoted (`status:"open issue"`)
     * values. The free-text return is trimmed of double-spaces left
     * behind by the strip.
     *
     * @return array{0: string, 1: list<array{field: string, value: string}>}
     */
    protected static function splitFieldTokens(string $search): array
    {
        $tokens = [];

        // The regex matches: word characters, `:`, then either a quoted
        // value or a non-whitespace run.
        $pattern = '/(?P<field>[a-zA-Z_][\w]*):(?:"(?P<quoted>[^"]*)"|(?P<bare>\S+))/';

        $remainder = preg_replace_callback(
            $pattern,
            function (array $match) use (&$tokens): string {
                $tokens[] = [
                    'field' => $match['field'],
                    'value' => $match['quoted'] !== '' ? $match['quoted'] : ($match['bare'] ?? ''),
                ];

                return '';
            },
            $search,
        ) ?? $search;

        return [trim(preg_replace('/\s+/', ' ', $remainder) ?? ''), $tokens];
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
