<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisManager;
use Martis\Menu\Menu;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;
use Martis\Resource;
use Martis\ResourceRegistry;

class NavigationTestModel extends Model
{
    protected $table = 'martis_test_navigation_items';

    protected $fillable = ['name'];
}

class NavigationAccountsResource extends Resource
{
    public static function model(): string
    {
        return NavigationTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'navigation-accounts';
    }

    public static function label(): string
    {
        return 'Accounts';
    }

    public static function singularLabel(): string
    {
        return 'Account';
    }

    public function group(): ?string
    {
        return 'Admin';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
        ];
    }
}

class NavigationHiddenResource extends NavigationAccountsResource
{
    public static function uriKey(): string
    {
        return 'navigation-hidden';
    }

    public static function label(): string
    {
        return 'Hidden';
    }

    public static function singularLabel(): string
    {
        return 'Hidden';
    }

    public static function displayInNavigation(): bool
    {
        return false;
    }
}

class NavigationDeniedResource extends NavigationAccountsResource
{
    public static function uriKey(): string
    {
        return 'navigation-denied';
    }

    public static function label(): string
    {
        return 'Denied';
    }

    public static function singularLabel(): string
    {
        return 'Denied';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class NavigationSupportResource extends Resource
{
    public static function model(): string
    {
        return NavigationTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'navigation-support';
    }

    public static function label(): string
    {
        return 'Tickets';
    }

    public static function singularLabel(): string
    {
        return 'Ticket';
    }

    public static function subtitle(): ?string
    {
        return 'Customer support queue';
    }

    public function group(): ?string
    {
        return 'Support';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
        ];
    }

    public function menuItem(Request $request): MenuItem
    {
        return MenuItem::resource(static::class)
            ->label('Help Desk')
            ->icon('lifebuoy')
            ->path('/support/help-desk');
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_navigation_items');
    Schema::create('martis_test_navigation_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    app(MartisManager::class)->forgetMainMenu();

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(NavigationAccountsResource::class);
    $registry->register(NavigationHiddenResource::class);
    $registry->register(NavigationDeniedResource::class);
    $registry->register(NavigationSupportResource::class);
});

afterEach(function () {
    app(MartisManager::class)->forgetMainMenu();
    Schema::dropIfExists('martis_test_navigation_items');
});

it('returns grouped navigation sections with declarative items only', function () {
    $response = $this->getJson('/martis/api/navigation');
    $sections = collect($response->json());
    $admin = $sections->firstWhere('label', 'Admin');
    $support = $sections->firstWhere('label', 'Support');

    $response->assertStatus(200);
    $response->assertJsonCount(2);
    expect($admin)->not->toBeNull();
    expect($support)->not->toBeNull();
    expect($admin['items'][0])->toMatchArray([
        'type' => 'resource',
        'label' => 'Accounts',
        'url' => '/resources/navigation-accounts',
    ]);
    expect($support['items'][0])->toMatchArray([
        'label' => 'Help Desk',
        'icon' => 'lifebuoy',
        'url' => '/support/help-desk',
    ]);
    expect($admin)->not->toHaveKey('resources');
    expect($support)->not->toHaveKey('resources');
});

it('publishes a resource count badge by default using indexQuery scoping', function () {
    NavigationTestModel::create(['name' => 'one']);
    NavigationTestModel::create(['name' => 'two']);
    NavigationTestModel::create(['name' => 'three']);

    $response = $this->getJson('/martis/api/navigation');
    $sections = collect($response->json());
    $admin = $sections->firstWhere('label', 'Admin');

    expect($admin['items'][0])->toMatchArray([
        'uriKey' => 'navigation-accounts',
        'count' => 3,
    ]);
});

it('omits the count when showMenuCount is false on the resource', function () {
    NavigationTestModel::create(['name' => 'one']);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register((new class extends NavigationAccountsResource
    {
        public static function showMenuCount(): bool
        {
            return false;
        }
    })::class);

    $response = $this->getJson('/martis/api/navigation');
    $sections = collect($response->json());
    $admin = $sections->firstWhere('label', 'Admin');

    expect($admin['items'][0]['count'])->toBeNull();
});

it('omits all counts when the global config switch is disabled', function () {
    config()->set('martis.navigation.counts.enabled', false);
    NavigationTestModel::create(['name' => 'one']);

    $response = $this->getJson('/martis/api/navigation');
    $sections = collect($response->json());
    $admin = $sections->firstWhere('label', 'Admin');

    expect($admin['items'][0]['count'])->toBeNull();
});

it('exposes a section attribute on MenuSection for custom main menus', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(
            MenuSection::make('Queues', [MenuItem::link('All jobs', '/queues')])->section('Platform')
        );
    });

    $response = $this->getJson('/martis/api/navigation');
    $first = $response->json('0');

    expect($first['label'])->toBe('Queues');
    expect($first['section'])->toBe('Platform');
});

it('allows a custom menu builder to prepend declarative links while preserving automatic resource sections', function () {
    app(MartisManager::class)->mainMenu(function (Request $request, Menu $menu): Menu {
        return $menu->prepend(
            MenuSection::make('Quick Links', [
                MenuItem::link('Overview', '/overview')->icon('house'),
                MenuItem::externalLink('Documentation', 'https://example.com/docs')->icon('book'),
            ])->collapsable(false)
        );
    });

    $response = $this->getJson('/martis/api/navigation');
    $sections = collect($response->json());
    $admin = $sections->firstWhere('label', 'Admin');
    $support = $sections->firstWhere('label', 'Support');

    $response->assertStatus(200);
    $response->assertJsonPath('0.label', 'Quick Links');
    $response->assertJsonPath('0.collapsable', false);
    $response->assertJsonPath('0.items.0.type', 'link');
    $response->assertJsonPath('0.items.0.label', 'Overview');
    $response->assertJsonPath('0.items.0.url', '/overview');
    $response->assertJsonPath('0.items.1.external', true);
    expect($sections[0])->not->toHaveKey('resources');
    expect($admin)->not->toBeNull();
    expect($support)->not->toBeNull();
});
