<?php

namespace Martis\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Enums\ComparisonOperator;
use Martis\Enums\FilterType;

/**
 * A date range filter for filtering records between two dates.
 *
 * The $value passed to apply() is an array with 'from' and/or 'to' keys
 * in Y-m-d format.
 *
 * This is a Martis extension — a built-in date range filter.
 *
 * @phpstan-consistent-constructor
 */
class DateRangeFilter extends Filter
{
    /** The database column to filter on. */
    protected string $column;

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

    public function filterType(): FilterType
    {
        return FilterType::DateRange;
    }

    /**
     * Set the database column to filter on.
     */
    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        if (! is_array($value)) {
            return $query;
        }

        if (! empty($value['from'])) {
            $query->whereDate($this->column, ComparisonOperator::GreaterThanOrEqual->value, $value['from']);
        }

        if (! empty($value['to'])) {
            $query->whereDate($this->column, ComparisonOperator::LessThanOrEqual->value, $value['to']);
        }

        return $query;
    }
}
