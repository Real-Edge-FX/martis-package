<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Martis\Facades\Martis;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Menu\MenuItem;
use Martis\Tools\Tool;

// Tools gain a first-class numeric nav count badge (v1.29.0), mirroring the
// Resource menuCount()/showMenuCount() contract.

class CountingTool extends Tool
{
    public function __construct()
    {
        parent::__construct('Standards', 'standards');
    }

    public function menuCount(Request $request): ?int
    {
        return 749;
    }
}

class NoCountTool extends Tool
{
    public function __construct()
    {
        parent::__construct('Projects', 'projects');
    }
    // Inherits the default menuCount() => null.
}

class HiddenCountTool extends Tool
{
    public function __construct()
    {
        parent::__construct('Secret', 'secret');
    }

    public function menuCount(Request $request): ?int
    {
        return 5;
    }

    public function showMenuCount(): bool
    {
        return false;
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
});

afterEach(function () {
    Martis::tools([]);
});

it('includes a tool menuCount in /api/navigation/badges keyed by uriKey', function () {
    Martis::tools([new CountingTool, new NoCountTool, new HiddenCountTool]);

    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertOk();
    // Keyed by "tool:{uriKey}" so a same-named resource can't conflate.
    expect($response->json())->toHaveKey('tool:standards', 749);
    expect($response->json())->not->toHaveKey('tool:projects'); // null count → hidden
    expect($response->json())->not->toHaveKey('tool:secret');   // showMenuCount() false
});

it('serialises the count on the resolved tool nav item', function () {
    $item = MenuItem::tool(new CountingTool)->resolve(request());

    expect($item)->not->toBeNull();
    expect($item['count'])->toBe(749);
});

it('serialises a null count for a tool without a menuCount', function () {
    $item = MenuItem::tool(new NoCountTool)->resolve(request());

    expect($item['count'])->toBeNull();
});
