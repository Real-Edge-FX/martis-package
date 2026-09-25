<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Fields\Text;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * With MARTIS_GUARD set to a guard other than the app's default,
 * MartisAuthenticate checked that guard but left the default one in place,
 * so `$request->user()` (and the policies) resolved the default guard's user:
 * null. The per-user metric cache key then named every user "guest" and served
 * the first user's value to the next. The middleware now makes the Martis guard
 * the request's guard. The users sign in through a real session here:
 * actingAs() calls shouldUse() itself and would hide the bug.
 */

class GuardSessionAdmin extends Authenticatable
{
    protected $table = 'martis_test_guard_admins';

    protected $guarded = [];
}

class GuardSessionPost extends Model
{
    protected $table = 'martis_test_guard_posts';

    protected $guarded = [];
}

class GuardSessionUserMetric extends ValueMetric
{
    public static int $calls = 0;

    public function calculate(Request $request): ValueResult
    {
        self::$calls++;

        return $this->result((int) $request->user()?->getAuthIdentifier());
    }
}

class GuardSessionPostResource extends Resource
{
    public static function model(): string
    {
        return GuardSessionPost::class;
    }

    public static function uriKey(): string
    {
        return 'guard-session-posts';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function cards(Request $request): array
    {
        return [GuardSessionUserMetric::make('My id', 'my-id')];
    }
}

beforeEach(function () {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'guard_admins']);
    config()->set('auth.providers.guard_admins', ['driver' => 'eloquent', 'model' => GuardSessionAdmin::class]);
    config()->set('martis.guard', 'admin');

    config()->set('cache.default', 'array');
    Cache::store('array')->flush();
    config()->set('martis.cache.enabled', true);
    config()->set('martis.cache.metrics', ['enabled' => true, 'ttl' => 5]);
    $this->app->forgetInstance(MartisCache::class);
    $this->app->singleton(MartisCache::class, fn () => new MartisCache(Cache::store('array')));
    GuardSessionUserMetric::$calls = 0;

    foreach (['martis_test_guard_admins', 'martis_test_guard_posts'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('martis_test_guard_admins', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::create('martis_test_guard_posts', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(GuardSessionPostResource::class);
});

afterEach(function () {
    app(ResourceRegistry::class)->flush();
    foreach (['martis_test_guard_admins', 'martis_test_guard_posts'] as $table) {
        Schema::dropIfExists($table);
    }
});

function guardSessionAdmin(string $name): GuardSessionAdmin
{
    return GuardSessionAdmin::create(['name' => $name, 'email' => "{$name}@example.com", 'password' => bcrypt('secret')]);
}

it('resolves the Martis guard user in the request of a non-default guard', function () {
    expect(config('auth.defaults.guard'))->not->toBe('admin');

    $first = guardSessionAdmin('first');
    $second = guardSessionAdmin('second');
    $session = fn (GuardSessionAdmin $admin): array => [auth()->guard('admin')->getName() => $admin->getKey()];

    $this->withSession($session($first))
        ->getJson('/martis/api/resources/guard-session-posts/cards/my-id')
        ->assertOk()
        ->assertJsonPath('data.result.value', $first->getKey());

    $this->flushSession();
    auth()->forgetGuards();

    $this->withSession($session($second))
        ->getJson('/martis/api/resources/guard-session-posts/cards/my-id')
        ->assertOk()
        ->assertJsonPath('data.result.value', $second->getKey());

    expect(GuardSessionUserMetric::$calls)->toBe(2);
});
