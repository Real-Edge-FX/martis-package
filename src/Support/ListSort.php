<?php

namespace Martis\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Martis\Contracts\FieldContract;
use Martis\Enums\SortDirection;
use Martis\Fields\Field;
use Martis\Resource;

/**
 * The ordering of a list, in one place: the resource index, a lens and the
 * relationship panels (HasMany, MorphMany, through, pivot) parse `?sort=`
 * and order their query here, so no surface drifts from another.
 *
 * Format of `?sort=`: a comma-separated list of `attribute[:direction]`
 * (`category:asc,position:asc,locale:desc`), a missing direction being
 * `asc`. One key without a suffix still takes `&direction=`
 * (`?sort=title&direction=desc`). At most {@see self::MAX_KEYS} keys count,
 * and a repeated attribute keeps its first occurrence.
 *
 * The order a list gets: the requested keys, then the `defaultSort()` keys
 * the request did not name (so the default breaks ties), then the model's
 * qualified primary key ascending (so pagination is stable). A key that is
 * not a sortable field the user can see is dropped silently, the others
 * still apply: request input is never a loud failure.
 *
 * @internal
 */
final class ListSort
{
    /** Keys past this many are ignored. */
    public const MAX_KEYS = 3;

    /**
     * The keys `?sort=` (and `?direction=` for a lone key) names. Not
     * validated against any field: see `apply()`.
     *
     * @return list<SortKey>
     */
    public static function fromRequest(Request $request): array
    {
        return self::parse($request->query('sort'), $request->query('direction'));
    }

    /**
     * @return list<SortKey>
     */
    public static function parse(mixed $sort, mixed $direction = null): array
    {
        if (! is_string($sort) || trim($sort) === '') {
            return [];
        }

        $pieces = array_values(array_filter(
            array_map('trim', explode(',', $sort)),
            static fn (string $piece): bool => $piece !== '',
        ));

        $keys = [];

        foreach ($pieces as $piece) {
            $attribute = $piece;
            $keyDirection = count($pieces) === 1 ? SortDirection::fromQuery($direction) : SortDirection::Asc;

            $colon = strrpos($piece, ':');
            if ($colon !== false) {
                $suffix = SortDirection::tryFrom(strtolower(substr($piece, $colon + 1)));

                // A suffix that is no direction makes the key unusable (it
                // would name an attribute that does not exist anyway).
                if ($suffix === null) {
                    continue;
                }

                $attribute = trim(substr($piece, 0, $colon));
                $keyDirection = $suffix;
            }

            if ($attribute === '' || isset($keys[$attribute])) {
                continue;
            }

            $keys[$attribute] = new SortKey($attribute, $keyDirection);

            if (count($keys) === self::MAX_KEYS) {
                break;
            }
        }

        return array_values($keys);
    }

    /**
     * The keys `$resourceClass::defaultSort()` declares, in order: a string
     * (with `defaultSortDirection()`) is one key, an array maps attribute
     * to a `SortDirection` or `'asc'|'desc'`.
     *
     * A value that is none of those is a mistake in the code, not request
     * input: it throws, naming the resource and the key.
     *
     * @param  class-string<resource>  $resourceClass
     * @return list<SortKey>
     *
     * @throws InvalidArgumentException
     */
    public static function defaults(string $resourceClass): array
    {
        $default = $resourceClass::defaultSort();

        if ($default === null || $default === '' || $default === []) {
            return [];
        }

        if (is_string($default)) {
            return [new SortKey($default, $resourceClass::defaultSortDirection())];
        }

        $keys = [];

        foreach ($default as $attribute => $value) {
            if (! is_string($attribute) || $attribute === '') {
                throw new InvalidArgumentException(sprintf(
                    '%s::defaultSort() must map attribute names to directions (["name" => SortDirection::Asc]); key [%s] is not an attribute name.',
                    $resourceClass,
                    (string) $attribute,
                ));
            }

            $direction = $value instanceof SortDirection
                ? $value
                : (is_string($value) ? SortDirection::tryFrom($value) : null);

            if ($direction === null) {
                throw new InvalidArgumentException(sprintf(
                    '%s::defaultSort() has an invalid direction for key [%s]: use a SortDirection or "asc"/"desc".',
                    $resourceClass,
                    $attribute,
                ));
            }

            $keys[] = new SortKey($attribute, $direction);
        }

        return $keys;
    }

    /**
     * Order `$query` by `$requested`, then the `$defaults` not already in
     * it, then the primary key.
     *
     * Only an attribute of `$sortable` (see `Field::sortableFields()`)
     * orders the list; a field with a `sortUsing()` closure orders through
     * it, any other attribute as a column.
     *
     * @param  Builder<Model>  $query
     * @param  array<string, FieldContract>  $sortable
     * @param  list<SortKey>  $requested
     * @param  list<SortKey>  $defaults
     * @param  bool  $qualifyJsonPaths  Qualify a JSON path (`meta->code`) with the
     *                                  query's table: a panel whose relation joins a table by
     *                                  nature (hasManyThrough, a pivot) sets it.
     */
    public static function apply(Builder $query, array $sortable, array $requested, array $defaults = [], bool $qualifyJsonPaths = false): void
    {
        $keys = [];

        foreach ([...$requested, ...$defaults] as $key) {
            if (isset($sortable[$key->attribute]) && ! isset($keys[$key->attribute])) {
                $keys[$key->attribute] = $key;
            }
        }

        foreach ($keys as $key) {
            $field = $sortable[$key->attribute];
            $callback = $field instanceof Field ? $field->sortCallback() : null;

            if ($callback !== null) {
                $callback($query, $key->direction);

                continue;
            }

            // As written, not qualified: the column may be an alias the model
            // selects (withCount, addSelect), which no table has. A column a
            // joined table shares (a hasManyThrough's intermediate, a pivot)
            // is not ambiguous here: the panels paginate through the
            // relation, which selects the related table's columns, and ORDER
            // BY resolves a bare name against the select list first. A JSON
            // path compiles to an expression, which ORDER BY does not
            // resolve that way, so over a joining relation it is qualified.
            $column = $qualifyJsonPaths && str_contains($key->attribute, '->')
                ? $query->qualifyColumn($key->attribute)
                : $key->attribute;
            $query->orderBy($column, $key->direction->value);
        }

        $model = $query->getModel();
        $primaryKey = $model->getKeyName();

        // A list sorted by its key already has a total order.
        if ($primaryKey !== '' && ! isset($keys[$primaryKey])) {
            $query->orderBy($model->getQualifiedKeyName(), SortDirection::Asc->value);
        }
    }
}
