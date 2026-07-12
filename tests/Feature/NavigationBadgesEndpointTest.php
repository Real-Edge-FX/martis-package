<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisManager;
use Martis\Resource;
use Martis\ResourceRegistry;

class BadgesTestModel extends Model
{
    protected $table = 'martis_test_badges_items';

    protected $fillable = ['name'];

    public $timestamps = false;
}

class BadgesAccountsResource extends Resource
{
    public static function model(): string
    {
        return BadgesTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'badges-accounts';
    }

    public static function label(): string
    {
        return 'Accounts';
    }

    public static function singularLabel(): string
    {
        return 'Account';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class BadgesHiddenResource extends BadgesAccountsResource
{
    public static function uriKey(): string
    {
        return 'badges-hidden';
    }

    public static function displayInNavigation(): bool
    {
        return false;
    }
}

class BadgesDeniedResource extends BadgesAccountsResource
{
    public static function uriKey(): string
    {
        return 'badges-denied';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class BadgesNoCountResource extends BadgesAccountsResource
{
    public static function uriKey(): string
    {
        return 'badges-no-count';
    }

    public static function showMenuCount(): bool
    {
        return false;
    }
}

class BadgesBrokenCountResource extends BadgesAccountsResource
{
    public static function uriKey(): string
    {
        return 'badges-broken';
    }

    public static function menuCount(Request $request): ?int
    {
        throw new RuntimeException('boom');
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_badges_items');
    Schema::create('martis_test_badges_items', function ($table) {
        $table->id();
        $table->string('name');
    });

    BadgesTestModel::create(['name' => 'Alpha']);
    BadgesTestModel::create(['name' => 'Bravo']);
    BadgesTestModel::create(['name' => 'Charlie']);

    app(MartisManager::class)->forgetMainMenu();

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(BadgesAccountsResource::class);
    $registry->register(BadgesHiddenResource::class);
    $registry->register(BadgesDeniedResource::class);
    $registry->register(BadgesNoCountResource::class);
    $registry->register(BadgesBrokenCountResource::class);

    config()->set('martis.navigation.counts.enabled', true);
});

afterEach(function () {
    app(MartisManager::class)->forgetMainMenu();
    Schema::dropIfExists('martis_test_badges_items');
});

it('returns a flat uriKey => count map for resources with menu counts', function () {
    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertStatus(200);
    expect($response->json())->toBe(['resource:badges-accounts' => 3]);
});

it('hides resources with displayInNavigation() === false', function () {
    $response = $this->getJson('/martis/api/navigation/badges');

    expect($response->json())->not->toHaveKey('resource:badges-hidden');
});

it('hides resources the user cannot viewAny', function () {
    $response = $this->getJson('/martis/api/navigation/badges');

    expect($response->json())->not->toHaveKey('resource:badges-denied');
});

it('skips resources whose showMenuCount() returns false', function () {
    $response = $this->getJson('/martis/api/navigation/badges');

    expect($response->json())->not->toHaveKey('resource:badges-no-count');
});

it('swallows exceptions thrown by menuCount() and skips that resource', function () {
    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertStatus(200);
    expect($response->json())->not->toHaveKey('resource:badges-broken');
    // Sanity: other resources are still present so one broken counter
    // doesn't take the whole endpoint down.
    expect($response->json())->toHaveKey('resource:badges-accounts');
});

it('returns an empty map when navigation counts are globally disabled', function () {
    config()->set('martis.navigation.counts.enabled', false);

    $response = $this->getJson('/martis/api/navigation/badges');

    $response->assertStatus(200);
    expect($response->json())->toBe([]);
});
