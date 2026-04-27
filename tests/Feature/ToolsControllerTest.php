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

// ---------------------------------------------------------------------------
// Per-tool boot() lifecycle hook (Nova parity)
// ---------------------------------------------------------------------------

class BootHookTool extends \Martis\Tools\Tool
{
    /** @var int */
    public static int $bootCount = 0;

    public function __construct()
    {
        parent::__construct(name: 'Boot Hook', uriKey: 'boot-hook');
    }

    public function boot(): void
    {
        static::$bootCount++;
    }
}

class ThrowingBootTool extends \Martis\Tools\Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Throwing Boot', uriKey: 'throwing-boot');
    }

    public function boot(): void
    {
        throw new \RuntimeException('boom');
    }
}

it('MartisManager::bootTools() runs boot() on every registered tool exactly once', function () {
    BootHookTool::$bootCount = 0;
    \Martis\Facades\Martis::tools([BootHookTool::class]);

    \Martis\Facades\Martis::getFacadeRoot()?->bootTools();
    \Martis\Facades\Martis::getFacadeRoot()?->bootTools(); // idempotent

    expect(BootHookTool::$bootCount)->toBe(1);
});

it('MartisManager::bootTools() materialises class-string registrations into instances', function () {
    \Martis\Facades\Martis::tools([BootHookTool::class]);
    \Martis\Facades\Martis::getFacadeRoot()?->bootTools();

    $resolved = \Martis\Facades\Martis::resolveTools(request());

    expect($resolved[0])->toBeInstanceOf(BootHookTool::class);
});

it('MartisManager::bootTools() swallows exceptions so a broken tool does not kill admin boot', function () {
    \Martis\Facades\Martis::tools([
        new ThrowingBootTool(),
        new BootHookTool(),
    ]);
    BootHookTool::$bootCount = 0;

    // Must not throw — the broken tool gets logged + skipped, the
    // healthy tool's boot still runs.
    \Martis\Facades\Martis::getFacadeRoot()?->bootTools();

    expect(BootHookTool::$bootCount)->toBe(1);
});

class PublishingTool extends \Martis\Tools\Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Publishing Tool', uriKey: 'publishing-tool');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/martis.php' => config_path('martis-publishing-tool-test.php'),
        ], 'publishing-tool-config');
    }
}

it('Tool::publishes() registers paths under the standard ServiceProvider buckets', function () {
    $reset = function () {
        \Illuminate\Support\ServiceProvider::$publishes[PublishingTool::class] = [];
        unset(\Illuminate\Support\ServiceProvider::$publishGroups['publishing-tool-config']);
    };
    $reset();

    \Martis\Facades\Martis::tools([new PublishingTool()]);
    \Martis\Facades\Martis::getFacadeRoot()?->bootTools();

    $perProvider = \Illuminate\Support\ServiceProvider::$publishes[PublishingTool::class] ?? [];
    $perTag = \Illuminate\Support\ServiceProvider::$publishGroups['publishing-tool-config'] ?? [];

    expect($perProvider)->not->toBeEmpty();
    expect(array_values($perTag)[0] ?? null)->toBe(config_path('martis-publishing-tool-test.php'));

    $reset();
});
