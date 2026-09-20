<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisManager;
use Martis\Menu\Menu;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

/*
 * Tools in the bundled "System" sidebar section (v1.35.0).
 *
 * Resources could opt into the package's System section via
 * `belongsToSystemSection()`; Tools only had `menuSection()`, so a Tool
 * returning the translated "System" label produced a *second* section
 * with the same header. `Tool::withSystemSection()` gives Tools the same
 * opt-in and the navigation builder merges them into the single bundled
 * section: system resources first, then tools, then the Cache admin link.
 */

class ToolSysSecUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class ToolSysSecModel extends Model
{
    protected $table = 'martis_test_tool_system_items';

    protected $fillable = ['name'];
}

class ToolSysSecRolesResource extends Resource
{
    public static function model(): string
    {
        return ToolSysSecModel::class;
    }

    public static function uriKey(): string
    {
        return 'tool-system-roles';
    }

    public static function label(): string
    {
        return 'Roles';
    }

    public static function singularLabel(): string
    {
        return 'Role';
    }

    public function belongsToSystemSection(): bool
    {
        return true;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class ToolSysSecHealthTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Health', uriKey: 'tool-system-health');
        $this->withIcon('pulse')->withSystemSection();
    }

    public function menuCount(Request $request): ?int
    {
        return 3;
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_tool_system_items');
    Schema::create('martis_test_tool_system_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    $this->testUser = ToolSysSecUser::query()->create([
        'name' => 'Sys Admin',
        'email' => 'toolsysadmin@martis.test',
        'password' => bcrypt('secret'),
    ]);

    app(MartisManager::class)->forgetMainMenu();
    app(MartisManager::class)->tools([]);
    app(ResourceRegistry::class)->flush();
});

afterEach(function () {
    app(MartisManager::class)->forgetMainMenu();
    app(MartisManager::class)->tools([]);
    app(ResourceRegistry::class)->flush();
    Schema::dropIfExists('martis_test_tool_system_items');
});

it('Tool::belongsToSystemSection() defaults to false and withSystemSection() toggles it', function () {
    $tool = Tool::make('Settings', 'tool-system-settings');

    expect($tool->belongsToSystemSection())->toBeFalse();
    expect($tool->toArray()['belongsToSystemSection'])->toBeFalse();

    // Fluent: returns the same instance so it chains in the constructor.
    expect($tool->withSystemSection())->toBe($tool);
    expect($tool->belongsToSystemSection())->toBeTrue();
    expect($tool->toArray()['belongsToSystemSection'])->toBeTrue();

    $tool->withSystemSection(false);
    expect($tool->belongsToSystemSection())->toBeFalse();
});

it('renders an opted-in Tool inside the single bundled System section, after the system resources and before the cache link', function () {
    config()->set('martis.cache.admin_ui', true);
    Gate::define('manage-martis-cache', fn ($user) => true);
    $this->actingAs($this->testUser, 'web');

    app(ResourceRegistry::class)->register(ToolSysSecRolesResource::class);
    app(MartisManager::class)->tools([new ToolSysSecHealthTool]);

    $sections = collect($this->getJson('/martis/api/navigation')->assertOk()->json());
    $systemLabel = __('martis::messages.system');

    // Exactly one "System" header in the whole payload.
    expect($sections->where('label', $systemLabel))->toHaveCount(1);

    $items = collect($sections->firstWhere('label', $systemLabel)['items']);

    expect($items->pluck('type')->all())->toBe(['resource', 'tool', 'link']);
    expect($items[0]['uriKey'])->toBe('tool-system-roles');
    expect($items[1])->toMatchArray([
        'type' => 'tool',
        'label' => 'Health',
        'uriKey' => 'tool-system-health',
        'url' => '/tools/tool-system-health',
        'icon' => 'pulse',
        'count' => 3,
    ]);
    expect($items[2]['url'])->toBe('/system/cache');

    // No "Tools" bucket is created for a System-section tool.
    expect($sections->firstWhere('label', 'Tools'))->toBeNull();
});

it('does not merge a Tool whose menuSection() merely equals the System label (no label matching)', function () {
    config()->set('martis.cache.admin_ui', true);
    Gate::define('manage-martis-cache', fn ($user) => true);
    $this->actingAs($this->testUser, 'web');

    app(MartisManager::class)->tools([
        Tool::make('Settings', 'tool-system-settings')->withMenuSection(__('martis::messages.system')),
    ]);

    $sections = collect($this->getJson('/martis/api/navigation')->assertOk()->json());

    // Backwards-compatible, documented: a label-only match still yields
    // two sections (the Tool's own bucket and the bundled one). The
    // opt-in is explicit, never inferred from a translated label.
    expect($sections->where('label', __('martis::messages.system')))->toHaveCount(2);
});

it('lets the System opt-in take precedence over menuSection()', function () {
    config()->set('martis.cache.admin_ui', false);

    app(MartisManager::class)->tools([
        Tool::make('Providers', 'tool-system-providers')
            ->withMenuSection('Operations')
            ->withSystemSection(),
    ]);

    $sections = collect($this->getJson('/martis/api/navigation')->assertOk()->json());

    expect($sections->firstWhere('label', 'Operations'))->toBeNull();

    $system = $sections->firstWhere('label', __('martis::messages.system'));
    expect($system)->not->toBeNull();
    expect(collect($system['items'])->pluck('uriKey')->all())->toBe(['tool-system-providers']);
});

it('renders the System section for an opted-in Tool alone (no system resources, cache admin off)', function () {
    config()->set('martis.cache.admin_ui', false);

    app(MartisManager::class)->tools([new ToolSysSecHealthTool]);

    $sections = collect($this->getJson('/martis/api/navigation')->assertOk()->json());
    $system = $sections->firstWhere('label', __('martis::messages.system'));

    expect($system)->not->toBeNull();
    expect(collect($system['items'])->pluck('url')->all())->toBe(['/tools/tool-system-health']);
});

it('keeps the opted-in Tool count in /api/navigation/badges', function () {
    app(MartisManager::class)->tools([new ToolSysSecHealthTool]);

    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertOk();
    expect($response->json())->toHaveKey('tool:tool-system-health', 3);
});

it('does not auto-inject an opted-in Tool the host already placed via Martis::mainMenu()', function () {
    config()->set('martis.cache.admin_ui', true);
    Gate::define('manage-martis-cache', fn ($user) => true);
    $this->actingAs($this->testUser, 'web');

    $tool = new ToolSysSecHealthTool;
    app(MartisManager::class)->tools([$tool]);
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu) use ($tool): Menu {
        return $menu->prepend(MenuSection::make('Pinned', [
            MenuItem::tool($tool),
        ]));
    });

    $sections = collect($this->getJson('/martis/api/navigation')->assertOk()->json());

    $pinned = $sections->firstWhere('label', 'Pinned');
    expect($pinned)->not->toBeNull();
    expect(collect($pinned['items'])->pluck('uriKey')->all())->toBe(['tool-system-health']);

    // The bundled section still renders (cache link) but must not repeat the tool.
    $system = $sections->firstWhere('label', __('martis::messages.system'));
    expect($system)->not->toBeNull();
    expect(collect($system['items'])->pluck('uriKey')->filter()->all())->not->toContain('tool-system-health');

    // Exactly one occurrence across the whole payload (groups flattened).
    $leaves = $sections
        ->flatMap(fn ($section) => $section['items'])
        ->flatMap(fn ($item) => ($item['type'] ?? null) === 'group' ? $item['items'] : [$item]);
    expect($leaves->where('uriKey', 'tool-system-health'))->toHaveCount(1);
});

it('omits an opted-in Tool the user cannot see while keeping the section for the cache link', function () {
    config()->set('martis.cache.admin_ui', true);
    Gate::define('manage-martis-cache', fn ($user) => true);
    $this->actingAs($this->testUser, 'web');

    app(MartisManager::class)->tools([
        Tool::make('Hidden', 'tool-system-hidden')->withSystemSection()->canSee(fn () => false),
    ]);

    $sections = collect($this->getJson('/martis/api/navigation')->assertOk()->json());
    $system = $sections->firstWhere('label', __('martis::messages.system'));

    expect($system)->not->toBeNull();
    expect(collect($system['items'])->pluck('url')->all())->toBe(['/system/cache']);
});
