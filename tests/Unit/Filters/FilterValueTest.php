<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Filters\BooleanFilter;
use Martis\Filters\FilterValue;
use Martis\Filters\MultiSelectFilter;
use Martis\Filters\SelectFilter;

// FilterValue fixes what a boolean filter's apply() receives from the request
// (F069, F073): the declared option values as keys, booleans as values.

class FilterValueFlagsFilter extends BooleanFilter
{
    public function options(Request $request): array
    {
        return ['Admin' => 'is_admin', 'Active' => 'is_active'];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query;
    }
}

class FilterValueGroupedFilter extends BooleanFilter
{
    public function options(Request $request): array
    {
        return ['Roles' => ['Admin' => 'is_admin'], 'State' => ['Active' => 'is_active', 'Locked' => 'is_locked']];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query;
    }
}

class FilterValueNumericFilter extends BooleanFilter
{
    public function options(Request $request): array
    {
        return ['One' => 1, 'Two' => 2];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query;
    }
}

class FilterValueSelectFilter extends SelectFilter
{
    public function options(Request $request): array
    {
        return ['Active' => 'active'];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query;
    }
}

class FilterValueMultiFilter extends MultiSelectFilter
{
    public function options(Request $request): array
    {
        return ['A' => 'a'];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query;
    }
}

function filterValueOf(BooleanFilter|SelectFilter|MultiSelectFilter $filter, mixed $value): mixed
{
    return FilterValue::resolve($filter, Request::create('/'), $value);
}

it('keeps the keys the options declare and drops the others', function () {
    $filter = FilterValueFlagsFilter::make('Flags');

    expect(filterValueOf($filter, ['is_admin' => true, 'is_secret' => true, 'settings->flag' => true, 'is_active' => false]))
        ->toBe(['is_admin' => true, 'is_active' => false]);
});

it('casts each value to a boolean', function () {
    $filter = FilterValueFlagsFilter::make('Flags');

    expect(filterValueOf($filter, ['is_admin' => 'true', 'is_active' => 'false']))->toBe(['is_admin' => true, 'is_active' => false])
        ->and(filterValueOf($filter, ['is_admin' => 1, 'is_active' => 0]))->toBe(['is_admin' => true, 'is_active' => false])
        ->and(filterValueOf($filter, ['is_admin' => '1', 'is_active' => 'on']))->toBe(['is_admin' => true, 'is_active' => true])
        ->and(filterValueOf($filter, ['is_admin' => ['x'], 'is_active' => null]))->toBe(['is_admin' => false, 'is_active' => false]);
});

it('skips a boolean filter that no declared key is left for', function () {
    $filter = FilterValueFlagsFilter::make('Flags');

    expect(filterValueOf($filter, ['is_secret' => true]))->toBeNull()
        ->and(filterValueOf($filter, []))->toBeNull();
});

it('reads a value that is not a map as nothing', function (mixed $value) {
    expect(filterValueOf(FilterValueFlagsFilter::make('Flags'), $value))->toBeNull();
})->with(['a string' => 'is_admin', 'true' => true, 'a number' => 5]);

it('reads the options of a grouped filter', function () {
    $filter = FilterValueGroupedFilter::make('Flags');

    expect(filterValueOf($filter, ['is_admin' => true, 'is_locked' => true, 'is_secret' => true]))
        ->toBe(['is_admin' => true, 'is_locked' => true]);
});

it('matches option values that are numbers against the string keys of the request', function () {
    $filter = FilterValueNumericFilter::make('Numbers');

    expect(filterValueOf($filter, ['1' => true, '3' => true]))->toBe([1 => true]);
});

it('leaves the value of every other filter type as it is', function () {
    expect(filterValueOf(FilterValueSelectFilter::make('Status'), 'anything'))->toBe('anything')
        ->and(filterValueOf(FilterValueMultiFilter::make('Tags'), ['x', 'y']))->toBe(['x', 'y']);
});
