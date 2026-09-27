<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Facades\Martis;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisManager;
use Martis\Menu\MenuGroup;
use Martis\Menu\MenuItem;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class PaletteTestModel extends Model
{
    protected $table = 'palette_test_items';

    protected $fillable = ['title'];
}

// A standalone action WITH an icon — the exact shape that crashed the whole
// palette in v1.25: CommandPaletteController read the protected `$icon`
// property directly, so `GET /api/command-palette` 500'd for any resource
// exposing a standalone action.
class PaletteTestExportAction extends Action
{
    public ?string $name = 'Export All';

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('ok');
    }
}

class PaletteTestResource extends Resource
{
    public static function model(): string
    {
        return PaletteTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'palette-test-items';
    }

    public static function label(): string
    {
        return 'Palette Items';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable(),
        ];
    }

    public function actions(Request $request): array
    {
        return [
            PaletteTestExportAction::make()->standalone()->icon('rocket-launch'),
        ];
    }
}

// A System-section resource: belongsToSystemSection() === true, group() === null
// (the exact shape of the bundled ActionEventResource). The sidebar renders it
// under a dedicated "System" header; the palette must agree.
class PaletteSystemResource extends Resource
{
    public static function model(): string
    {
        return PaletteTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'palette-system-items';
    }

    public static function label(): string
    {
        return 'Palette System Items';
    }

    public function belongsToSystemSection(): bool
    {
        return true;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
        ];
    }
}

// A Tool the current user is NOT allowed to see — must never appear in ⌘K.
class PaletteDeniedTool extends Tool
{
    public function __construct()
    {
        parent::__construct('Secret Tool', 'palette-secret-tool');
    }

    public function authorizedToSee(Request $request): bool
    {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::create('palette_test_items', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    app(ResourceRegistry::class)->register(PaletteTestResource::class);
    app(ResourceRegistry::class)->register(PaletteSystemResource::class);

    Martis::tools([
        Tool::make('Standards', 'standards')->withIcon('book')->withMenuSection('Knowledge'),
        new PaletteDeniedTool,
    ]);
});

afterEach(function () {
    Schema::dropIfExists('palette_test_items');
    app(ResourceRegistry::class)->flush();
    Martis::tools([]);
    Martis::forgetCommandPalette();
});

// ---------------------------------------------------------------------------
// Bug #1: standalone action must not 500 the palette (getIcon vs $icon)
// ---------------------------------------------------------------------------

it('returns 200 (not 500) when a resource exposes a standalone action with an icon', function () {
    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();

    $actions = collect($response->json('actions'));
    $action = $actions->firstWhere('resourceUriKey', 'palette-test-items');

    expect($action)->not->toBeNull();
    expect($action['label'])->toBe('Export All');
    expect($action['icon'])->toBe('rocket-launch');
});

// ---------------------------------------------------------------------------
// Enhancement: registered Tools appear in their own palette section
// ---------------------------------------------------------------------------

it('lists an authorised Tool in the tools section, shaped like a resource row', function () {
    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();

    $tools = collect($response->json('tools'));
    $tool = $tools->firstWhere('uriKey', 'standards');

    expect($tool)->not->toBeNull();
    expect($tool['label'])->toBe('Standards');
    expect($tool['icon'])->toBe('book');
    expect($tool['group'])->toBe('Knowledge');
    expect($tool['url'])->toBe('/tools/standards');
});

it('excludes a Tool the user is not authorised to see (security)', function () {
    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();

    $tools = collect($response->json('tools'));

    expect($tools->firstWhere('uriKey', 'palette-secret-tool'))->toBeNull();
});

it('tags an opted-in System-section Tool with the "System" group label, mirroring the sidebar', function () {
    Martis::tools([
        Tool::make('Standards', 'standards')->withIcon('book')->withMenuSection('Knowledge'),
        Tool::make('Health', 'palette-system-health')->withMenuSection('Operations')->withSystemSection(),
    ]);

    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();

    $tools = collect($response->json('tools'));

    // The sidebar renders the opted-in tool under "System" regardless of
    // its menuSection(); the palette tag must agree (same rule as the
    // v1.29.3 fix for belongsToSystemSection() resources).
    expect($tools->firstWhere('uriKey', 'palette-system-health')['group'])
        ->toBe(__('martis::messages.system'));

    // A plain menuSection() tool keeps its own label.
    expect($tools->firstWhere('uriKey', 'standards')['group'])->toBe('Knowledge');
});

// ---------------------------------------------------------------------------
// Bug: System-section resources rendered with no palette group tag, even
// though the sidebar groups them under "System" (belongsToSystemSection was
// consulted by the sidebar but not by the palette).
// ---------------------------------------------------------------------------

it('tags a System-section resource with the System group (matches the sidebar)', function () {
    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();

    $resources = collect($response->json('resources'));
    $entry = $resources->firstWhere('uriKey', 'palette-system-items');

    expect($entry)->not->toBeNull();
    // The sidebar renders belongsToSystemSection() resources under the
    // __('martis::messages.system') header; the palette tag must agree
    // (before the fix this was null — no tag).
    expect($entry['group'])->toBe(__('martis::messages.system'));
});

it('leaves a non-System resource group untouched (fix is scoped)', function () {
    $response = $this->getJson('/martis/api/command-palette');

    $response->assertOk();

    $resources = collect($response->json('resources'));
    $entry = $resources->firstWhere('uriKey', 'palette-test-items');

    // PaletteTestResource is not a System-section resource and declares no
    // group() — its palette tag stays null, unaffected by the fix.
    expect($entry)->not->toBeNull();
    expect($entry['group'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Enhancement: app-registered commands (Martis::commandPalette(), v2.1.0)
// ---------------------------------------------------------------------------

it('lists the commands an app registers, with the label of a MenuGroup as their group', function () {
    Martis::commandPalette(fn (Request $request) => [
        MenuItem::link('Open analyses', '/tools/analyses')->icon('chart-line'),
        MenuGroup::make('Reports', [
            MenuItem::externalLink('Status page', 'https://status.example.com'),
        ]),
    ]);

    $commands = $this->getJson('/martis/api/command-palette')->assertOk()->json('commands');

    expect($commands)->toBe([
        ['key' => 'command:0', 'label' => 'Open analyses', 'url' => '/tools/analyses', 'external' => false, 'icon' => 'chart-line', 'group' => null],
        ['key' => 'command:1', 'label' => 'Status page', 'url' => 'https://status.example.com', 'external' => true, 'icon' => null, 'group' => 'Reports'],
    ]);
});

it('leaves out a command canSee() hides and a tool the user may not see', function () {
    Martis::commandPalette(fn () => [
        MenuItem::link('Hidden', '/hidden')->canSee(fn () => false),
        MenuItem::tool(PaletteDeniedTool::class),
        MenuItem::link('Visible', '/visible'),
    ]);

    $labels = collect($this->getJson('/martis/api/command-palette')->json('commands'))->pluck('label')->all();

    expect($labels)->toBe(['Visible']);
});

it('accumulates the commands of several registrations', function () {
    Martis::commandPalette(fn () => [MenuItem::link('First', '/first')]);
    Martis::commandPalette(fn () => [MenuItem::link('Second', '/second')]);

    $labels = collect($this->getJson('/martis/api/command-palette')->json('commands'))->pluck('label')->all();

    expect($labels)->toBe(['First', 'Second']);
});

it('answers an empty commands list when no app registers any', function () {
    expect($this->getJson('/martis/api/command-palette')->assertOk()->json('commands'))->toBe([]);
});

it('fails loudly on an entry that is not a MenuItem or a MenuGroup', function () {
    Martis::commandPalette(fn () => ['/not-a-menu-item']);

    expect(fn () => app(MartisManager::class)->resolveCommandPalette(request()))
        ->toThrow(InvalidArgumentException::class, 'string given');
});

it('accepts a resolver that returns a Collection of entries', function () {
    Martis::commandPalette(fn () => collect([MenuItem::link('First', '/first'), MenuItem::link('Second', '/second')]));

    $labels = collect($this->getJson('/martis/api/command-palette')->json('commands'))->pluck('label')->all();

    expect($labels)->toBe(['First', 'Second']);
});

it('fails loudly on a resolver that returns a single MenuItem instead of an iterable', function () {
    Martis::commandPalette(fn () => MenuItem::link('Lonely', '/lonely'));

    expect(fn () => app(MartisManager::class)->resolveCommandPalette(request()))
        ->toThrow(InvalidArgumentException::class, 'Martis::commandPalette() resolvers must return an iterable of MenuItem/MenuGroup, Martis\Menu\MenuItem given.');
});
