<?php

namespace Martis\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Enums\FilterType;

/**
 * Contract for resource filters.
 *
 * Filters modify the index query based on user-selected values.
 * Each filter type (select, boolean, date, date-range) provides
 * its own input rendering and query application logic.
 */
interface FilterContract
{
    /**
     * Create a new filter instance.
     */
    public static function make(string $name, ?string $uriKey = null): static;

    /**
     * Apply the filter to the given query.
     *
     * @param  Builder<Model>  $query
     */
    public function apply(Request $request, Builder $query, mixed $value): Builder;

    /**
     * Human-readable filter name.
     */
    public function name(): string;

    /**
     * Stable URI key used in query parameters and schema payloads.
     */
    public function uriKey(): string;

    /**
     * The filter type identifier.
     */
    public function filterType(): FilterType;

    /**
     * Optional frontend component key for custom rendering.
     */
    public function component(): ?string;

    /**
     * Get the filter options (for select and boolean filters).
     *
     * Keys are display labels, values are the query values.
     * Supports grouped format: ['Group' => ['Label' => 'value']].
     *
     * @return array<string, mixed>
     */
    public function options(Request $request): array;

    /**
     * Get the default filter value.
     */
    public function default(): mixed;

    /**
     * Set a callback that determines if the filter should be visible.
     *
     * Martis extension: per-filter authorization.
     */
    public function canSee(Closure $callback): static;

    /**
     * Determine if the filter should be visible for the given request.
     */
    public function authorizedToSee(Request $request): bool;

    /**
     * Extra metadata forwarded to the frontend.
     *
     * @return array<string, mixed>
     */
    public function meta(): array;

    /**
     * Serialize the filter for the schema endpoint.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
