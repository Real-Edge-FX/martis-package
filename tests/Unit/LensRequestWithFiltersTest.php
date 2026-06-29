<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Filters\SelectFilter;
use Martis\Http\Requests\LensRequest;

/**
 * Stub filter that records every value received by apply().
 *
 * Extends SelectFilter so it satisfies the FilterContract; options() is
 * left empty because these tests never render the filter schema.
 */
class RecordingSelectFilter extends SelectFilter
{
    /** @var list<mixed> */
    public array $appliedValues = [];

    public function options(Request $request): array
    {
        return [];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        $this->appliedValues[] = $value;

        return $query;
    }
}

/**
 * Build a LensRequest with the given selectedFilters and one RecordingSelectFilter
 * registered under the key 'recording'.
 *
 * @param  array<string, mixed>  $selectedFilters
 * @return array{LensRequest, RecordingSelectFilter}
 */
function makeLensRequestWithFilter(array $selectedFilters): array
{
    $req = new LensRequest;
    $filter = RecordingSelectFilter::make('Recording', 'recording');

    $req->availableFilters = ['recording' => $filter];
    $req->selectedFilters = $selectedFilters;

    return [$req, $filter];
}

// ---------------------------------------------------------------------------
// Null / empty-string guard
// Mirrors the guard in ResourceController::applyFilters() (line 1570).
// ---------------------------------------------------------------------------

it('withFilters skips filter when selected value is null', function () {
    [$req, $filter] = makeLensRequestWithFilter(['recording' => null]);

    $req->withFilters(Mockery::mock(Builder::class)->makePartial());

    expect($filter->appliedValues)->toBeEmpty();
});

it('withFilters skips filter when selected value is empty string', function () {
    [$req, $filter] = makeLensRequestWithFilter(['recording' => '']);

    $req->withFilters(Mockery::mock(Builder::class)->makePartial());

    expect($filter->appliedValues)->toBeEmpty();
});

it('withFilters calls filter when selected value is a non-empty string', function () {
    [$req, $filter] = makeLensRequestWithFilter(['recording' => 'active']);

    $req->withFilters(Mockery::mock(Builder::class)->makePartial());

    expect($filter->appliedValues)->toBe(['active']);
});

it('withFilters calls filter when selected value is integer 0', function () {
    [$req, $filter] = makeLensRequestWithFilter(['recording' => 0]);

    $req->withFilters(Mockery::mock(Builder::class)->makePartial());

    expect($filter->appliedValues)->toBe([0]);
});

it('withFilters calls filter when selected value is boolean false', function () {
    [$req, $filter] = makeLensRequestWithFilter(['recording' => false]);

    $req->withFilters(Mockery::mock(Builder::class)->makePartial());

    expect($filter->appliedValues)->toBe([false]);
});

it('withFilters skips unknown uriKey without calling any filter', function () {
    [$req, $filter] = makeLensRequestWithFilter(['unknown-key' => 'value']);

    $req->withFilters(Mockery::mock(Builder::class)->makePartial());

    expect($filter->appliedValues)->toBeEmpty();
});
