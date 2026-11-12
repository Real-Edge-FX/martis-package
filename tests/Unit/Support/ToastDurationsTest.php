<?php

declare(strict_types=1);

use Martis\Support\ToastDurations;
use Martis\Tests\TestCase;

uses(TestCase::class);

it('defaults every severity to 5000 ms', function () {
    expect(ToastDurations::resolve(null))->toBe([
        'success' => 5000,
        'info' => 5000,
        'warning' => 5000,
        'error' => 5000,
    ]);
});

it('keeps a positive integer and null (sticky) per severity', function () {
    $out = ToastDurations::resolve(['error' => null, 'success' => 2000]);

    expect($out['error'])->toBeNull()
        ->and($out['success'])->toBe(2000)
        ->and($out['info'])->toBe(5000)
        ->and($out['warning'])->toBe(5000);
});

it('throws naming the key for an unusable duration', function (mixed $bad) {
    ToastDurations::resolve(['warning' => $bad]);
})->with([[0], [-5], [1.5], ['5000.5'], ['-5'], ['0'], [''], [' 5000'], ['fast'], [true], [[]], [2147483648], ['2147483648'], ['99999999999999999999']])
    ->throws(InvalidArgumentException::class, 'martis.toast.durations.warning');

it('accepts digit strings, which is what env() yields, as integers', function () {
    $out = ToastDurations::resolve(['success' => '8000', 'error' => '2147483647']);

    expect($out['success'])->toBe(8000)
        ->and($out['error'])->toBe(2147483647);
});

it('accepts the largest delay a browser timer holds', function () {
    expect(ToastDurations::resolve(['info' => 2147483647])['info'])->toBe(2147483647);
});

it('names the maximum when a duration is too long for a timer', function () {
    ToastDurations::resolve(['error' => 3000000000]);
})->throws(InvalidArgumentException::class, '2147483647');

it('throws naming the key for an unknown severity', function () {
    ToastDurations::resolve(['danger' => 1000]);
})->throws(InvalidArgumentException::class, 'martis.toast.durations.danger');

it('throws naming the key when durations is not an array', function () {
    ToastDurations::resolve('fast');
})->throws(InvalidArgumentException::class, 'martis.toast.durations');

it('publishes durations in the default config', function () {
    expect(config('martis.toast.durations'))->toBe([
        'success' => 5000, 'info' => 5000, 'warning' => 5000, 'error' => 5000,
    ]);
});

it('exposes the toast config block with normalised durations', function () {
    config(['martis.toast.durations.error' => null]);

    $block = ToastDurations::config(config('martis.toast'));

    expect($block['position'])->toBe('bottom-right')
        ->and($block['durations']['error'])->toBeNull();
});
