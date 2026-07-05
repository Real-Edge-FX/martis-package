<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Enums\ComparisonOperator;
use Martis\Enums\FilterType;
use Martis\Filters\BooleanFilter;
use Martis\Filters\DateFilter;
use Martis\Filters\DateRangeFilter;
use Martis\Filters\SelectFilter;

// ---------------------------------------------------------------------------
// Concrete test filters
// ---------------------------------------------------------------------------

class TestStatusFilter extends SelectFilter
{
    public function options(Request $request): array
    {
        return [
            'Active' => 'active',
            'Inactive' => 'inactive',
            'Pending' => 'pending',
        ];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }
}

class TestRolesFilter extends BooleanFilter
{
    public function options(Request $request): array
    {
        return [
            'Admin' => 'is_admin',
            'Active' => 'is_active',
        ];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        if (is_array($value)) {
            foreach ($value as $column => $enabled) {
                if ($enabled) {
                    $query->where($column, true);
                }
            }
        }

        return $query;
    }
}

// ---------------------------------------------------------------------------
// SelectFilter
// ---------------------------------------------------------------------------

it('SelectFilter::make creates a filter with correct name and type', function () {
    $filter = TestStatusFilter::make('Status');

    expect($filter->name())->toBe('Status')
        ->and($filter->filterType())->toBe(FilterType::Select)
        ->and($filter->uriKey())->toBe('status');
});

it('SelectFilter generates uriKey from name', function () {
    $filter = TestStatusFilter::make('User Status');
    expect($filter->uriKey())->toBe('user-status');
});

it('SelectFilter accepts custom uriKey', function () {
    $filter = TestStatusFilter::make('Status', 'custom-key');
    expect($filter->uriKey())->toBe('custom-key');
});

it('SelectFilter resolves options for schema', function () {
    $filter = TestStatusFilter::make('Status');
    $filter->resolveForSchema(Request::create('/'));

    $arr = $filter->toArray();

    expect($arr['options'])->toHaveCount(3)
        ->and($arr['options'][0])->toBe(['label' => 'Active', 'value' => 'active'])
        ->and($arr['options'][1])->toBe(['label' => 'Inactive', 'value' => 'inactive'])
        ->and($arr['options'][2])->toBe(['label' => 'Pending', 'value' => 'pending']);
});

it('SelectFilter supports searchable option', function () {
    $filter = TestStatusFilter::make('Status')->searchable();

    expect($filter->meta()['searchable'])->toBeTrue();
});

it('SelectFilter toArray includes all required keys', function () {
    $filter = TestStatusFilter::make('Status');
    $filter->resolveForSchema(Request::create('/'));

    $arr = $filter->toArray();

    expect($arr)->toHaveKeys(['type', 'filterType', 'name', 'uriKey', 'component', 'options', 'default', 'meta'])
        ->and($arr['type'])->toBe('filter')
        ->and($arr['filterType'])->toBe('select')
        ->and($arr['default'])->toBeNull();
});

// ---------------------------------------------------------------------------
// BooleanFilter
// ---------------------------------------------------------------------------

it('BooleanFilter::make creates a filter with correct type', function () {
    $filter = TestRolesFilter::make('Roles');

    expect($filter->filterType())->toBe(FilterType::Boolean)
        ->and($filter->name())->toBe('Roles');
});

it('BooleanFilter resolves options for schema', function () {
    $filter = TestRolesFilter::make('Roles');
    $filter->resolveForSchema(Request::create('/'));

    $arr = $filter->toArray();

    expect($arr['options'])->toHaveCount(2)
        ->and($arr['options'][0])->toBe(['label' => 'Admin', 'value' => 'is_admin']);
});

// ---------------------------------------------------------------------------
// DateFilter
// ---------------------------------------------------------------------------

it('DateFilter has correct type', function () {
    $filter = DateFilter::make('Created At');

    expect($filter->filterType())->toBe(FilterType::Date)
        ->and($filter->uriKey())->toBe('created-at');
});

it('DateFilter accepts custom column', function () {
    $filter = DateFilter::make('Created At')->column('created_at');
    $filter->resolveForSchema(Request::create('/'));

    $arr = $filter->toArray();
    expect($arr['filterType'])->toBe('date')
        ->and($arr['options'])->toBe([]);
});

it('DateFilter accepts custom operator', function () {
    $filter = DateFilter::make('After Date')->operator(ComparisonOperator::GreaterThanOrEqual);
    expect($filter->filterType())->toBe(FilterType::Date);
});

// ---------------------------------------------------------------------------
// DateRangeFilter
// ---------------------------------------------------------------------------

it('DateRangeFilter has correct type', function () {
    $filter = DateRangeFilter::make('Date Range');

    expect($filter->filterType())->toBe(FilterType::DateRange)
        ->and($filter->uriKey())->toBe('date-range');
});

it('DateRangeFilter accepts custom column', function () {
    $filter = DateRangeFilter::make('Created Between')->column('created_at');

    $filter->resolveForSchema(Request::create('/'));
    $arr = $filter->toArray();

    expect($arr['filterType'])->toBe('date-range');
});

// ---------------------------------------------------------------------------
// withMeta
// ---------------------------------------------------------------------------

it('Filter supports withMeta', function () {
    $filter = TestStatusFilter::make('Status')
        ->withMeta(['placeholder' => 'Choose...']);

    expect($filter->meta())->toHaveKey('placeholder', 'Choose...');
});

// ---------------------------------------------------------------------------
// componentKey
// ---------------------------------------------------------------------------

it('Filter supports custom componentKey', function () {
    $filter = TestStatusFilter::make('Status')->componentKey('custom-select');

    expect($filter->component())->toBe('custom-select');
});

// ---------------------------------------------------------------------------
// default
// ---------------------------------------------------------------------------

it('Filter default returns null by default', function () {
    $filter = TestStatusFilter::make('Status');
    expect($filter->default())->toBeNull();
});

// ---------------------------------------------------------------------------
// canSee — Martis extension
// ---------------------------------------------------------------------------

it('Filter is visible by default (no canSee callback)', function () {
    $filter = TestStatusFilter::make('Status');
    expect($filter->authorizedToSee(Request::create('/')))->toBeTrue();
});

it('canSee hides filter when callback returns false', function () {
    $filter = TestStatusFilter::make('Status')
        ->canSee(fn () => false);

    expect($filter->authorizedToSee(Request::create('/')))->toBeFalse();
});

it('canSee shows filter when callback returns true', function () {
    $filter = TestStatusFilter::make('Status')
        ->canSee(fn () => true);

    expect($filter->authorizedToSee(Request::create('/')))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Grouped options
// ---------------------------------------------------------------------------

class TestGroupedFilter extends SelectFilter
{
    public function options(Request $request): array
    {
        return [
            'Numbers' => [
                'Odd' => 'odd',
                'Even' => 'even',
            ],
            'Letters' => [
                'Vowels' => 'vowels',
                'Consonants' => 'consonants',
            ],
        ];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query;
    }
}

it('Filter resolves grouped options correctly', function () {
    $filter = TestGroupedFilter::make('Category');
    $filter->resolveForSchema(Request::create('/'));

    $arr = $filter->toArray();
    $options = $arr['options'];

    expect($options)->toHaveCount(4)
        ->and($options[0])->toBe(['label' => 'Odd', 'value' => 'odd', 'group' => 'Numbers'])
        ->and($options[1])->toBe(['label' => 'Even', 'value' => 'even', 'group' => 'Numbers'])
        ->and($options[2])->toBe(['label' => 'Vowels', 'value' => 'vowels', 'group' => 'Letters'])
        ->and($options[3])->toBe(['label' => 'Consonants', 'value' => 'consonants', 'group' => 'Letters']);
});

// ---------------------------------------------------------------------------
// placeholder — Martis extension
// ---------------------------------------------------------------------------

it('Filter toArray serializes placeholder as null when not set', function () {
    $filter = TestStatusFilter::make('Status');
    $filter->resolveForSchema(Request::create('/'));

    $arr = $filter->toArray();

    expect($arr)->toHaveKey('placeholder', null);
});

it('Filter toArray serializes the configured placeholder', function () {
    $filter = TestStatusFilter::make('Status')->placeholder('Select…');
    $filter->resolveForSchema(Request::create('/'));

    $arr = $filter->toArray();

    expect($arr)->toHaveKey('placeholder', 'Select…');
});

it('placeholder() returns the filter instance for chaining', function () {
    $filter = TestStatusFilter::make('Status');

    expect($filter->placeholder('Select…'))->toBe($filter);
});
