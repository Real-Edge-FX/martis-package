<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Cards\Card;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;

/**
 * Minimal concrete lens used by unit tests. Nova-style Martis lenses are
 * subclasses, not descriptor instances, so the unit tests mirror that.
 */
class _RecentlyUpdatedLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query;
    }
}

it('Card defaults to visible when no canSee callback is set', function () {
    $card = Card::make('Revenue');
    expect($card->authorizedToSee(Request::create('/')))->toBeTrue();
});

it('Card hides itself when canSee returns false', function () {
    $card = Card::make('Revenue')->canSee(fn () => false);
    expect($card->authorizedToSee(Request::create('/')))->toBeFalse();
});

it('Card receives the current request in the canSee closure', function () {
    $card = Card::make('Revenue')->canSee(fn (Request $request) => $request->has('show'));

    expect($card->authorizedToSee(Request::create('/')))->toBeFalse();
    expect($card->authorizedToSee(Request::create('/', 'GET', ['show' => 1])))->toBeTrue();
});

it('Lens defaults to visible when no canSee callback is set', function () {
    $lens = new _RecentlyUpdatedLens();
    expect($lens->authorizedToSee(Request::create('/')))->toBeTrue();
});

it('Lens hides itself when canSee returns false', function () {
    $lens = (new _RecentlyUpdatedLens())->canSee(fn () => false);
    expect($lens->authorizedToSee(Request::create('/')))->toBeFalse();
});
