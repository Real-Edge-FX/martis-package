<?php

namespace Martis\Http\Requests;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Request wrapper for lens endpoints.
 *
 * Nova v5 parity: exposes `withFilters` and `withOrdering` helpers used
 * inside `Lens::query()` to compose user-selected filters and orderings
 * onto the lens-specific query.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
class LensRequest extends Request
{
    /** @var array<string, \Martis\Contracts\FilterContract> uriKey → filter instance */
    public array $availableFilters = [];

    /** @var array<string, mixed> Values currently selected (uriKey → value). */
    public array $selectedFilters = [];

    /** Search term applied to the lens, if any. */
    public string $search = '';

    /** Column name to order by (null = no explicit sort). */
    public ?string $sortColumn = null;

    /** Sort direction ('asc'|'desc'). */
    public string $sortDirection = 'asc';

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

            if (method_exists($filter, 'apply')) {
                $filter->apply($this, $query, $value);
            }
        }

        return $query;
    }

    /**
     * Apply user-selected ordering; if none, invoke the default closure.
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withOrdering(Builder $query, ?Closure $default = null): Builder
    {
        if ($this->sortColumn !== null && $this->sortColumn !== '') {
            return $query->orderBy($this->sortColumn, $this->sortDirection);
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
     * @param  array<string, \Martis\Contracts\FilterContract>  $availableFilters
     */
    public static function fromRequest(Request $source, array $availableFilters): self
    {
        /** @var self $req */
        $req = self::createFrom($source, new self());

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
        $req->sortColumn = is_string($rawSort) && $rawSort !== '' ? $rawSort : null;

        $rawDir = strtolower((string) $source->query('direction', 'asc'));
        $req->sortDirection = in_array($rawDir, ['asc', 'desc'], true) ? $rawDir : 'asc';

        return $req;
    }
}
