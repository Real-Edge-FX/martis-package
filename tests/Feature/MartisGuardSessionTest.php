<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Fields\Text;
use Martis\Impersonation\ImpersonationManager;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;
use Martis\Models\ActionEvent;
use Martis\Models\UserPreference;
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

/** What a gate decides for the request's user: 1 when it is a Martis guard user. */
class GuardSessionGateMetric extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->result(Gate::allows('guard-session-probe') ? 1 : 0);
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
        return [GuardSessionUserMetric::make('My id', 'my-id'), GuardSessionGateMetric::make('Gate', 'gate')];
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

    expect(GuardSessionUserMetric::$calls)->toBe(2)
        ->and(auth()->getDefaultDriver())->toBe('admin');
});

it('runs the gates and policies for the Martis guard user', function () {
    Gate::define('guard-session-probe', fn (GuardSessionAdmin $admin): bool => true);
    $admin = guardSessionAdmin('gate');

    $this->withSession([auth()->guard('admin')->getName() => $admin->getKey()])
        ->getJson('/martis/api/resources/guard-session-posts/cards/gate')
        ->assertOk()
        ->assertJsonPath('data.result.value', 1);
});

it('reads the notification bell as off, and says why, for a guard model without Notifiable', function () {
    Log::spy();
    $admin = guardSessionAdmin('bell');
    $session = [auth()->guard('admin')->getName() => $admin->getKey()];

    $this->withSession($session)
        ->getJson('/martis/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread', 0)
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('reason', fn (string $reason): bool => str_contains($reason, GuardSessionAdmin::class) && str_contains($reason, 'Notifiable'));

    $this->withSession($session)
        ->postJson('/martis/api/notifications/read-all')
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Notifiable'));

    Log::shouldHaveReceived('warning')->once();
});

it('points the action log, the preferences and impersonation at the Martis guard', function () {
    config()->set('martis.impersonation.guard', null);

    expect((new ActionEvent)->user()->getRelated())->toBeInstanceOf(GuardSessionAdmin::class)
        ->and((new UserPreference)->user()->getRelated())->toBeInstanceOf(GuardSessionAdmin::class)
        ->and(app(ImpersonationManager::class)->guard())->toBe('admin');
});
