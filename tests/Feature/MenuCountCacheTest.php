<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Martis\Cache\MartisCache;
use Martis\Exceptions\MenuCountFailedException;
use Martis\Facades\Martis;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

/*
 * v2.20.0: `menuCountCache()` caches a sidebar count on its own (shared across
 * users or per user, in seconds), `martis.cache.navigation.badge_ttl_seconds`
 * sets the badge map's TTL in seconds and `Martis::menuCountsUsing()` resolves
 * several counts in one go. Every default keeps today's behaviour.
 */

class McModel extends Model
{
    protected $table = 'mc_items';

    public $timestamps = false;
}

/** Counts what its counter was asked, per class. */
class McBaseResource extends Resource
{
    /** @var array<string, int> */
    public static array $calls = [];

    public static function model(): string
    {
        return McModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public static function menuCount(Request $request): ?int
    {
        self::$calls[static::class] = (self::$calls[static::class] ?? 0) + 1;

        return static::value();
    }

    public static function value(): ?int
    {
        return 5;
    }
}

class McPlainResource extends McBaseResource
{
    public static function uriKey(): string
    {
        return 'mc-plain';
    }
}

class McSharedResource extends McBaseResource
{
    public static function uriKey(): string
    {
        return 'mc-shared';
    }

    public static function menuCountCache(): ?array
    {
        return ['scope' => 'shared', 'ttl' => 30];
    }

    public static function value(): ?int
    {
        return 7;
    }
}

class McUserResource extends McBaseResource
{
    public static function uriKey(): string
    {
        return 'mc-user';
    }

    public static function menuCountCache(): ?array
    {
        return ['scope' => 'user', 'ttl' => 30];
    }

    public static function value(): ?int
    {
        return 9;
    }
}

/** The declaration leaves both keys out: user scope, 60 seconds. */
class McDefaultsResource extends McBaseResource
{
    public static function uriKey(): string
    {
        return 'mc-defaults';
    }

    public static function menuCountCache(): ?array
    {
        return [];
    }
}

class McNullResource extends McBaseResource
{
    public static function uriKey(): string
    {
        return 'mc-null';
    }

    public static function menuCountCache(): ?array
    {
        return ['scope' => 'shared', 'ttl' => 30];
    }

    public static function value(): ?int
    {
        return null;
    }
}

class McBadScopeResource extends McBaseResource
{
    public static function uriKey(): string
    {
        return 'mc-bad-scope';
    }

    public static function menuCountCache(): ?array
    {
        return ['scope' => 'everyone', 'ttl' => 30];
    }
}

class McBadTtlResource extends McBaseResource
{
    public static function uriKey(): string
    {
        return 'mc-bad-ttl';
    }

    public static function menuCountCache(): ?array
    {
        return ['scope' => 'shared', 'ttl' => 0];
    }
}

class McDeniedResource extends McBaseResource
{
    public static function uriKey(): string
    {
        return 'mc-denied';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class McHiddenResource extends McBaseResource
{
    public static function uriKey(): string
    {
        return 'mc-hidden';
    }

    public static function displayInNavigation(): bool
    {
        return false;
    }
}

class McNoCountResource extends McBaseResource
{
    public static function uriKey(): string
    {
        return 'mc-no-count';
    }

    public static function showMenuCount(): bool
    {
        return false;
    }
}

class McSharedTool extends Tool
{
    public static int $calls = 0;

    public function __construct()
    {
        parent::__construct('Counted tool', 'mc-tool');
    }

    public function menuCount(Request $request): ?int
    {
        self::$calls++;

        return 4;
    }

    public function menuCountCache(): ?array
    {
        return ['scope' => 'shared', 'ttl' => 30];
    }
}

function mcUser(int $id): User
{
    return (new User)->forceFill(['id' => $id]);
}

/** @return list<string> the keys the array store holds */
function mcStoredKeys(): array
{
    $store = Cache::store('array')->getStore();

    $keys = array_keys((fn () => $this->storage)->call($store));

    // The API rate limiter keeps its own entries in the same store.
    return array_values(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'martis:cache:')));
}

/** @return list<string> */
function mcCountKeys(): array
{
    return array_values(array_filter(mcStoredKeys(), fn (string $key): bool => str_contains($key, ':count:')));
}

function mcBadges(string $host = 'localhost'): array
{
    return test()->getJson("http://{$host}/martis/api/navigation/badges")->json();
}

function mcRegister(string ...$classes): void
{
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ($classes as $class) {
        $registry->register($class);
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    config()->set('cache.default', 'array');
    Cache::store('array')->flush();
    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.navigation', ['enabled' => true, 'ttl' => 5]);
    config()->set('martis.navigation.counts.enabled', true);
    config()->set('martis.dev.tools_enabled', false);

    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array'), 'v2.20.0'));
    app(MartisCache::class)->flushAllForTesting();

    McBaseResource::$calls = [];
    McSharedTool::$calls = 0;

    Martis::forgetMainMenu();
    Martis::forgetCacheScope();
    Martis::forgetMenuCounts();
    Martis::tools([]);
});

afterEach(function () {
    Martis::forgetCacheScope();
    Martis::forgetMenuCounts();
    Martis::tools([]);
    app(ResourceRegistry::class)->flush();
});

// -----------------------------------------------------------------------------
// Default path: nothing changes
// -----------------------------------------------------------------------------

it('keeps the default path as it was: one badge map entry, no entry of its own for a count', function () {
    mcRegister(McPlainResource::class);

    expect(mcBadges())->toBe(['resource:mc-plain' => 5]);
    expect(mcBadges())->toBe(['resource:mc-plain' => 5]);

    expect(McBaseResource::$calls[McPlainResource::class])->toBe(1)
        ->and(mcCountKeys())->toBe([])
        ->and(mcStoredKeys())->toBe(['martis:cache:navigation@v2.20.0:v1:badges:guest:en']);
});

it('keeps the default map TTL of the navigation layer, in minutes', function () {
    mcRegister(McPlainResource::class);

    mcBadges();
    $this->travel(4)->minutes();
    mcBadges();
    expect(McBaseResource::$calls[McPlainResource::class])->toBe(1);

    $this->travel(2)->minutes();
    mcBadges();
    expect(McBaseResource::$calls[McPlainResource::class])->toBe(2);
});

// -----------------------------------------------------------------------------
// Shared and user scope
// -----------------------------------------------------------------------------

it('serves a shared count to a second user without counting again', function () {
    mcRegister(McSharedResource::class);

    $this->actingAs(mcUser(1));
    expect(mcBadges())->toBe(['resource:mc-shared' => 7]);

    $this->actingAs(mcUser(2));
    expect(mcBadges())->toBe(['resource:mc-shared' => 7]);

    expect(McBaseResource::$calls[McSharedResource::class])->toBe(1)
        ->and(mcCountKeys())->toHaveCount(1);
});

it('keeps one count per user for the user scope', function () {
    mcRegister(McUserResource::class);

    $this->actingAs(mcUser(1));
    mcBadges();
    $this->actingAs(mcUser(2));
    mcBadges();

    expect(McBaseResource::$calls[McUserResource::class])->toBe(2)
        ->and(mcCountKeys())->toHaveCount(2);
});

it('reads the user scope entry again for the same user once the badge map has expired', function () {
    mcRegister(McUserResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', 10);

    $this->actingAs(mcUser(1));
    mcBadges();
    $this->travel(15)->seconds();
    mcBadges();

    // The map expired after 10 seconds, the count (30 seconds) did not.
    expect(McBaseResource::$calls[McUserResource::class])->toBe(1);
});

it('defaults a declaration without keys to the user scope and 60 seconds', function () {
    mcRegister(McDefaultsResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', 5);

    $this->actingAs(mcUser(1));
    mcBadges();
    $this->travel(50)->seconds();
    mcBadges();
    expect(McBaseResource::$calls[McDefaultsResource::class])->toBe(1);

    $this->travel(20)->seconds();
    mcBadges();
    expect(McBaseResource::$calls[McDefaultsResource::class])->toBe(2);

    $this->actingAs(mcUser(2));
    mcBadges();
    expect(McBaseResource::$calls[McDefaultsResource::class])->toBe(3);
});

it('leaves the locale out of the key of a count', function () {
    mcRegister(McSharedResource::class);

    $this->actingAs(mcUser(1));
    mcBadges();
    app()->setLocale('pt_PT');
    mcBadges();

    expect(McBaseResource::$calls[McSharedResource::class])->toBe(1)
        ->and(mcCountKeys())->toHaveCount(1)
        ->and(mcCountKeys()[0])->not->toContain('pt_PT');
});

it('caches a null count too, so a hidden badge is not counted on every map', function () {
    mcRegister(McNullResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', 5);

    expect(mcBadges())->toBe([]);
    $this->travel(10)->seconds();
    expect(mcBadges())->toBe([]);

    expect(McBaseResource::$calls[McNullResource::class])->toBe(1);
});

it('caches the count of a tool the same way', function () {
    Martis::tools([McSharedTool::class]);

    $this->actingAs(mcUser(1));
    expect(mcBadges())->toBe(['tool:mc-tool' => 4]);
    $this->actingAs(mcUser(2));
    expect(mcBadges())->toBe(['tool:mc-tool' => 4]);

    expect(McSharedTool::$calls)->toBe(1);
});

// -----------------------------------------------------------------------------
// Tenant scope: a shared entry is still per scope
// -----------------------------------------------------------------------------

it('puts the app cache scope into the key of a shared count, so two tenants never share one', function () {
    Martis::cacheScopeUsing(fn (Request $request): ?string => $request->getHost());
    mcRegister(McSharedResource::class);

    $this->actingAs(mcUser(1));
    mcBadges('tenant-a.example');
    $this->actingAs(mcUser(2));
    mcBadges('tenant-a.example');
    expect(McBaseResource::$calls[McSharedResource::class])->toBe(1);

    $this->actingAs(mcUser(3));
    mcBadges('tenant-b.example');
    expect(McBaseResource::$calls[McSharedResource::class])->toBe(2);

    $keys = mcCountKeys();
    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toMatch('/:s[0-9a-f]{32}$/')
        ->and($keys[1])->toMatch('/:s[0-9a-f]{32}$/')
        ->and($keys[0])->not->toBe($keys[1]);
});

// -----------------------------------------------------------------------------
// Seconds, clear and bypass
// -----------------------------------------------------------------------------

it('honours the ttl of the declaration in seconds', function () {
    mcRegister(McSharedResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', 1);

    mcBadges();
    $this->travel(29)->seconds();
    mcBadges();
    expect(McBaseResource::$calls[McSharedResource::class])->toBe(1);

    $this->travel(3)->seconds();
    mcBadges();
    expect(McBaseResource::$calls[McSharedResource::class])->toBe(2);
});

it('honours badge_ttl_seconds for the badge map', function () {
    mcRegister(McPlainResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', 20);

    mcBadges();
    $this->travel(19)->seconds();
    mcBadges();
    expect(McBaseResource::$calls[McPlainResource::class])->toBe(1);

    $this->travel(2)->seconds();
    mcBadges();
    expect(McBaseResource::$calls[McPlainResource::class])->toBe(2);
});

it('falls back to the navigation TTL when badge_ttl_seconds is null or empty', function (mixed $value) {
    mcRegister(McPlainResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', $value);

    mcBadges();
    $this->travel(30)->seconds();
    mcBadges();

    expect(McBaseResource::$calls[McPlainResource::class])->toBe(1);
})->with([null, '']);

it('accepts badge_ttl_seconds from an env var (a numeric string)', function () {
    mcRegister(McPlainResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', '10');

    mcBadges();
    $this->travel(11)->seconds();
    mcBadges();

    expect(McBaseResource::$calls[McPlainResource::class])->toBe(2);
});

it('throws, naming the key, when badge_ttl_seconds is set but unusable', function (mixed $value) {
    mcRegister(McPlainResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', $value);
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/martis/api/navigation/badges'))
        ->toThrow(InvalidArgumentException::class, 'martis.cache.navigation.badge_ttl_seconds');
})->with([0, -5, 'soon', '1.5', 2.5, true]);

it('clears the entries of a count with martis:cache:clear navigation', function () {
    mcRegister(McSharedResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', 1);

    mcBadges();
    $this->travel(5)->seconds();
    mcBadges();
    expect(McBaseResource::$calls[McSharedResource::class])->toBe(1);

    $this->artisan('martis:cache:clear', ['type' => 'navigation'])->assertSuccessful();
    app(MartisCache::class)->refreshState();

    mcBadges();
    expect(McBaseResource::$calls[McSharedResource::class])->toBe(2);
});

it('does not clear a count when another layer is cleared', function () {
    mcRegister(McSharedResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', 1);

    mcBadges();
    $this->artisan('martis:cache:clear', ['type' => 'metrics'])->assertSuccessful();
    app(MartisCache::class)->refreshState();
    $this->travel(5)->seconds();
    mcBadges();

    expect(McBaseResource::$calls[McSharedResource::class])->toBe(1);
});

it('computes every time under the per-request bypass', function () {
    mcRegister(McSharedResource::class);
    Gate::define('bypass-martis-cache', fn ($user = null) => true);

    $this->actingAs(mcUser(1));
    $this->getJson('/martis/api/navigation/badges', ['X-Martis-No-Cache' => '1']);
    $this->getJson('/martis/api/navigation/badges', ['X-Martis-No-Cache' => '1']);

    expect(McBaseResource::$calls[McSharedResource::class])->toBe(2)
        ->and(mcCountKeys())->toBe([]);
});

it('bypasses nothing for a user who fails the bypass gate', function () {
    mcRegister(McSharedResource::class);
    Gate::define('bypass-martis-cache', fn ($user = null) => false);

    $this->actingAs(mcUser(1));
    $this->getJson('/martis/api/navigation/badges', ['X-Martis-No-Cache' => '1']);
    $this->getJson('/martis/api/navigation/badges', ['X-Martis-No-Cache' => '1']);

    expect(McBaseResource::$calls[McSharedResource::class])->toBe(1);
});

it('does not cache a count when the navigation layer is disabled', function () {
    mcRegister(McSharedResource::class);
    config()->set('martis.cache.navigation.enabled', false);

    mcBadges();
    mcBadges();

    expect(McBaseResource::$calls[McSharedResource::class])->toBe(2)
        ->and(mcStoredKeys())->toBe([]);
});

it('reports an unusable declaration and skips only that badge', function (string $class) {
    mcRegister($class, McPlainResource::class);
    $reported = [];
    Martis::forgetMenuCounts();
    $this->app->make(ExceptionHandler::class)->reportable(function (MenuCountFailedException $e) use (&$reported) {
        $reported[] = $e->getPrevious()?->getMessage();

        return false;
    });

    expect(mcBadges())->toBe(['resource:mc-plain' => 5])
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('menuCountCache()');
})->with([McBadScopeResource::class, McBadTtlResource::class]);

it('applies the declaration to the count the menu embeds as well', function () {
    mcRegister(McSharedResource::class);

    $this->actingAs(mcUser(1));
    $this->getJson('/martis/api/navigation');
    $this->actingAs(mcUser(2));
    $this->getJson('/martis/api/navigation');
    mcBadges();

    expect(McBaseResource::$calls[McSharedResource::class])->toBe(1);
});

// -----------------------------------------------------------------------------
// Batch hook
// -----------------------------------------------------------------------------

it('resolves the counts the batch answers once, and the rest with their own counter', function () {
    mcRegister(McPlainResource::class, McUserResource::class);
    $seen = [];
    Martis::menuCountsUsing(function (Request $request, Collection $resources) use (&$seen): array {
        $seen[] = $resources->all();

        return [McPlainResource::class => 42];
    });

    expect(mcBadges())->toBe(['resource:mc-plain' => 42, 'resource:mc-user' => 9]);

    expect($seen)->toBe([[McPlainResource::class, McUserResource::class]])
        ->and(McBaseResource::$calls)->toBe([McUserResource::class => 1]);
});

it('lets the batch hide a badge with null', function () {
    mcRegister(McPlainResource::class);
    Martis::menuCountsUsing(fn (): array => [McPlainResource::class => null]);

    expect(mcBadges())->toBe([])
        ->and(McBaseResource::$calls)->toBe([]);
});

it('never offers an unauthorised, hidden or opted-out resource to the batch', function () {
    mcRegister(McPlainResource::class, McDeniedResource::class, McHiddenResource::class, McNoCountResource::class);
    $seen = [];
    Martis::menuCountsUsing(function (Request $request, Collection $resources) use (&$seen): array {
        $seen = $resources->all();

        return [];
    });

    mcBadges();

    expect($seen)->toBe([McPlainResource::class]);
});

it('falls back to every own counter, and reports, when the batch throws', function () {
    mcRegister(McPlainResource::class, McUserResource::class);
    Martis::menuCountsUsing(function (): array {
        throw new RuntimeException('batch down');
    });
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (MenuCountFailedException $e) use (&$reported) {
        $reported[] = [$e->badgeKey(), $e->getPrevious()?->getMessage()];

        return false;
    });

    expect(mcBadges())->toBe(['resource:mc-plain' => 5, 'resource:mc-user' => 9])
        ->and($reported)->toBe([['batch', 'batch down']]);
});

it('falls back when the batch answers something that is not an array, and lets report_failures silence it', function () {
    mcRegister(McPlainResource::class);
    config()->set('martis.navigation.counts.report_failures', false);
    Martis::menuCountsUsing(fn () => 'nope');
    $reported = 0;
    $this->app->make(ExceptionHandler::class)->reportable(function (MenuCountFailedException $e) use (&$reported) {
        $reported++;

        return false;
    });

    expect(mcBadges())->toBe(['resource:mc-plain' => 5])
        ->and($reported)->toBe(0);
});

it('shows the batch failure under _failed when dev tools are on', function () {
    mcRegister(McPlainResource::class);
    config()->set('martis.dev.tools_enabled', true);
    config()->set('martis.navigation.counts.report_failures', false);
    Martis::menuCountsUsing(function (): array {
        throw new RuntimeException('batch down');
    });

    $payload = mcBadges();

    expect($payload['resource:mc-plain'])->toBe(5)
        ->and($payload['_failed']['batch'])->toContain('batch down');
});

it('does not call the batch when every count is served from the cache', function () {
    mcRegister(McSharedResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', 1);
    $calls = 0;
    Martis::menuCountsUsing(function () use (&$calls): array {
        $calls++;

        return [McSharedResource::class => 70];
    });

    expect(mcBadges())->toBe(['resource:mc-shared' => 70]);
    $this->travel(5)->seconds();
    expect(mcBadges())->toBe(['resource:mc-shared' => 70]);

    expect($calls)->toBe(1)
        ->and(McBaseResource::$calls)->toBe([]);
});

it('caches what the batch answered for a resource that declares menuCountCache()', function () {
    mcRegister(McSharedResource::class, McPlainResource::class);
    config()->set('martis.cache.navigation.badge_ttl_seconds', 1);
    Martis::menuCountsUsing(fn (): array => [McSharedResource::class => 70, McPlainResource::class => 50]);

    $this->actingAs(mcUser(1));
    mcBadges();
    Martis::menuCountsUsing(fn (): array => [McSharedResource::class => 71, McPlainResource::class => 51]);
    $this->actingAs(mcUser(2));
    $this->travel(5)->seconds();

    // The shared entry still holds 70, the plain count follows the new batch.
    expect(mcBadges())->toBe(['resource:mc-shared' => 70, 'resource:mc-plain' => 51]);
});

it('ignores a batch answer that is not a count and counts that class itself', function () {
    mcRegister(McPlainResource::class);
    Martis::menuCountsUsing(fn (): array => [McPlainResource::class => 'many']);

    expect(mcBadges())->toBe(['resource:mc-plain' => 5]);
});

it('does not apply the batch to the counts the menu embeds', function () {
    mcRegister(McPlainResource::class);
    $calls = 0;
    Martis::menuCountsUsing(function () use (&$calls): array {
        $calls++;

        return [McPlainResource::class => 42];
    });

    $this->getJson('/martis/api/navigation');

    expect($calls)->toBe(0);
});

it('removes the batch resolver with forgetMenuCounts()', function () {
    mcRegister(McPlainResource::class);
    Martis::menuCountsUsing(fn (): array => [McPlainResource::class => 42]);
    Martis::forgetMenuCounts();

    expect(mcBadges())->toBe(['resource:mc-plain' => 5]);
});

// -----------------------------------------------------------------------------
// MartisCache::rememberFor()
// -----------------------------------------------------------------------------

it('rememberFor() keeps an entry for the seconds given and keys it like remember()', function () {
    $cache = app(MartisCache::class);
    $runs = 0;
    $compute = function () use (&$runs): int {
        return ++$runs;
    };

    expect($cache->rememberFor('metrics', 'k', $compute, 30))->toBe(1);
    $this->travel(29)->seconds();
    expect($cache->rememberFor('metrics', 'k', $compute, 30))->toBe(1);
    $this->travel(2)->seconds();
    expect($cache->rememberFor('metrics', 'k', $compute, 30))->toBe(2);

    expect(mcStoredKeys())->toBe([$cache->buildKey('metrics', 'k')]);
});

it('rememberFor() refuses a TTL below one second', function () {
    expect(fn () => app(MartisCache::class)->rememberFor('metrics', 'k', fn () => 1, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('rememberFor() computes every time when the layer is disabled, and refuses an unknown layer', function () {
    $cache = app(MartisCache::class);
    $cache->disable('metrics');
    $runs = 0;
    $compute = function () use (&$runs): int {
        return ++$runs;
    };

    $cache->rememberFor('metrics', 'k', $compute, 30);
    $cache->rememberFor('metrics', 'k', $compute, 30);
    expect($runs)->toBe(2);

    expect(fn () => $cache->rememberFor('nope', 'k', $compute, 30))->toThrow(InvalidArgumentException::class);
});
