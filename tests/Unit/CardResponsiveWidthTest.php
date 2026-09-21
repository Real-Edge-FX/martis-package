<?php

declare(strict_types=1);

use Martis\Cards\Card;

/*
 * A plain Card shares the dashboard grid with metrics, so it carries the
 * same responsive width cascade (`width()` from md, `widthMd()` / `widthLg()`
 * overriding it per breakpoint) and serialises the two tiers next to `width`
 * for the SPA resolver (lib/cardGridSpan.ts).
 */

it('serialises widthMd and widthLg as null until declared', function () {
    $arr = Card::make('Revenue')->width(6)->toArray();

    expect($arr['width'])->toBe(6)
        ->and($arr['widthMd'])->toBeNull()
        ->and($arr['widthLg'])->toBeNull();
});

it('serialises the responsive widths declared on a plain Card', function () {
    $arr = Card::make('Revenue')->width(12)->widthMd(12)->widthLg(8)->toArray();

    expect($arr['width'])->toBe(12)
        ->and($arr['widthMd'])->toBe(12)
        ->and($arr['widthLg'])->toBe(8);
});

it('clamps every tier into the 12-column grid like Metric does', function () {
    $arr = Card::make('Revenue')->width(0)->widthMd(13)->widthLg(-3)->toArray();

    expect($arr['width'])->toBe(1)
        ->and($arr['widthMd'])->toBe(12)
        ->and($arr['widthLg'])->toBe(1);
});
