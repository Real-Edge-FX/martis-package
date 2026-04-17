<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;

/**
 * Unit tests for the 4 Martis lens differentials.
 *
 * D1 — summary row is exercised end-to-end in LensControllerTest.
 * D2 — cacheFor() TTL normalization is tested here.
 * D3 — withDefaultFilters() is tested here.
 * D4 — URL state sync is a frontend-only concern (see tests/e2e/lens.spec.ts).
 */
class FakeLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query;
    }
}

// D2 — cacheFor() with integer seconds

it('stores the cache TTL in seconds when given an integer', function () {
    $lens = (new FakeLens())->cacheFor(120);
    expect($lens->cacheTtl())->toBe(120);
});

it('stores 0 when cacheFor receives a negative integer', function () {
    $lens = (new FakeLens())->cacheFor(-5);
    expect($lens->cacheTtl())->toBe(0);
});

it('stores the cache TTL in seconds when given a DateInterval', function () {
    $lens = (new FakeLens())->cacheFor(new DateInterval('PT5M'));
    // 5 minutes = 300 seconds. Allow +-1 to absorb clock drift inside the helper.
    expect($lens->cacheTtl())->toBeGreaterThanOrEqual(299);
    expect($lens->cacheTtl())->toBeLessThanOrEqual(301);
});

it('defaults cacheTtl to 0 when cacheFor has not been called', function () {
    $lens = new FakeLens();
    expect($lens->cacheTtl())->toBe(0);
});

// D3 — withDefaultFilters()

it('defaults default filters to an empty array', function () {
    $lens = new FakeLens();
    expect($lens->defaultFilters())->toBe([]);
});

it('stores default filter values', function () {
    $lens = (new FakeLens())->withDefaultFilters(['status' => 'active', 'owner' => 42]);
    expect($lens->defaultFilters())->toBe(['status' => 'active', 'owner' => 42]);
});

it('exposes defaultFilters in the toArray() descriptor', function () {
    $lens = (new FakeLens())->withDefaultFilters(['status' => 'active']);
    $payload = $lens->toArray();
    expect($payload['defaultFilters'])->toBe(['status' => 'active']);
});

it('exposes cacheTtlSeconds in the toArray() descriptor', function () {
    $lens = (new FakeLens())->cacheFor(60);
    $payload = $lens->toArray();
    expect($payload['cacheTtlSeconds'])->toBe(60);
});

// canSeeWhen — shorthand

it('canSeeWhen denies when the user cannot perform the ability', function () {
    $lens = (new FakeLens())->canSeeWhen('does-not-exist', null);
    $request = Request::create('/');
    expect($lens->authorizedToSee($request))->toBeFalse();
});
