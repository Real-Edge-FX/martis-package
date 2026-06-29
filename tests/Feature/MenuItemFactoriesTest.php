<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Dashboards\Dashboard;
use Martis\Enums\FilterType;
use Martis\Enums\MenuItemType;
use Martis\Fields\Text;
use Martis\Filters\Filter;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\MartisManager;
use Martis\Menu\Menu;
use Martis\Menu\MenuGroup;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Resources\ActionEventResource;

class MenuFactoryModel extends Model
{
    protected $table = 'menu_factory_items';

    protected $fillable = ['title', 'status'];
}

class MenuFactoryStatusFilter extends Filter
{
    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }

    public function options(Request $request): array
    {
        return ['open' => 'Open', 'closed' => 'Closed'];
    }

    public function uriKey(): string
    {
        return 'status';
    }

    public function filterType(): FilterType
    {
        return FilterType::Select;
    }
}

class MenuFactoryRecentLens extends Lens
{
    public function name(): string
    {
        return 'Recent';
    }

    public function uriKey(): string
    {
        return 'recent';
    }

    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class MenuFactoryHiddenLens extends Lens
{
    public function name(): string
    {
        return 'Hidden';
    }

    public function uriKey(): string
    {
        return 'hidden';
    }

    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query;
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function authorizedToSee(Request $request): bool
    {
        return false;
    }
}

class MenuFactoryTicketResource extends Resource
{
    public static function model(): string
    {
        return MenuFactoryModel::class;
    }

    public static function uriKey(): string
    {
        return 'menu-factory-tickets';
    }

    public static function label(): string
    {
        return 'Tickets';
    }

    public static function singularLabel(): string
    {
        return 'Ticket';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title'), Text::make('status')];
    }
}

class MenuFactorySalesDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct('Sales', 'sales');
    }

    public function cards(Request $request): array
    {
        return [];
    }
}

class MenuFactoryHiddenDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct('Hidden', 'menu-hidden');
    }

    public function cards(Request $request): array
    {
        return [];
    }

    public function authorizedToSee(Request $request): bool
    {
        return false;
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('menu_factory_items');
    Schema::create('menu_factory_items', function ($t) {
        $t->id();
        $t->string('title');
        $t->string('status')->default('open');
        $t->timestamps();
    });

    app(MartisManager::class)->forgetMainMenu();
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MenuFactoryTicketResource::class);
});

afterEach(function () {
    app(MartisManager::class)->forgetMainMenu();
    Schema::dropIfExists('menu_factory_items');
});

it('builds a dashboard menu item lazily', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(MenuSection::make('Insights', [
            MenuItem::dashboard(MenuFactorySalesDashboard::class),
        ]));
    });

    $response = $this->getJson('/martis/api/navigation');

    $response->assertJsonPath('0.items.0.type', MenuItemType::Dashboard->value);
    $response->assertJsonPath('0.items.0.label', 'Sales');
    $response->assertJsonPath('0.items.0.url', '/dashboards/sales');
    $response->assertJsonPath('0.items.0.uriKey', 'sales');
});

it('drops a dashboard item when authorizedToSee returns false', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(MenuSection::make('Insights', [
            MenuItem::dashboard(MenuFactoryHiddenDashboard::class),
        ]));
    });

    $response = $this->getJson('/martis/api/navigation');
    $sections = collect($response->json());

    expect($sections->firstWhere('label', 'Insights'))->toBeNull();
});

it('builds a lens menu item with the canonical resource/lens URL', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(MenuSection::make('Lenses', [
            MenuItem::lens(MenuFactoryTicketResource::class, MenuFactoryRecentLens::class),
        ]));
    });

    $response = $this->getJson('/martis/api/navigation');

    $response->assertJsonPath('0.items.0.type', MenuItemType::Lens->value);
    $response->assertJsonPath('0.items.0.label', 'Recent');
    $response->assertJsonPath('0.items.0.url', '/resources/menu-factory-tickets/lens/recent');
    $response->assertJsonPath('0.items.0.resourceUriKey', 'menu-factory-tickets');
});

it('drops a lens item when the lens denies the user', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(MenuSection::make('Lenses', [
            MenuItem::lens(MenuFactoryTicketResource::class, MenuFactoryHiddenLens::class),
        ]));
    });

    $response = $this->getJson('/martis/api/navigation');
    expect(collect($response->json())->firstWhere('label', 'Lenses'))->toBeNull();
});

it('builds a filter menu item with an encoded filter payload', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(MenuSection::make('Saved views', [
            MenuItem::filter('Open tickets', MenuFactoryTicketResource::class)
                ->applies(MenuFactoryStatusFilter::class, 'open')
                ->icon('lifebuoy'),
        ]));
    });

    $response = $this->getJson('/martis/api/navigation');

    $response->assertJsonPath('0.items.0.type', MenuItemType::Filter->value);
    $response->assertJsonPath('0.items.0.label', 'Open tickets');
    $response->assertJsonPath('0.items.0.icon', 'lifebuoy');
    $response->assertJsonPath('0.items.0.filterUriKey', 'status');
    $url = $response->json('0.items.0.url');
    expect($url)->toStartWith('/resources/menu-factory-tickets?filters=');
    expect(rawurldecode(substr($url, strpos($url, '?filters=') + 9)))
        ->toBe('{"status":"open"}');
});

it('attaches a decorative badge to any menu item', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(MenuSection::make('Quick', [
            MenuItem::link('What is new', '/whats-new')
                ->withBadge('New', 'success'),
        ]));
    });

    $response = $this->getJson('/martis/api/navigation');

    $response->assertJsonPath('0.items.0.badge.text', 'New');
    $response->assertJsonPath('0.items.0.badge.tone', 'success');
});

it('emits MenuSection::path on the section payload', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(
            MenuSection::make('Reports', [MenuItem::link('Stub', '/stub')])->path('/reports')
        );
    });

    $response = $this->getJson('/martis/api/navigation');

    $response->assertJsonPath('0.label', 'Reports');
    $response->assertJsonPath('0.path', '/reports');
});

it('serialises a nested MenuGroup with type=group inside a section', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(MenuSection::make('Settings', [
            MenuGroup::make('Auth', [
                MenuItem::link('Users', '/users')->icon('users'),
                MenuItem::link('Roles', '/roles')->icon('shield-check'),
            ])->icon('lock-key')->path('/settings/auth'),
            MenuItem::link('General', '/settings/general'),
        ]));
    });

    $response = $this->getJson('/martis/api/navigation');

    $response->assertJsonPath('0.label', 'Settings');
    $response->assertJsonPath('0.items.0.type', 'group');
    $response->assertJsonPath('0.items.0.label', 'Auth');
    $response->assertJsonPath('0.items.0.icon', 'lock-key');
    $response->assertJsonPath('0.items.0.path', '/settings/auth');
    $response->assertJsonPath('0.items.0.items.0.label', 'Users');
    $response->assertJsonPath('0.items.0.items.1.label', 'Roles');
    $response->assertJsonPath('0.items.1.label', 'General');
});

it('suppresses System auto-injection when a resource is referenced in the custom mainMenu', function () {
    // ActionEventResource ships with belongsToSystemSection() === true.
    // When the host app pulls it into a custom MenuSection / MenuGroup,
    // the System section should NOT also auto-inject it (avoids the
    // duplicate-row sidebar bug).
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(MenuSection::make('Audit', [
            MenuGroup::make('Activity', [
                MenuItem::resource(ActionEventResource::class),
            ]),
        ]));
    });

    // The bundled System section may still show the Cache admin link,
    // but it must not contain a second copy of the action-events resource.
    $response = $this->getJson('/martis/api/navigation');
    $sections = collect($response->json());

    $auditSection = $sections->firstWhere('label', 'Audit');
    expect($auditSection)->not->toBeNull();
    $auditUriKeys = collect($auditSection['items'])
        ->flatMap(fn ($item) => $item['type'] === 'group' ? $item['items'] : [$item])
        ->pluck('uriKey')
        ->filter()
        ->all();
    expect($auditUriKeys)->toContain('action-events');

    $systemSection = $sections->firstWhere('label', 'System');
    if ($systemSection !== null) {
        $systemUriKeys = collect($systemSection['items'])->pluck('uriKey')->filter()->all();
        expect($systemUriKeys)->not->toContain('action-events');
    }
});

it('falls back to the dashboard icon when no MenuItem-level icon is set', function () {
    // MenuFactorySalesDashboard does not call withIcon(), so icon() returns null.
    // A subclass that sets an icon should have it propagated to the menu payload.
    $dashboard = new class extends Dashboard
    {
        public function __construct()
        {
            parent::__construct('Metrics', 'metrics');
            $this->withIcon('chart-bar');
        }

        public function cards(Request $request): array
        {
            return [];
        }
    };

    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu) use ($dashboard): Menu {
        return $menu->prepend(MenuSection::make('Reports', [
            MenuItem::dashboard($dashboard),
        ]));
    });

    $response = $this->getJson('/martis/api/navigation');

    $response->assertJsonPath('0.items.0.icon', 'chart-bar');
});

it('lets a MenuItem-level icon override the dashboard icon', function () {
    $dashboard = new class extends Dashboard
    {
        public function __construct()
        {
            parent::__construct('Metrics', 'metrics');
            $this->withIcon('chart-bar');
        }

        public function cards(Request $request): array
        {
            return [];
        }
    };

    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu) use ($dashboard): Menu {
        return $menu->prepend(MenuSection::make('Reports', [
            MenuItem::dashboard($dashboard)->icon('rocket-launch'),
        ]));
    });

    $response = $this->getJson('/martis/api/navigation');

    $response->assertJsonPath('0.items.0.icon', 'rocket-launch');
});

it('drops a MenuGroup whose canSee returns false', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(MenuSection::make('Settings', [
            MenuGroup::make('Hidden', [MenuItem::link('Secret', '/secret')])
                ->canSee(false),
            MenuItem::link('Visible', '/visible'),
        ]));
    });

    $response = $this->getJson('/martis/api/navigation');

    $response->assertJsonPath('0.items.0.type', 'link');
    $response->assertJsonPath('0.items.0.label', 'Visible');
});
