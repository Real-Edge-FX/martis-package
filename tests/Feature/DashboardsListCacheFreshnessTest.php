<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Cache\MartisCache;
use Martis\Dashboards\Dashboard;
use Martis\Facades\Martis;
use Martis\Http\Middleware\MartisAuthenticate;

// ---------------------------------------------------------------------------
// The per-user dashboards list is an authorization snapshot (resolveDashboards
// already filters through authorizedToSee). Cached under `list:{user}:{locale}`
// with no expiry it never converged: a dashboard registered after a user's
// list was cached never reached that user, and a user whose state changed
// kept a stale sidebar entry (404 on click) or never saw a dashboard they
// became entitled to, until a manual `martis:cache:clear dashboards`.
// ---------------------------------------------------------------------------

class DLCUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class DLCGatedDashboard extends Dashboard
{
    public static bool $visible = true;

    public static int $badgeCalls = 0;

    public function __construct(string $name = 'Gated', string $uriKey = 'gated')
    {
        parent::__construct(name: $name, uriKey: $uriKey);
    }

    public function authorizedToSee(Request $request): bool
    {
        return static::$visible;
    }

    public function badge(): ?array
    {
        static::$badgeCalls++;

        return ['value' => 'live', 'type' => 'info'];
    }
}

class DLCPlainDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct(name: 'Plain', uriKey: 'plain');
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
    }

    config()->set('cache.default', 'array');
    Cache::store('array')->flush();
    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.dashboards', ['enabled' => true, 'ttl' => null]);

    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array')));

    DLCGatedDashboard::$visible = true;
    DLCGatedDashboard::$badgeCalls = 0;
    Martis::dashboards([new DLCPlainDashboard]);

    $this->user = DLCUser::query()->create([
        'name' => 'Cache Tester',
        'email' => 'dlc@martis.test',
        'password' => bcrypt('secret'),
    ]);
    $this->actingAs($this->user, 'web');
});

afterEach(function () {
    Martis::dashboards([]);
    DLCGatedDashboard::$visible = true;
});

function dlcUriKeys(TestResponse $response): array
{
    return collect($response->json('data.dashboards'))->pluck('uriKey')->all();
}

it('ships a finite default TTL for the dashboards layer', function () {
    // Read the shipped default, not the value the harness pins above.
    $shipped = require __DIR__.'/../../config/martis.php';

    expect($shipped['cache']['dashboards']['ttl'])->toBeInt()->toBeGreaterThan(0);
});

it('shows a dashboard registered after the list was cached, without a manual cache clear', function () {
    expect(dlcUriKeys($this->getJson('/martis/api/dashboards')->assertOk()))->toBe(['plain']);

    // A release registers a new dashboard; the user's list was cached forever.
    Martis::dashboards([new DLCPlainDashboard, new DLCGatedDashboard]);

    expect(dlcUriKeys($this->getJson('/martis/api/dashboards')->assertOk()))->toBe(['plain', 'gated']);
});

it('drops a dashboard the user is no longer authorized to see (no stale entry, no 404 on click)', function () {
    Martis::dashboards([new DLCPlainDashboard, new DLCGatedDashboard]);
    expect(dlcUriKeys($this->getJson('/martis/api/dashboards')->assertOk()))->toBe(['plain', 'gated']);

    DLCGatedDashboard::$visible = false;

    expect(dlcUriKeys($this->getJson('/martis/api/dashboards')->assertOk()))->toBe(['plain']);
});

it('shows a dashboard the user became entitled to', function () {
    DLCGatedDashboard::$visible = false;
    Martis::dashboards([new DLCPlainDashboard, new DLCGatedDashboard]);
    expect(dlcUriKeys($this->getJson('/martis/api/dashboards')->assertOk()))->toBe(['plain']);

    DLCGatedDashboard::$visible = true;

    expect(dlcUriKeys($this->getJson('/martis/api/dashboards')->assertOk()))->toBe(['plain', 'gated']);
});

it('still serves the cached shape while the authorized set is unchanged', function () {
    Martis::dashboards([new DLCPlainDashboard, new DLCGatedDashboard]);

    $this->getJson('/martis/api/dashboards')->assertOk();
    $this->getJson('/martis/api/dashboards')->assertOk();

    // toArray() (which calls badge()) ran once: the second request was a hit.
    expect(DLCGatedDashboard::$badgeCalls)->toBe(1);
});

it('keeps the lists of two users independent (per-user keys)', function () {
    Martis::dashboards([new DLCPlainDashboard, new DLCGatedDashboard]);
    expect(dlcUriKeys($this->getJson('/martis/api/dashboards')->assertOk()))->toBe(['plain', 'gated']);

    $other = DLCUser::query()->create(['name' => 'Other', 'email' => 'dlc2@martis.test', 'password' => bcrypt('x')]);
    DLCGatedDashboard::$visible = false;
    $this->actingAs($other, 'web');

    expect(dlcUriKeys($this->getJson('/martis/api/dashboards')->assertOk()))->toBe(['plain']);
});
