<?php

namespace Martis\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Enums\ComparisonOperator;

/**
 * A date picker filter for filtering records by a single date.
 *
 * The $value passed to apply() is a date string (Y-m-d format).
 *
 * @phpstan-consistent-constructor
 */
class DateFilter extends Filter
{
    /** The database column to filter on. */
    protected string $column;

    /** The comparison operator. */
    protected ComparisonOperator $operator = ComparisonOperator::Equals;

    public function __construct(
        string $name,
        ?string $uriKey = null,
        ?string $column = null,
    ) {
        parent::__construct($name, $uriKey);
        $this->column = $column ?? strtolower(str_replace(' ', '_', $name));
    }

    public static function make(string $name, ?string $uriKey = null): static
    {
        return new static($name, $uriKey);
    }

    public function filterType(): string
    {
        return 'date';
    }

    /**
     * Set the database column to filter on.
     */
    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    /**
     * Set the comparison operator.
     */
    public function operator(ComparisonOperator $operator): static
    {
        $this->operator = $operator;

        return $this;
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->whereDate($this->column, $this->operator->value, $value);
    }
}
