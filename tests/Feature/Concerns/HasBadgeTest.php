<?php

declare(strict_types=1);

use Martis\Cards\Card;
use Martis\Dashboards\Dashboard;
use Martis\Tools\Tool;

it('Dashboard exposes a null badge by default', function () {
    expect((new Dashboard('Sales'))->badge())->toBeNull()
        ->and((new Dashboard('Sales'))->toArray()['badge'])->toBeNull();
});

it('Dashboard::withBadge stores text + tone', function () {
    $dashboard = (new Dashboard('Sales'))->withBadge('Pro', 'accent');

    expect($dashboard->badge())->toBe(['text' => 'Pro', 'tone' => 'accent'])
        ->and($dashboard->toArray()['badge'])->toBe(['text' => 'Pro', 'tone' => 'accent']);
});

it('Dashboard::withBadge defaults to neutral tone', function () {
    $dashboard = (new Dashboard('Sales'))->withBadge('New');

    expect($dashboard->badge())->toBe(['text' => 'New', 'tone' => 'neutral']);
});

it('Tool exposes a null badge by default and round-trips withBadge', function () {
    $tool = new Tool('Charts', 'charts');
    expect($tool->toArray()['badge'])->toBeNull();

    $tool->withBadge('Beta', 'warning');
    expect($tool->toArray()['badge'])->toBe(['text' => 'Beta', 'tone' => 'warning']);
});

it('Card exposes a null badge by default and round-trips withBadge', function () {
    $card = (new Card('Top Spenders'))->withBadge('Pro', 'accent');

    expect($card->toArray()['badge'])->toBe(['text' => 'Pro', 'tone' => 'accent']);
});
