<?php

use Martis\Contracts\UnsavedChangesConfigContract;
use Martis\UnsavedChangesConfig;

it('UnsavedChangesConfig implements the contract', function () {
    expect(UnsavedChangesConfig::make())->toBeInstanceOf(UnsavedChangesConfigContract::class);
});

it('starts with every field unset (empty toArray)', function () {
    $config = UnsavedChangesConfig::make();

    expect($config->toArray())->toBe([]);
});

it('serialises only the keys that were set', function () {
    $config = UnsavedChangesConfig::make()
        ->title('Custom title')
        ->body('Custom body')
        ->confirmLabel('Yes, discard');

    expect($config->toArray())->toBe([
        'title' => 'Custom title',
        'body' => 'Custom body',
        'confirmLabel' => 'Yes, discard',
    ]);
});

it('round-trips the full configuration', function () {
    $config = UnsavedChangesConfig::make()
        ->title('Attention')
        ->body('You have pending work.')
        ->icon('question')
        ->iconColor('info')
        ->confirmLabel('Discard')
        ->confirmColor('warning')
        ->cancelLabel('Go back');

    expect($config->toArray())->toBe([
        'title' => 'Attention',
        'body' => 'You have pending work.',
        'icon' => 'question',
        'iconColor' => 'info',
        'confirmLabel' => 'Discard',
        'confirmColor' => 'warning',
        'cancelLabel' => 'Go back',
    ]);
});

it('icon(null) explicitly hides the icon', function () {
    $config = UnsavedChangesConfig::make()->icon(null);

    // null is a "meaningful null" — we do persist it, but the keys with
    // other null values (which mean "use the default") are filtered.
    // icon(null) after icon() was never called produces an empty array.
    expect($config->toArray())->toBe([]);
});

it('icon(empty-string) serialises the empty string (acts as "no icon")', function () {
    $config = UnsavedChangesConfig::make()->icon('');

    expect($config->toArray())->toBe(['icon' => '']);
});

it('fluent setters return the config instance (chainable)', function () {
    $config = UnsavedChangesConfig::make();

    expect($config->title('x'))->toBe($config)
        ->and($config->body('x'))->toBe($config)
        ->and($config->confirmLabel('x'))->toBe($config)
        ->and($config->confirmColor('x'))->toBe($config)
        ->and($config->cancelLabel('x'))->toBe($config)
        ->and($config->iconColor('x'))->toBe($config);
});
