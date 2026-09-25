<?php

namespace Martis\Http\Requests;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Contracts\FilterContract;
use Martis\Enums\SortDirection;
use Martis\Support\IndexScope;

/**
 * Request wrapper for lens endpoints.
 *
 * Exposes `withFilters` and `withOrdering` helpers used inside
 * `Lens::query()` to compose user-selected filters and orderings
 * onto the lens-specific query.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
class LensRequest extends Request
{
    /** @var array<string, FilterContract> uriKey → filter instance */
    public array $availableFilters = [];

    /** @var array<string, mixed> Values currently selected (uriKey → value). */
    public array $selectedFilters = [];

    /** Search term applied to the lens, if any. */
    public string $search = '';

    /**
     * Column name to order by (null = no explicit sort). Built by
     * `fromRequest()`, it names a sortable field of the lens the user can
     * see, or nothing.
     */
    public ?string $sortColumn = null;

    /** Sort direction. */
    public SortDirection $sortDirection = SortDirection::Asc;

    /**
     * Apply filters the user selected onto the builder.
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withFilters(Builder $query): Builder
    {
        foreach ($this->selectedFilters as $uriKey => $value) {
            $filter = $this->availableFilters[$uriKey] ?? null;
            if ($filter === null) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (method_exists($filter, 'apply')) {
                // Filters operate on Builder<Model>; the generic narrows back
                // when the caller's $query is the concrete TModel.
                /** @var Builder<Model> $generic */
                $generic = $query;
                // Grouped: an `orWhere()` in the lens's query or in the
                // filter cannot OR the other away (see IndexScope).
                IndexScope::grouped($generic, fn (Builder $grouped) => $filter->apply($this, $grouped, $value));
            }
        }

        return $query;
    }

    /**
     * Apply user-selected ordering; if none, invoke the default closure.
     *
     * The ordering is the `sortColumn` the request may sort the lens by
     * (see `fromRequest()`); a `?sort=` naming any other column leaves it
     * empty, so the default closure orders the lens.
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withOrdering(Builder $query, ?Closure $default = null): Builder
    {
        if ($this->sortColumn !== null && $this->sortColumn !== '') {
            return $query->orderBy($this->sortColumn, $this->sortDirection->value);
        }

        if ($default !== null) {
            $result = $default($query);

            return $result instanceof Builder ? $result : $query;
        }

        return $query;
    }

    /**
     * Build a LensRequest from the incoming HTTP request plus the
     * context the controller has already resolved.
     *
     * `$sortableColumns` are the columns `?sort=` may name: the attributes
     * of the lens's sortable fields the user can see
     * (`Field::sortableAttributes()`). A `?sort=` naming any other column,
     * one no field exposes, a field the user cannot see or a column that
     * does not exist, leaves `sortColumn` empty, as if the request named
     * none: it never reaches the query, so it cannot order the lens by
     * values the user may not read, or fail the query.
     *
     * @param  array<string, FilterContract>  $availableFilters
     * @param  list<string>  $sortableColumns
     */
    public static function fromRequest(Request $source, array $availableFilters, array $sortableColumns = []): self
    {
        /** @var self $req */
        $req = self::createFrom($source, new self);

        $req->availableFilters = $availableFilters;

        $rawFilters = $source->query('filters');
        if (is_string($rawFilters) && $rawFilters !== '') {
            $decoded = json_decode($rawFilters, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $req->selectedFilters = $decoded;
            }
        } elseif (is_array($rawFilters)) {
            /** @var array<string, mixed> $rawFilters */
            $req->selectedFilters = $rawFilters;
        }

        $rawSearch = $source->query('search', '');
        $req->search = trim(is_string($rawSearch) ? $rawSearch : '');

        $rawSort = $source->query('sort');
        $req->sortColumn = is_string($rawSort) && in_array($rawSort, $sortableColumns, true) ? $rawSort : null;
        $req->sortDirection = SortDirection::fromQuery($source->query('direction'));

        return $req;
    }
}
