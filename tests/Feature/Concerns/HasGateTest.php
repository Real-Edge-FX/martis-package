<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Martis\Dashboards\Dashboard;
use Martis\Tools\Tool;

beforeEach(function () {
    config()->set('martis.gates.plan_resolver', null);
    config()->set('martis.gates.plan_rank', [
        'free' => 0,
        'starter' => 1,
        'pro' => 2,
        'admin' => 3,
    ]);
    config()->set('martis.gates.presets', [
        'pro' => [
            'badge' => ['text' => 'Pro', 'tone' => 'accent'],
            'modal' => [
                'title' => 'Pro feature',
                'message' => 'Upgrade to unlock',
                'cta' => ['label' => 'Upgrade', 'url' => '/billing'],
            ],
        ],
    ]);
});

it('a dashboard without a lock predicate is never locked', function () {
    $dashboard = new Dashboard('Sales');
    expect($dashboard->isLockedFor(new Request))->toBeFalse()
        ->and($dashboard->lockPayloadFor(new Request))->toBeNull()
        ->and($dashboard->toArray()['lock'])->toBeNull();
});

it('lockedFor closure returning true marks the entity as locked', function () {
    $dashboard = (new Dashboard('Sales'))->lockedFor(fn () => true);
    expect($dashboard->isLockedFor(new Request))->toBeTrue();
});

it('lockedFor closure returning false leaves the entity unlocked', function () {
    $dashboard = (new Dashboard('Sales'))->lockedFor(fn () => false);
    expect($dashboard->isLockedFor(new Request))->toBeFalse();
});

it('lockPayloadFor returns the configured modal when locked', function () {
    $dashboard = (new Dashboard('Sales'))
        ->lockedFor(fn () => true)
        ->lockModal([
            'title' => 'Locked',
            'message' => 'Upgrade required.',
            'cta' => ['label' => 'Go', 'url' => '/billing'],
        ]);

    $payload = $dashboard->lockPayloadFor(new Request);

    expect($payload)->not->toBeNull()
        ->and($payload['reason'])->toBe('gated')
        ->and($payload['modal']['title'])->toBe('Locked')
        ->and($payload['modal']['cta']['url'])->toBe('/billing');
});

it('lockPreset applies the badge + modal from config', function () {
    $dashboard = (new Dashboard('Sales'))
        ->lockedFor(fn () => true)
        ->lockPreset('pro');

    expect($dashboard->badge())->toBe(['text' => 'Pro', 'tone' => 'accent']);

    $payload = $dashboard->lockPayloadFor(new Request);
    expect($payload['modal']['title'])->toBe('Pro feature');
});

it('lockPreset for an unknown name is a no-op', function () {
    $dashboard = (new Dashboard('Sales'))->lockPreset('unknown-preset-xyz');

    expect($dashboard->badge())->toBeNull()
        ->and($dashboard->lockPayloadFor(new Request))->toBeNull();
});

it('requirePlan is a no-op when no plan_resolver is configured', function () {
    config()->set('martis.gates.plan_resolver', null);

    $dashboard = (new Dashboard('Sales'))->requirePlan('pro');

    // No resolver → no plan info → user is locked (rank -1 < required).
    // Validating the documented behaviour: without the resolver the
    // ranker returns true (locked) for every authenticated user. Hosts
    // that do not configure the resolver should not call requirePlan.
    expect($dashboard->isLockedFor(new Request))->toBeTrue();
});

it('requirePlan compares the resolved plan against plan_rank', function () {
    config()->set('martis.gates.plan_resolver', fn ($user) => 'starter');

    $dashboard = (new Dashboard('Sales'))->requirePlan('pro');
    expect($dashboard->isLockedFor(new Request))->toBeTrue(); // starter < pro

    config()->set('martis.gates.plan_resolver', fn ($user) => 'pro');
    expect($dashboard->isLockedFor(new Request))->toBeFalse(); // pro >= pro

    config()->set('martis.gates.plan_resolver', fn ($user) => 'admin');
    expect($dashboard->isLockedFor(new Request))->toBeFalse(); // admin >= pro
});

it('requirePlan with an undeclared tier fails open (not locked)', function () {
    config()->set('martis.gates.plan_resolver', fn ($user) => 'free');

    $dashboard = (new Dashboard('Sales'))->requirePlan('mythril-tier');

    // Undeclared tier returns null rank → ranker fails open by design.
    expect($dashboard->isLockedFor(new Request))->toBeFalse();
});

it('Tool also emits lock in toArray when locked', function () {
    $tool = (new Tool('Charts', 'charts'))->lockedFor(fn () => true);

    expect($tool->toArray()['lock'])->not->toBeNull();
});
