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
 * Order of the items inside the bundled "System" sidebar section (v1.38.0).
 *
 * The section used to be assembled in a fixed order (System-section
 * resources, then System-section Tools, then the Cache admin link) and a
 * host could not change it. Resources and Tools now carry a
 * `systemSectionOrder()` weight (default 100, `Tool::withSystemSection(order:)`
 * sets it) and the Cache link reads `martis.cache.admin_ui_order` (default
 * 1000); the merged list is sorted by that weight, ties keeping the natural
 * order, so a host that sets nothing gets exactly the old output. The Cache
 * link also joins the `Martis::mainMenu(...)` dedup: a host that places a
 * link to `/system/cache` itself does not get a second one.
 */

class SysOrderUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class SysOrderModel extends Model
{
    protected $table = 'martis_test_system_order_items';

    protected $fillable = ['name'];
}

abstract class SysOrderSystemResource extends Resource
{
    public static function model(): string
    {
        return SysOrderModel::class;
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

class SysOrderAuditLogResource extends SysOrderSystemResource
{
    public static function uriKey(): string
    {
        return 'sys-order-audit-log';
    }

    public static function label(): string
    {
        return 'Audit Log';
    }
}

class SysOrderActionEventsResource extends SysOrderSystemResource
{
    public static function uriKey(): string
    {
        return 'sys-order-action-events';
    }

    public static function label(): string
    {
        return 'Action Events';
    }
}

class SysOrderFirstResource extends SysOrderSystemResource
{
    public static function uriKey(): string
    {
        return 'sys-order-first';
    }

    public static function label(): string
    {
        return 'Pinned first';
    }

    public function systemSectionOrder(): int
    {
        return 1;
    }
}

class SysOrderHiddenResource extends SysOrderSystemResource
{
    public static function uriKey(): string
    {
        return 'sys-order-hidden';
    }

    public function systemSectionOrder(): int
    {
        return 1;
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

function sysOrderSystemItems($test): array
{
    $sections = collect($test->getJson('/martis/api/navigation')->assertOk()->json());
    $system = $sections->where('label', __('martis::messages.system'));

    expect($system)->toHaveCount(1);

    return array_values($system->first()['items']);
}

/** Identify each System item: resource / tool uriKey, or the link URL. */
function sysOrderKeys(array $items): array
{
    return array_map(
        fn (array $item): string => $item['type'] === 'link' ? 'link:'.$item['url'] : $item['type'].':'.$item['uriKey'],
        $items,
    );
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_system_order_items');
    Schema::create('martis_test_system_order_items', function ($table) {
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

    $this->admin = SysOrderUser::query()->create([
        'name' => 'Order Admin',
        'email' => 'sysorder-admin@martis.test',
        'password' => bcrypt('secret'),
    ]);

    config()->set('martis.cache.admin_ui', true);
    Gate::define('manage-martis-cache', fn ($user) => true);
    $this->actingAs($this->admin, 'web');

    app(MartisManager::class)->forgetMainMenu();
    app(MartisManager::class)->tools([]);
    app(ResourceRegistry::class)->flush();
});

afterEach(function () {
    app(MartisManager::class)->forgetMainMenu();
    app(MartisManager::class)->tools([]);
    app(ResourceRegistry::class)->flush();
    Schema::dropIfExists('martis_test_system_order_items');
});

// ---------------------------------------------------------------------------
// The weights themselves
// ---------------------------------------------------------------------------

it('defaults systemSectionOrder() to 100 on Resources and Tools', function () {
    expect((new SysOrderAuditLogResource)->systemSectionOrder())->toBe(100)
        ->and(Tool::make('Settings', 'settings')->systemSectionOrder())->toBe(100)
        ->and(Tool::make('Settings', 'settings')->withSystemSection()->systemSectionOrder())->toBe(100);
});

it('sets the flag and the weight in one call with withSystemSection(order:)', function () {
    $tool = Tool::make('Settings', 'settings');

    expect($tool->withSystemSection(order: 10))->toBe($tool)
        ->and($tool->belongsToSystemSection())->toBeTrue()
        ->and($tool->systemSectionOrder())->toBe(10);

    // Toggling the opt-in off and on again keeps the weight already set.
    $tool->withSystemSection(false)->withSystemSection();
    expect($tool->systemSectionOrder())->toBe(10);
});

// ---------------------------------------------------------------------------
// Navigation payload
// ---------------------------------------------------------------------------

it('keeps the v1.37.3 order when no weight is set: resources, tools, then the cache link', function () {
    app(ResourceRegistry::class)->register(SysOrderAuditLogResource::class);
    app(ResourceRegistry::class)->register(SysOrderActionEventsResource::class);
    app(MartisManager::class)->tools([
        Tool::make('Settings', 'settings')->withSystemSection(),
        Tool::make('System Events', 'system-events')->withSystemSection(),
    ]);

    expect(sysOrderKeys(sysOrderSystemItems($this)))->toBe([
        'resource:sys-order-audit-log',
        'resource:sys-order-action-events',
        'tool:settings',
        'tool:system-events',
        'link:/system/cache',
    ]);
});

it('orders tools ahead of resources with withSystemSection(order:) (the reported layout)', function () {
    app(ResourceRegistry::class)->register(SysOrderAuditLogResource::class);
    app(ResourceRegistry::class)->register(SysOrderActionEventsResource::class);
    app(MartisManager::class)->tools([
        Tool::make('Settings', 'settings')->withSystemSection(order: 10),
        Tool::make('System Events', 'system-events')->withSystemSection(order: 20),
    ]);

    $items = sysOrderSystemItems($this);

    expect(sysOrderKeys($items))->toBe([
        'tool:settings',
        'tool:system-events',
        'resource:sys-order-audit-log',
        'resource:sys-order-action-events',
        'link:/system/cache',
    ]);
    expect(array_column($items, 'label'))->toBe([
        'Settings', 'System Events', 'Audit Log', 'Action Events', __('martis::messages.cache_admin_title'),
    ]);
});

it('orders a resource by its systemSectionOrder() override and keeps ties in registration order', function () {
    app(ResourceRegistry::class)->register(SysOrderAuditLogResource::class);
    app(ResourceRegistry::class)->register(SysOrderActionEventsResource::class);
    app(ResourceRegistry::class)->register(SysOrderFirstResource::class);
    app(MartisManager::class)->tools([
        Tool::make('Settings', 'settings')->withSystemSection(order: 100),
    ]);

    expect(sysOrderKeys(sysOrderSystemItems($this)))->toBe([
        'resource:sys-order-first',
        'resource:sys-order-audit-log',
        'resource:sys-order-action-events',
        'tool:settings',
        'link:/system/cache',
    ]);
});

it('places the cache link by martis.cache.admin_ui_order', function () {
    config()->set('martis.cache.admin_ui_order', 50);

    app(ResourceRegistry::class)->register(SysOrderAuditLogResource::class);
    app(MartisManager::class)->tools([
        Tool::make('Settings', 'settings')->withSystemSection(order: 10),
    ]);

    expect(sysOrderKeys(sysOrderSystemItems($this)))->toBe([
        'tool:settings',
        'link:/system/cache',
        'resource:sys-order-audit-log',
    ]);
});

it('keeps the cache link last when the published config sets its weight to null', function () {
    config()->set('martis.cache.admin_ui_order', null);

    app(ResourceRegistry::class)->register(SysOrderAuditLogResource::class);

    expect(sysOrderKeys(sysOrderSystemItems($this)))->toBe([
        'resource:sys-order-audit-log',
        'link:/system/cache',
    ]);
});

it('still drops the items the user may not see, whatever their weight', function () {
    app(ResourceRegistry::class)->register(SysOrderHiddenResource::class);
    app(ResourceRegistry::class)->register(SysOrderAuditLogResource::class);
    app(MartisManager::class)->tools([
        Tool::make('Secret', 'secret')->withSystemSection(order: 1)->canSee(fn () => false),
        Tool::make('Settings', 'settings')->withSystemSection(order: 10),
    ]);
    Gate::define('manage-martis-cache', fn ($user) => false);

    expect(sysOrderKeys(sysOrderSystemItems($this)))->toBe([
        'tool:settings',
        'resource:sys-order-audit-log',
    ]);
});

it('collapses the section when no ordered item is visible', function () {
    config()->set('martis.cache.admin_ui', false);

    app(ResourceRegistry::class)->register(SysOrderHiddenResource::class);
    app(MartisManager::class)->tools([
        Tool::make('Secret', 'secret')->withSystemSection(order: 1)->canSee(fn () => false),
    ]);

    $sections = collect($this->getJson('/martis/api/navigation')->assertOk()->json());

    expect($sections->where('label', __('martis::messages.system')))->toHaveCount(0);
});

it('builds each user its own ordered section under the per-user navigation cache', function () {
    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.navigation', ['enabled' => true, 'ttl' => 5]);

    app(ResourceRegistry::class)->register(SysOrderAuditLogResource::class);
    app(MartisManager::class)->tools([
        Tool::make('Settings', 'settings')->withSystemSection(order: 10),
        Tool::make('Admin only', 'admin-only')->withSystemSection(order: 5)
            ->canSee(fn (Request $request) => $request->user()?->email === 'sysorder-admin@martis.test'),
    ]);
    Gate::define('manage-martis-cache', fn ($user) => $user->email === 'sysorder-admin@martis.test');

    $other = SysOrderUser::query()->create([
        'name' => 'Operator',
        'email' => 'sysorder-operator@martis.test',
        'password' => bcrypt('secret'),
    ]);

    expect(sysOrderKeys(sysOrderSystemItems($this)))->toBe([
        'tool:admin-only', 'tool:settings', 'resource:sys-order-audit-log', 'link:/system/cache',
    ]);

    $this->actingAs($other, 'web');
    expect(sysOrderKeys(sysOrderSystemItems($this)))->toBe([
        'tool:settings', 'resource:sys-order-audit-log',
    ]);

    // The first user's cached payload is untouched by the second request.
    $this->actingAs($this->admin, 'web');
    expect(sysOrderKeys(sysOrderSystemItems($this)))->toBe([
        'tool:admin-only', 'tool:settings', 'resource:sys-order-audit-log', 'link:/system/cache',
    ]);
});

// ---------------------------------------------------------------------------
// Cache link dedup against Martis::mainMenu(...)
// ---------------------------------------------------------------------------

it('does not append the cache link when the host menu already links to /system/cache', function () {
    app(ResourceRegistry::class)->register(SysOrderAuditLogResource::class);
    $settings = Tool::make('Settings', 'settings')->withSystemSection();
    app(MartisManager::class)->tools([$settings]);

    // A host that owns the whole System section, in its own order.
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu) use ($settings): Menu {
        return $menu->append(MenuSection::make(__('martis::messages.system'), [
            MenuItem::tool($settings),
            MenuItem::link('System cache', '/system/cache/')->icon('database'),
            MenuItem::resource(SysOrderAuditLogResource::class),
        ]));
    });

    $sections = collect($this->getJson('/martis/api/navigation')->assertOk()->json());
    $system = $sections->where('label', __('martis::messages.system'));

    // One "System" header: the host's, in the host's order.
    expect($system)->toHaveCount(1);
    expect(sysOrderKeys(array_values($system->first()['items'])))->toBe([
        'tool:settings',
        'link:/system/cache/',
        'resource:sys-order-audit-log',
    ]);
});

it('still appends the cache link when the host only links elsewhere or externally', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->append(MenuSection::make('Links', [
            MenuItem::link('Cache docs', '/system/cache-docs'),
            MenuItem::externalLink('Remote cache', 'https://example.test/system/cache'),
            MenuItem::link('Other host', 'https://other.test/system/cache'),
        ]));
    });

    expect(sysOrderKeys(sysOrderSystemItems($this)))->toBe(['link:/system/cache']);
});
