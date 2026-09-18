<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Exceptions\MenuCountFailedException;
use Martis\Facades\Martis;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisManager;
use Martis\Menu\Menu;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

// A count badge whose `menuCount()` throws must never take the sidebar or
// the badges endpoint down (that resilience is by design), but the failure
// must not vanish either: it is reported through the app's exception
// handler and, with dev tools on, surfaced in the badges payload under the
// reserved `_failed` key.

class CountFailureTestModel extends Model
{
    protected $table = 'martis_test_count_failure_items';

    protected $fillable = ['name'];

    public $timestamps = false;
}

class CountFailureHealthyResource extends Resource
{
    public static function model(): string
    {
        return CountFailureTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'count-failure-healthy';
    }

    public static function label(): string
    {
        return 'Healthy';
    }

    public static function singularLabel(): string
    {
        return 'Healthy';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class CountFailureBrokenResource extends CountFailureHealthyResource
{
    public static function uriKey(): string
    {
        return 'count-failure-broken';
    }

    public static function menuCount(Request $request): ?int
    {
        throw new RuntimeException('No tenant is resolved in the current context');
    }
}

class CountFailureBrokenTool extends Tool
{
    public function __construct()
    {
        parent::__construct('Broken tool', 'count-failure-broken-tool');
    }

    public function menuCount(Request $request): ?int
    {
        throw new LogicException('tool counter exploded');
    }
}

class CountFailureHealthyTool extends Tool
{
    public function __construct()
    {
        parent::__construct('Healthy tool', 'count-failure-healthy-tool');
    }

    public function menuCount(Request $request): ?int
    {
        return 9;
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_count_failure_items');
    Schema::create('martis_test_count_failure_items', function ($table) {
        $table->id();
        $table->string('name');
    });

    CountFailureTestModel::create(['name' => 'Alpha']);
    CountFailureTestModel::create(['name' => 'Bravo']);

    app(MartisManager::class)->forgetMainMenu();
    Martis::tools([]);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CountFailureHealthyResource::class);
    $registry->register(CountFailureBrokenResource::class);

    config()->set('martis.navigation.counts.enabled', true);
    config()->set('martis.navigation.counts.report_failures', true);
    // Production-like default; the `_failed` tests flip it on explicitly.
    config()->set('martis.dev.tools_enabled', false);
});

afterEach(function () {
    app(MartisManager::class)->forgetMainMenu();
    Martis::tools([]);
    Schema::dropIfExists('martis_test_count_failure_items');
});

/**
 * Match a reported MenuCountFailedException for the given subject class.
 *
 * @return Closure(mixed): bool
 */
function countFailureReportedFor(string $subjectClass, string $badgeKey, string $previousClass): Closure
{
    return function (mixed $e) use ($subjectClass, $badgeKey, $previousClass): bool {
        return $e instanceof MenuCountFailedException
            && $e->subjectClass() === $subjectClass
            && $e->badgeKey() === $badgeKey
            && $e->getPrevious() instanceof $previousClass
            && str_contains($e->getMessage(), $subjectClass)
            && str_contains($e->getMessage(), $e->getPrevious()->getMessage());
    };
}

// ---------------------------------------------------------------------------
// Badges endpoint
// ---------------------------------------------------------------------------

it('reports a resource menuCount() failure through the exception handler while keeping the badges endpoint up', function () {
    $handler = $this->spy(ExceptionHandler::class);

    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertOk();
    expect($response->json())->toHaveKey('resource:count-failure-healthy', 2);
    expect($response->json())->not->toHaveKey('resource:count-failure-broken');

    $handler->shouldHaveReceived('report')
        ->once()
        ->withArgs(countFailureReportedFor(
            CountFailureBrokenResource::class,
            'resource:count-failure-broken',
            RuntimeException::class,
        ));
});

it('reports a tool menuCount() failure through the exception handler while keeping the badges endpoint up', function () {
    app(ResourceRegistry::class)->flush();
    Martis::tools([new CountFailureHealthyTool, new CountFailureBrokenTool]);
    $handler = $this->spy(ExceptionHandler::class);

    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertOk();
    expect($response->json())->toHaveKey('tool:count-failure-healthy-tool', 9);
    expect($response->json())->not->toHaveKey('tool:count-failure-broken-tool');

    $handler->shouldHaveReceived('report')
        ->once()
        ->withArgs(countFailureReportedFor(
            CountFailureBrokenTool::class,
            'tool:count-failure-broken-tool',
            LogicException::class,
        ));
});

it('does not report menuCount() failures when counts.report_failures is off', function () {
    config()->set('martis.navigation.counts.report_failures', false);
    $handler = $this->spy(ExceptionHandler::class);

    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertOk();
    expect($response->json())->not->toHaveKey('resource:count-failure-broken');
    $handler->shouldNotHaveReceived('report');
});

// ---------------------------------------------------------------------------
// Dev-mode diagnostics: reserved `_failed` key in the badges payload
// ---------------------------------------------------------------------------

it('lists failed counters under _failed in the badges payload when dev tools are on', function () {
    config()->set('martis.dev.tools_enabled', true);
    Martis::tools([new CountFailureBrokenTool]);

    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertOk();
    expect($response->json())->toHaveKey('resource:count-failure-healthy', 2);
    expect($response->json('_failed'))->toBe([
        'resource:count-failure-broken' => 'RuntimeException: No tenant is resolved in the current context',
        'tool:count-failure-broken-tool' => 'LogicException: tool counter exploded',
    ]);
});

it('never adds _failed to the badges payload when dev tools are off', function () {
    Martis::tools([new CountFailureBrokenTool]);

    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertOk();
    expect($response->json())->not->toHaveKey('_failed');
});

it('omits _failed entirely when every counter succeeds, even with dev tools on', function () {
    config()->set('martis.dev.tools_enabled', true);
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CountFailureHealthyResource::class);

    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertOk();
    expect($response->json())->toBe(['resource:count-failure-healthy' => 2]);
});

// ---------------------------------------------------------------------------
// Full navigation payload (MenuItem::resource / MenuItem::tool)
// ---------------------------------------------------------------------------

it('reports a resource menuCount() failure from a custom main menu and renders the item without a count', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(
            MenuSection::make('Custom', [MenuItem::resource(CountFailureBrokenResource::class)])
        );
    });
    $handler = $this->spy(ExceptionHandler::class);

    $response = $this->getJson('/martis/api/navigation');

    $response->assertOk();
    $item = collect($response->json())->firstWhere('label', 'Custom')['items'][0];
    expect($item['uriKey'])->toBe('count-failure-broken');
    expect($item['count'])->toBeNull();

    $handler->shouldHaveReceived('report')
        ->withArgs(countFailureReportedFor(
            CountFailureBrokenResource::class,
            'resource:count-failure-broken',
            RuntimeException::class,
        ));
});

it('reports a tool menuCount() failure from a MenuItem::tool item and renders the item without a count', function () {
    $handler = $this->spy(ExceptionHandler::class);

    $item = MenuItem::tool(new CountFailureBrokenTool)->resolve(request());

    expect($item)->not->toBeNull();
    expect($item['count'])->toBeNull();

    $handler->shouldHaveReceived('report')
        ->once()
        ->withArgs(countFailureReportedFor(
            CountFailureBrokenTool::class,
            'tool:count-failure-broken-tool',
            LogicException::class,
        ));
});

it('does not report from MenuItem when counts.report_failures is off', function () {
    config()->set('martis.navigation.counts.report_failures', false);
    $handler = $this->spy(ExceptionHandler::class);

    $item = MenuItem::resource(CountFailureBrokenResource::class)->resolve(request());

    expect($item['count'])->toBeNull();
    $handler->shouldNotHaveReceived('report');
});
