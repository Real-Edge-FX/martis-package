<?php

use Illuminate\Http\Request;
use Martis\Cards\Card;
use Martis\Lenses\Lens;

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
    $lens = Lens::make('Recently Updated');
    expect($lens->authorizedToSee(Request::create('/')))->toBeTrue();
});

it('Lens hides itself when canSee returns false', function () {
    $lens = Lens::make('Recently Updated')->canSee(fn () => false);
    expect($lens->authorizedToSee(Request::create('/')))->toBeFalse();
});
