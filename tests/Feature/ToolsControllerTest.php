<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Martis\Facades\Martis;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Menu\MenuItem;
use Martis\Tools\Tool;

class SystemStatusTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'System Status', uriKey: 'system-status');
        $this->withIcon('pulse')
            ->withComponent('tool:system-status')
            ->withMenuSection('Operations');
    }
}

class HiddenTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Hidden Tool', uriKey: 'hidden-tool');
        $this->canSee(fn (Request $r) => false);
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Martis::tools([]); // reset
});

afterEach(function () {
    Martis::tools([]);
});

it('GET /martis/api/tools is empty when no tools are registered', function () {
    $response = $this->getJson('/martis/api/tools');

    $response->assertStatus(200)->assertExactJson([]);
});

it('GET /martis/api/tools lists every visible registered tool', function () {
    Martis::tools([
        new SystemStatusTool(),
        new HiddenTool(),
    ]);

    $response = $this->getJson('/martis/api/tools');

    $response->assertStatus(200);
    $payload = $response->json();

    expect($payload)->toHaveCount(1);
    expect($payload[0])->toMatchArray([
        'type' => 'tool',
        'name' => 'System Status',
        'uriKey' => 'system-status',
        'icon' => 'pulse',
        'component' => 'tool:system-status',
        'menuSection' => 'Operations',
    ]);
});

it('GET /martis/api/tools/{uriKey} returns the tool metadata', function () {
    Martis::tools([new SystemStatusTool()]);

    $response = $this->getJson('/martis/api/tools/system-status');

    $response->assertStatus(200)->assertJson([
        'uriKey' => 'system-status',
        'component' => 'tool:system-status',
    ]);
});

it('GET /martis/api/tools/{uriKey} returns 404 for unknown tools', function () {
    Martis::tools([new SystemStatusTool()]);

    $response = $this->getJson('/martis/api/tools/nope');

    $response->assertStatus(404);
});

it('GET /martis/api/tools/{uriKey} returns 404 for tools whose canSee() denies the user', function () {
    Martis::tools([new HiddenTool()]);

    // Indistinguishable from "not found" by design — an unauthorised
    // user must not be able to probe which tools exist.
    $response = $this->getJson('/martis/api/tools/hidden-tool');

    $response->assertStatus(404);
});

it('class-string registrations are instantiated lazily', function () {
    Martis::tools([SystemStatusTool::class]);

    $tools = Martis::resolveTools(request());

    expect($tools)->toHaveCount(1);
    expect($tools[0])->toBeInstanceOf(SystemStatusTool::class);
});

it('MenuItem::tool() resolves to a tool entry with the right URL and component', function () {
    $tool = new SystemStatusTool();

    $item = MenuItem::tool($tool);
    $resolved = $item->resolve(request());

    expect($resolved)->toMatchArray([
        'type' => 'tool',
        'label' => 'System Status',
        'url' => '/tools/system-status',
        'icon' => 'pulse',
        'uriKey' => 'system-status',
        'component' => 'tool:system-status',
    ]);
});

it('MenuItem::tool() drops the entry when canSee() denies the request', function () {
    $item = MenuItem::tool(new HiddenTool());

    expect($item->resolve(request()))->toBeNull();
});

it('MenuItem::tool() accepts a class-string and instantiates it lazily', function () {
    $item = MenuItem::tool(SystemStatusTool::class);
    $resolved = $item->resolve(request());

    expect($resolved)->not->toBeNull();
    expect($resolved['uriKey'])->toBe('system-status');
});
