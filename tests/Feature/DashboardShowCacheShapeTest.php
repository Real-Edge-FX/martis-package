<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Dashboards\Dashboard;
use Martis\Facades\Martis;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;

// The cached `show:` payload of a dashboard must follow the cards and filters
// the dashboard declares now, not the ones it declared when the entry was
// written.

class DSCAlphaMetric extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->result(1);
    }
}

class DSCBetaMetric extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->result(2);
    }
}

class DSCUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class DSCDashboard extends Dashboard
{
    /** @var list<string> */
    public static array $cardClasses = [];

    /** @var array<int, list<string>> */
    public static array $perUser = [];

    public function __construct()
    {
        parent::__construct(name: 'Shape', uriKey: 'shape');
    }

    public function cards(Request $request): array
    {
        $classes = self::$perUser[(int) $request->user()?->getAuthIdentifier()] ?? self::$cardClasses;

        return array_map(fn (string $c) => $c::make($c), $classes);
    }
}

function dscCards($test, string $path = '/martis/api/dashboards/shape'): array
{
    return collect($test->getJson($path)->assertOk()->json('data.cards'))->pluck('name')->all();
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
    config()->set('martis.cache.dashboards', ['enabled' => true, 'ttl' => 5]);
    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array')));

    DSCDashboard::$cardClasses = [DSCAlphaMetric::class];
    DSCDashboard::$perUser = [];
    Martis::dashboards([new DSCDashboard]);

    $this->userA = DSCUser::query()->create(['name' => 'A', 'email' => 'a@martis.test', 'password' => bcrypt('x')]);
    $this->userB = DSCUser::query()->create(['name' => 'B', 'email' => 'b@martis.test', 'password' => bcrypt('x')]);
    $this->actingAs($this->userA, 'web');
});

afterEach(function () {
    Martis::dashboards([]);
    DSCDashboard::$perUser = [];
});

it('serves the cached payload while the shape is unchanged', function () {
    expect(dscCards($this))->toBe([DSCAlphaMetric::class]);
    expect(dscCards($this))->toBe([DSCAlphaMetric::class]);
});

it('shows a card added after the payload was cached', function () {
    dscCards($this);
    DSCDashboard::$cardClasses = [DSCAlphaMetric::class, DSCBetaMetric::class];

    expect(dscCards($this))->toBe([DSCAlphaMetric::class, DSCBetaMetric::class]);
});

it('drops a card removed after the payload was cached', function () {
    DSCDashboard::$cardClasses = [DSCAlphaMetric::class, DSCBetaMetric::class];
    dscCards($this);
    DSCDashboard::$cardClasses = [DSCBetaMetric::class];

    expect(dscCards($this))->toBe([DSCBetaMetric::class]);
});

it('follows a reorder of the cards', function () {
    DSCDashboard::$cardClasses = [DSCAlphaMetric::class, DSCBetaMetric::class];
    dscCards($this);
    DSCDashboard::$cardClasses = [DSCBetaMetric::class, DSCAlphaMetric::class];

    expect(dscCards($this))->toBe([DSCBetaMetric::class, DSCAlphaMetric::class]);
});

it('does not share a payload between users with different cards', function () {
    DSCDashboard::$perUser = [
        $this->userA->id => [DSCAlphaMetric::class],
        $this->userB->id => [DSCBetaMetric::class],
    ];

    expect(dscCards($this))->toBe([DSCAlphaMetric::class]);

    $this->actingAs($this->userB, 'web');
    expect(dscCards($this))->toBe([DSCBetaMetric::class]);
});
