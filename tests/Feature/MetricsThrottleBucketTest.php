<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\RouteMiddleware;

// The metric and dashboard read routes can count against a bucket of their
// own (`martis.throttle.metrics_max_attempts`), and then only against it.

class MTBUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

const MTB_METRIC_ROUTES = ['api.dashboards.index', 'api.dashboards.show', 'api.dashboards.cards.compute', 'api.resources.cards.compute'];

function mtbReloadRoutes(): void
{
    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/martis.php';
    $router->getRoutes()->refreshNameLookups();
}

/** @return list<string> the middleware of the route as the router runs them */
function mtbStack(string $name): array
{
    /** @var Router $router */
    $router = app('router');
    $route = $router->getRoutes()->getByName('martis.'.$name);
    expect($route)->toBeInstanceOf(Route::class);

    return array_values(array_filter(
        $router->gatherRouteMiddleware($route),
        static fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:'),
    ));
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

    config()->set('martis.throttle', array_merge(config('martis.throttle'), [
        'enabled' => true,
        'max_attempts' => 5,
        'decay_minutes' => 1,
        'metrics_max_attempts' => null,
        'metrics_decay_minutes' => null,
    ]));
});

it('keeps the metric routes in the API bucket by default', function () {
    mtbReloadRoutes();

    foreach (MTB_METRIC_ROUTES as $name) {
        expect(mtbStack($name))->toBe(['throttle:5,1,'.RouteMiddleware::throttlePrefix('api')]);
    }
    expect(RouteMiddleware::metricsThrottle())->toBe([]);
});

it('moves the metric routes to their own bucket, and only them', function () {
    config()->set('martis.throttle.metrics_max_attempts', 50);
    config()->set('martis.throttle.metrics_decay_minutes', 2);
    mtbReloadRoutes();

    $own = 'throttle:50,2,'.RouteMiddleware::throttlePrefix('metrics');
    foreach (MTB_METRIC_ROUTES as $name) {
        expect(mtbStack($name))->toBe([$own]);
    }

    // Everything else, reads and writes, keeps the API bucket alone.
    foreach (['api.search', 'api.resources.index'] as $name) {
        expect(mtbStack($name))->toBe(RouteMiddleware::throttle());
    }
});

it('takes the decay of the API bucket when the metrics decay is unset', function () {
    config()->set('martis.throttle.decay_minutes', 3);
    config()->set('martis.throttle.metrics_max_attempts', 50);

    expect(RouteMiddleware::metricsThrottle())->toBe(['throttle:50,3,'.RouteMiddleware::throttlePrefix('metrics')]);
});

it('disables the metrics bucket with the throttle', function () {
    config()->set('martis.throttle.enabled', false);
    config()->set('martis.throttle.metrics_max_attempts', 50);
    mtbReloadRoutes();

    expect(RouteMiddleware::metricsThrottle())->toBe([]);
    foreach (MTB_METRIC_ROUTES as $name) {
        expect(mtbStack($name))->toBe([]);
    }
});

it('refuses a value that is not a positive whole number, naming the key', function (string $key, mixed $value) {
    config()->set('martis.throttle.metrics_max_attempts', 10);
    config()->set($key, $value);

    expect(fn () => RouteMiddleware::metricsThrottle())->toThrow(InvalidArgumentException::class, "[{$key}]");
})->with([
    'zero max' => ['martis.throttle.metrics_max_attempts', 0],
    'negative max' => ['martis.throttle.metrics_max_attempts', -3],
    'array max' => ['martis.throttle.metrics_max_attempts', [5]],
    'text max' => ['martis.throttle.metrics_max_attempts', 'lots'],
    'zero decay' => ['martis.throttle.metrics_decay_minutes', 0],
    'text decay' => ['martis.throttle.metrics_decay_minutes', 'soon'],
]);

it('counts metric reads against their own bucket and never the API one', function () {
    config()->set('martis.throttle.max_attempts', 3);
    config()->set('martis.throttle.metrics_max_attempts', 2);
    mtbReloadRoutes();
    RateLimiter::clear('x');

    $user = MTBUser::query()->create(['name' => 'T', 'email' => 't@martis.test', 'password' => bcrypt('x')]);
    $this->actingAs($user, 'web');

    $this->getJson('/martis/api/dashboards')->assertStatus(200);
    $this->getJson('/martis/api/dashboards')->assertStatus(200);
    $this->getJson('/martis/api/dashboards')->assertStatus(429);

    // The API bucket is untouched: it still allows its three requests.
    $this->getJson('/martis/api/search?q=a')->assertStatus(200);
    $this->getJson('/martis/api/search?q=a')->assertStatus(200);
    $this->getJson('/martis/api/search?q=a')->assertStatus(200);
    $this->getJson('/martis/api/search?q=a')->assertStatus(429);
});
