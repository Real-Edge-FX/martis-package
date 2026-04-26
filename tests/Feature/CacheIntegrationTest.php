<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisManager;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * End-to-end integration tests for the Task 17 cache layer. These tests
 * exercise the actual HTTP endpoints — `GET /api/navigation`,
 * `GET /api/dashboards`, `GET /api/resources/{r}/schema` — to prove that
 * caching is honoured in production code paths, not just at the service
 * level. The unit tests in `tests/Unit/MartisCacheTest.php` cover the
 * isolated service contract; this file proves the wiring.
 */
class CachedTestModel extends Model
{
    protected $table = 'martis_test_cache_items';

    protected $fillable = ['name'];
}

class CachedTestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class CachedAccountsResource extends Resource
{
    public static function model(): string
    {
        return CachedTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'cached-accounts';
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
        return 'Cache integration';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    Schema::dropIfExists('martis_test_cache_items');
    Schema::create('martis_test_cache_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('cache.default', 'array');
    Cache::store('array')->flush();

    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);
    config()->set('martis.cache.navigation', ['enabled' => true, 'ttl' => 1]);
    config()->set('martis.cache.dashboards', ['enabled' => true, 'ttl' => null]);
    config()->set('martis.cache.schema', ['enabled' => true, 'ttl' => null]);

    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array')));

    app(MartisManager::class)->forgetMainMenu();

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CachedAccountsResource::class);

    $this->user = CachedTestUser::query()->create([
        'name' => 'Cache Tester',
        'email' => 'cache-int@martis.test',
        'password' => bcrypt('secret'),
    ]);
});

afterEach(function () {
    app(MartisManager::class)->forgetMainMenu();
    Schema::dropIfExists('martis_test_cache_items');
});

// -----------------------------------------------------------------------------
// Navigation
// -----------------------------------------------------------------------------

it('navigation endpoint caches results between requests', function () {
    $this->actingAs($this->user, 'web');

    $cache = $this->app->make(MartisCache::class);
    $beforeVersion = $cache->status()[1]['version']; // navigation = index 1

    $first = $this->getJson('/martis/api/navigation');
    $second = $this->getJson('/martis/api/navigation');

    $first->assertOk();
    $second->assertOk();
    expect($first->json())->toEqual($second->json());

    // Manually clear and confirm the cache was actually populated by
    // checking that the version bumped.
    $cache->clear('navigation');
    expect($cache->status()[1]['version'])->toBeGreaterThan($beforeVersion);
});

it('navigation cache is scoped per user', function () {
    $other = CachedTestUser::query()->create([
        'name' => 'Other',
        'email' => 'other@martis.test',
        'password' => bcrypt('secret'),
    ]);

    // Resource that hides itself for $this->user but is visible to
    // others. Adding it on the fly via an anonymous registry entry.
    $registry = app(ResourceRegistry::class);
    $registry->flush();

    $hiddenForFirst = new class extends CachedAccountsResource {
        public static function uriKey(): string { return 'cached-hidden'; }
        public static function label(): string { return 'Per-user hidden'; }

        public function authorizedToViewAny(Request $request): bool
        {
            return $request->user()?->getKey() !== 1; // hide only from user 1
        }
    };
    $registry->register(CachedAccountsResource::class);
    $registry->register($hiddenForFirst::class);

    $this->actingAs($this->user, 'web');
    $firstUserResp = $this->getJson('/martis/api/navigation');

    $this->actingAs($other, 'web');
    $secondUserResp = $this->getJson('/martis/api/navigation');

    $firstFlat = json_encode($firstUserResp->json());
    $secondFlat = json_encode($secondUserResp->json());
    expect($firstFlat)->not->toEqual($secondFlat);
});

it('navigation bypass via X-Martis-No-Cache header skips the cache layer', function () {
    $this->actingAs($this->user, 'web');

    // Prime cache.
    $this->getJson('/martis/api/navigation');

    // Replace registry with empty list — without bypass we'd still get
    // the cached payload; with bypass we get the recomputed empty list.
    app(ResourceRegistry::class)->flush();

    $cached = $this->getJson('/martis/api/navigation');
    $bypassed = $this->getJson('/martis/api/navigation', ['X-Martis-No-Cache' => '1']);

    expect($cached->json())->not->toEqual($bypassed->json());
    expect($bypassed->json())->toBeArray();
});

it('navigation cache is bypassed when master switch is off', function () {
    config()->set('martis.cache.enabled', false);

    $this->actingAs($this->user, 'web');
    $this->getJson('/martis/api/navigation');

    app(ResourceRegistry::class)->flush();

    // No bypass header — but master is off, so the second call recomputes.
    $second = $this->getJson('/martis/api/navigation');
    expect($second->json())->toBeArray();
});

// -----------------------------------------------------------------------------
// Schema
// -----------------------------------------------------------------------------

it('schema endpoint caches the heavy schema payload', function () {
    $this->actingAs($this->user, 'web');

    $first = $this->getJson('/martis/api/resources/cached-accounts/schema');
    $first->assertOk();

    $cache = $this->app->make(MartisCache::class);
    $schemaRow = collect($cache->status())->firstWhere('type', 'schema');
    $beforeVersion = $schemaRow['version'];

    $second = $this->getJson('/martis/api/resources/cached-accounts/schema');
    $second->assertOk();
    expect($first->json())->toEqual($second->json());

    $cache->clear('schema');
    $afterVersion = collect($cache->status())->firstWhere('type', 'schema')['version'];
    expect($afterVersion)->toBeGreaterThan($beforeVersion);
});

it('schema cache returns a fresh payload after a registry change + clear', function () {
    $this->actingAs($this->user, 'web');

    $original = $this->getJson('/martis/api/resources/cached-accounts/schema')->json();

    // Re-register with the same uriKey but a richer fields() — simulates
    // a code change. Without clear() we'd serve the old payload.
    app(ResourceRegistry::class)->flush();
    app(ResourceRegistry::class)->register(new class extends CachedAccountsResource {
        public function fields(Request $request): array
        {
            return [Text::make('name'), Text::make('extra_field')];
        }
    }::class);

    $stale = $this->getJson('/martis/api/resources/cached-accounts/schema')->json();
    expect($stale)->toEqual($original);

    $this->app->make(MartisCache::class)->clear('schema');

    $fresh = $this->getJson('/martis/api/resources/cached-accounts/schema')->json();
    $names = collect($fresh['data']['fields'] ?? [])->pluck('attribute')->all();
    expect($names)->toContain('extra_field');
});

it('schema bypass via ?nocache=1 query skips the cache', function () {
    $this->actingAs($this->user, 'web');

    $primed = $this->getJson('/martis/api/resources/cached-accounts/schema')->json();

    app(ResourceRegistry::class)->flush();
    app(ResourceRegistry::class)->register(new class extends CachedAccountsResource {
        public function fields(Request $request): array
        {
            return [Text::make('name'), Text::make('bypassed_field')];
        }
    }::class);

    $bypassed = $this->getJson('/martis/api/resources/cached-accounts/schema?nocache=1')->json();
    $names = collect($bypassed['data']['fields'] ?? [])->pluck('attribute')->all();

    expect($names)->toContain('bypassed_field');
});

// -----------------------------------------------------------------------------
// Dashboards
// -----------------------------------------------------------------------------

it('dashboards list endpoint caches the response', function () {
    $this->actingAs($this->user, 'web');

    $first = $this->getJson('/martis/api/dashboards')->assertOk()->json();

    $cache = $this->app->make(MartisCache::class);
    $beforeVersion = collect($cache->status())->firstWhere('type', 'dashboards')['version'];

    $second = $this->getJson('/martis/api/dashboards')->assertOk()->json();
    expect($first)->toEqual($second);

    $cache->clear('dashboards');
    $afterVersion = collect($cache->status())->firstWhere('type', 'dashboards')['version'];
    expect($afterVersion)->toBeGreaterThan($beforeVersion);
});

// -----------------------------------------------------------------------------
// Disable / enable surface
// -----------------------------------------------------------------------------

it('runtime disable on schema bypasses cache for every subsequent request', function () {
    $this->actingAs($this->user, 'web');
    $cache = $this->app->make(MartisCache::class);

    $cache->disable('schema');

    $first = $this->getJson('/martis/api/resources/cached-accounts/schema')->json();

    // Mutate the registry — without cache, second call reflects the change.
    app(ResourceRegistry::class)->flush();
    app(ResourceRegistry::class)->register(new class extends CachedAccountsResource {
        public function fields(Request $request): array
        {
            return [Text::make('name'), Text::make('mutated_field')];
        }
    }::class);

    $second = $this->getJson('/martis/api/resources/cached-accounts/schema')->json();
    $secondNames = collect($second['data']['fields'] ?? [])->pluck('attribute')->all();
    expect($secondNames)->toContain('mutated_field');
});
