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

    Martis::tools([
        Tool::make('Standards', 'standards')->withIcon('book')->withMenuSection('Knowledge'),
        new PaletteDeniedTool,
    ]);
});

afterEach(function () {
    Schema::dropIfExists('palette_test_items');
    app(ResourceRegistry::class)->flush();
    Martis::tools([]);
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
