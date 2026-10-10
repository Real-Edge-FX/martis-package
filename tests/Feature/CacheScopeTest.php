<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Facades\Martis;
use Martis\Fields\Text;
use Martis\Http\Controllers\LensController;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\MartisManager;
use Martis\Menu\Menu;
use Martis\Menu\MenuItem;
use Martis\Menu\MenuSection;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * Martis::cacheScopeUsing() (v2.10.0): an app scopes every Martis cache key
 * by something the request carries beyond the user and the locale (the host
 * of a tenant-per-subdomain app, a tenant header). Without it a main menu, a
 * field, a metric or a lens that reads the host is cached as if it did not,
 * and the same user on two tenants gets whichever payload was built first.
 */

class CsModel extends Model
{
    protected $table = 'cs_items';

    protected $guarded = [];

    public $timestamps = false;
}

class CsItemResource extends Resource
{
    public static function model(): string
    {
        return CsModel::class;
    }

    public static function uriKey(): string
    {
        return 'cs-items';
    }

    public function fields(Request $request): array
    {
        // The schema depends on the host.
        return [Text::make($request->getHost() === 'tenant-a.example' ? 'alpha_field' : 'beta_field')];
    }

    public function lenses(Request $request): array
    {
        return [new CsPlainLens, (new CsCachedLens)->cacheFor(60)];
    }

    public static function menuCount(Request $request): ?int
    {
        return $request->getHost() === 'tenant-a.example' ? 11 : 22;
    }
}

/** A metric whose value is the host's, the same for every user (no cachePerUser). */
class CsHostMetric extends ValueMetric
{
    public static int $calls = 0;

    protected bool $cachePerUser = false;

    public function calculate(Request $request): ValueResult
    {
        self::$calls++;

        return $this->result($request->getHost() === 'tenant-a.example' ? 1 : 2);
    }
}

class CsHostMetricWithCacheFor extends CsHostMetric
{
    public function cacheFor(): ?DateTimeInterface
    {
        return now()->addMinutes(5);
    }
}

class CsLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

class CsPlainLens extends CsLens {}

class CsCachedLens extends CsLens {}

const CS_A = 'tenant-a.example';
const CS_B = 'tenant-b.example';

/** The keys the array store holds. */
function csStoredKeys(): array
{
    $store = Cache::store('array')->getStore();

    return array_keys((fn () => $this->storage)->call($store));
}

function csOnHost(string $host): Request
{
    return Request::create("http://{$host}/");
}

/** Bind a request on `host` as the current one, as the kernel does for a web request. */
function csBindHost(string $host): void
{
    app()->instance('request', csOnHost($host));
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('cs_items');
    Schema::create('cs_items', function ($t) {
        $t->id();
    });

    config()->set('cache.default', 'array');
    Cache::store('array')->flush();
    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);
    config()->set('martis.cache.navigation', ['enabled' => true, 'ttl' => 5]);
    config()->set('martis.cache.dashboards', ['enabled' => true, 'ttl' => null]);
    config()->set('martis.cache.schema', ['enabled' => true, 'ttl' => null]);

    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array'), 'v2.10.0'));
    app(MartisCache::class)->flushAllForTesting();

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CsItemResource::class);

    Martis::forgetMainMenu();
    Martis::forgetCacheScope();
    CsHostMetric::$calls = 0;
});

afterEach(function () {
    Martis::forgetMainMenu();
    Martis::forgetCacheScope();
    Schema::dropIfExists('cs_items');
    app(ResourceRegistry::class)->flush();
});

/** A main menu whose only extra section names the host it was built for. */
function csHostMenu(): void
{
    Martis::mainMenu(fn (Request $request, Menu $menu): Menu => $menu->prepend(
        MenuSection::make('Menu of '.$request->getHost(), [MenuItem::link('Home', '/')]),
    ));
}

function csFirstSection(string $host): ?string
{
    return test()->getJson("http://{$host}/martis/api/navigation")->json('0.label');
}

// -----------------------------------------------------------------------------
// The key
// -----------------------------------------------------------------------------

it('leaves every key as it was without a resolver', function () {
    $cache = app(MartisCache::class);

    expect($cache->scopeSegment())->toBe('')
        ->and($cache->buildKey('schema', 'posts'))->toBe('martis:cache:schema@v2.10.0:v1:posts')
        ->and($cache->buildKey('navigation', '7:en'))->toBe('martis:cache:navigation@v2.10.0:v1:7:en');
});

it('adds a fixed-length hash of the resolver\'s answer to the key', function () {
    Martis::cacheScopeUsing(fn (Request $request): ?string => $request->getHost());
    $cache = app(MartisCache::class);

    csBindHost(CS_A);
    $a = $cache->buildKey('schema', 'posts');
    csBindHost(CS_B);
    $b = $cache->buildKey('schema', 'posts');

    expect($a)->toBe('martis:cache:schema@v2.10.0:v1:posts:s'.substr(hash('sha256', CS_A), 0, 32))
        ->and($b)->not->toBe($a)
        ->and(strlen($a))->toBe(strlen($b));
});

it('answers null and the empty string with the unscoped key', function (?string $answer) {
    Martis::cacheScopeUsing(fn (Request $request): ?string => $answer);

    csBindHost(CS_A);

    expect(app(MartisCache::class)->buildKey('schema', 'posts'))->toBe('martis:cache:schema@v2.10.0:v1:posts');
})->with([null, '']);

it('takes any string, a long one included', function () {
    Martis::cacheScopeUsing(fn (Request $request): string => str_repeat('tenant "é" ', 500));

    csBindHost(CS_A);
    $key = app(MartisCache::class)->buildKey('schema', 'posts');

    expect(strlen($key))->toBeLessThanOrEqual(MartisCache::MAX_KEY_LENGTH);
});

it('keeps a long key different per scope: the scope is in the key before it is hashed', function () {
    $cache = app(MartisCache::class);
    $long = str_repeat('x', 400);
    Martis::cacheScopeUsing(fn (Request $request): string => $request->getHost());

    csBindHost(CS_A);
    $a = $cache->buildKey('schema', $long);
    csBindHost(CS_B);
    $b = $cache->buildKey('schema', $long);

    expect($a)->not->toBe($b)
        ->and(strlen($a))->toBeLessThanOrEqual(MartisCache::MAX_KEY_LENGTH)
        ->and($a)->toStartWith('martis:cache:schema:h:');
});

it('fails loudly when the resolver answers something that is not a string or null', function (mixed $answer) {
    Martis::cacheScopeUsing(fn (Request $request): mixed => $answer);

    csBindHost(CS_A);
    app(MartisCache::class)->buildKey('schema', 'posts');
})->with([42, 1.5, true, [['tenant']], new stdClass])->throws(UnexpectedValueException::class, 'must return a string or null');

it('does not swallow an exception of the resolver', function () {
    Martis::cacheScopeUsing(function (Request $request): never {
        throw new LogicException('no tenant resolved');
    });

    csBindHost(CS_A);
    app(MartisCache::class)->remember('schema', 'posts', fn () => 'computed');
})->throws(LogicException::class, 'no tenant resolved');

it('removes the resolver with null', function () {
    Martis::cacheScopeUsing(fn (Request $request): string => 'x');
    Martis::cacheScopeUsing(null);

    expect(app(MartisManager::class)->cacheScopeResolver())->toBeNull()
        ->and(app(MartisCache::class)->scopeSegment(csOnHost(CS_A)))->toBe('');
});

// -----------------------------------------------------------------------------
// The layers
// -----------------------------------------------------------------------------

it('serves the menu of the other host from the cache without a scope, the report\'s leak', function () {
    csHostMenu();

    expect(csFirstSection(CS_A))->toBe('Menu of '.CS_A)
        ->and(csFirstSection(CS_B))->toBe('Menu of '.CS_A);
});

it('serves each host its own menu with the navigation cache on', function () {
    Martis::cacheScopeUsing(fn (Request $request): string => $request->getHost());
    csHostMenu();

    expect(csFirstSection(CS_A))->toBe('Menu of '.CS_A)
        ->and(csFirstSection(CS_B))->toBe('Menu of '.CS_B)
        ->and(csFirstSection(CS_A))->toBe('Menu of '.CS_A);
});

it('caches the menu of one host between requests', function () {
    $builds = 0;
    Martis::cacheScopeUsing(fn (Request $request): string => $request->getHost());
    Martis::mainMenu(function (Request $request, Menu $menu) use (&$builds): Menu {
        $builds++;

        return $menu;
    });

    csFirstSection(CS_A);
    csFirstSection(CS_A);
    csFirstSection(CS_B);

    expect($builds)->toBe(2);
});

it('scopes the sidebar badges', function () {
    Martis::cacheScopeUsing(fn (Request $request): string => $request->getHost());

    $counts = fn (string $host) => $this->getJson("http://{$host}/martis/api/navigation/badges")->json()['resource:cs-items'] ?? null;

    expect($counts(CS_A))->toBe(11)
        ->and($counts(CS_B))->toBe(22);
});

it('serves the badges of the other host without a scope', function () {
    $counts = fn (string $host) => $this->getJson("http://{$host}/martis/api/navigation/badges")->json()['resource:cs-items'] ?? null;

    expect($counts(CS_A))->toBe(11)
        ->and($counts(CS_B))->toBe(11);
});

it('scopes the schema', function () {
    Martis::cacheScopeUsing(fn (Request $request): string => $request->getHost());
    $attributes = fn (string $host) => collect($this->getJson("http://{$host}/martis/api/resources/cs-items/schema")->json('data.fields'))->pluck('attribute')->all();

    expect($attributes(CS_A))->toBe(['alpha_field'])
        ->and($attributes(CS_B))->toBe(['beta_field']);
});

it('serves the schema of the other host without a scope', function () {
    $attributes = fn (string $host) => collect($this->getJson("http://{$host}/martis/api/resources/cs-items/schema")->json('data.fields'))->pluck('attribute')->all();

    expect($attributes(CS_A))->toBe(['alpha_field'])
        ->and($attributes(CS_B))->toBe(['alpha_field']);
});

it('keeps the results of a metric without cachePerUser apart per scope', function (string $metricClass) {
    Martis::cacheScopeUsing(fn (Request $request): string => $request->getHost());
    $metric = $metricClass::make('Host');

    $resolveOn = function (string $host) use ($metric) {
        csBindHost($host);

        return $metric->resolve(csOnHost($host))['value'];
    };
    $a = $resolveOn(CS_A);
    $b = $resolveOn(CS_B);
    $resolveOn(CS_A);

    expect([$a, $b])->toBe([1, 2])
        ->and(CsHostMetric::$calls)->toBe(2);
})->with([
    'the metrics cache layer' => [CsHostMetric::class],
    'a per-class cacheFor()' => [CsHostMetricWithCacheFor::class],
]);

it('shares the result of a metric without cachePerUser across scopes without a resolver', function (string $metricClass) {
    $metric = $metricClass::make('Host');

    csBindHost(CS_A);
    $a = $metric->resolve(csOnHost(CS_A))['value'];
    csBindHost(CS_B);

    expect($a)->toBe(1)
        ->and($metric->resolve(csOnHost(CS_B))['value'])->toBe(1);
})->with([
    'the metrics cache layer' => [CsHostMetric::class],
    'a per-class cacheFor()' => [CsHostMetricWithCacheFor::class],
]);

it('scopes the key of a lens', function () {
    $key = function (string $host): string {
        $request = new LensRequest;
        $request->headers->set('Host', $host);
        $request->setUserResolver(fn (): Authenticatable => new User);

        return (string) (new ReflectionMethod(LensController::class, 'buildCacheKey'))
            ->invoke(app(LensController::class), CsLens::make(), $request, 25, 1, '', 'v1');
    };
    $unscoped = $key(CS_A);

    expect($key(CS_B))->toBe($unscoped);

    Martis::cacheScopeUsing(fn (Request $request): string => $request->headers->get('Host', ''));

    expect($key(CS_A))->not->toBe($key(CS_B))
        ->and($key(CS_A))->toStartWith($unscoped.':s')
        ->and($key(CS_A))->toBe($key(CS_A));
});

it('does not run the resolver for a lens that is not cached', function () {
    Martis::cacheScopeUsing(function (Request $request): never {
        throw new RuntimeException('boom');
    });

    $this->getJson('http://'.CS_A.'/martis/api/resources/cs-items/lenses/cs-plain')->assertOk();
});

it('still runs the resolver for a cached lens', function () {
    Martis::cacheScopeUsing(function (Request $request): never {
        throw new RuntimeException('boom');
    });

    $this->getJson('http://'.CS_A.'/martis/api/resources/cs-items/lenses/cs-cached')->assertStatus(500);
});

it('scopes the key of a cached lens through its endpoint', function () {
    $seen = [];
    Martis::cacheScopeUsing(function (Request $request) use (&$seen): string {
        $seen[] = $request->getHost();

        return $request->getHost();
    });

    $this->getJson('http://'.CS_A.'/martis/api/resources/cs-items/lenses/cs-cached')->assertOk();
    $this->getJson('http://'.CS_B.'/martis/api/resources/cs-items/lenses/cs-cached')->assertOk();

    expect($seen)->toContain(CS_A, CS_B);
});

// -----------------------------------------------------------------------------
// clear and prune
// -----------------------------------------------------------------------------

it('clears the entries of every scope with martis:cache:clear', function () {
    $builds = 0;
    Martis::cacheScopeUsing(fn (Request $request): string => $request->getHost());
    Martis::mainMenu(function (Request $request, Menu $menu) use (&$builds): Menu {
        $builds++;

        return $menu;
    });

    csFirstSection(CS_A);
    csFirstSection(CS_B);
    csFirstSection(CS_A);
    csFirstSection(CS_B);
    expect($builds)->toBe(2);

    $this->artisan('martis:cache:clear', ['type' => 'navigation'])->assertSuccessful();

    csFirstSection(CS_A);
    csFirstSection(CS_B);
    expect($builds)->toBe(4);
});

it('treats scoped keys as live when pruning a database store', function () {
    Schema::dropIfExists('cs_cache');
    Schema::create('cs_cache', function ($table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    config()->set('cache.stores.cs_db', ['driver' => 'database', 'table' => 'cs_cache', 'connection' => null, 'lock_connection' => null]);
    config()->set('cache.prefix', 'app_');
    /** @var Repository $store */
    $store = Cache::store('cs_db');

    Martis::cacheScopeUsing(fn (Request $request): string => $request->getHost());
    $current = new MartisCache($store, 'v2.10.0');
    $current->flushAllForTesting();
    csBindHost(CS_A);
    $keyA = $current->buildKey('schema', 'posts');
    $current->remember('schema', 'posts', fn () => 'a');
    csBindHost(CS_B);
    $keyB = $current->buildKey('schema', 'posts');
    $current->remember('schema', 'posts', fn () => 'b');
    csBindHost(CS_A);
    (new MartisCache($store, 'v2.9.0'))->remember('schema', 'posts', fn () => 'old version');

    $result = $current->prune();

    expect($result)->toMatchArray(['driver' => 'database', 'supported' => true, 'deleted' => 1])
        ->and(DB::table('cs_cache')->pluck('key')->sort()->values()->all())->toBe(collect([
            'app_'.$keyA,
            'app_'.$keyB,
        ])->sort()->values()->all());

    Schema::dropIfExists('cs_cache');
});
