<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Schema;
use Martis\Http\Middleware\ApplyUserPreferencesLocale;
use Martis\Http\Middleware\EnforceImpersonationDuration;
use Martis\Http\Middleware\EnsureEmailIsVerified;
use Martis\Http\Middleware\EnsureTwoFactorChallenge;
use Martis\Http\Middleware\MartisAuthenticate;

/*
 * The protected routes' throttle keys its bucket on `$request->user()`. The
 * router's middleware priority moved ThrottleRequests ahead of
 * SubstituteBindings (from the `web` group), and so ahead of
 * MartisAuthenticate, which was not in the priority list: the throttle ran
 * before shouldUse() and, with a custom MARTIS_GUARD, counted every admin
 * behind one IP in the same bucket (or in the site user's, when the browser
 * also held a site session). MartisAuthenticate now implements
 * AuthenticatesRequests, as Laravel's and Nova's Authenticate do, so it runs
 * first. The users sign in through a real session: actingAs() calls
 * shouldUse() itself and would hide the order.
 */

class ThrottleGuardAdmin extends Authenticatable
{
    protected $table = 'martis_test_throttle_admins';

    protected $guarded = [];
}

class ThrottleGuardSiteUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'throttle_admins']);
    config()->set('auth.providers.throttle_admins', ['driver' => 'eloquent', 'model' => ThrottleGuardAdmin::class]);
    config()->set('auth.providers.users.model', ThrottleGuardSiteUser::class);

    foreach (['martis_test_throttle_admins', 'users'] as $table) {
        Schema::dropIfExists($table);
        Schema::create($table, function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
});

afterEach(function () {
    Schema::dropIfExists('martis_test_throttle_admins');
    Schema::dropIfExists('users');
});

/** The route's middleware as the router runs them, sorted by priority. */
function throttleSortedMiddleware(string $routeName): array
{
    app(HttpKernel::class); // syncs the groups, aliases and priority to the router
    $router = app('router');
    $route = $router->getRoutes()->getByName($routeName);

    return array_map(
        static fn ($middleware): string => is_string($middleware) ? explode(':', $middleware)[0] : 'closure',
        $router->gatherRouteMiddleware($route),
    );
}

/** The remaining attempts the throttle reports for one request of this session. */
function throttleRemainingFor(array $session): int
{
    $response = test()->withSession($session)->getJson('/martis/api/_meta/guards')->assertOk();

    // Start the next request as a new process would: shouldUse() wrote the
    // Martis guard into auth.defaults.guard, which the next request's
    // throttle would otherwise read before MartisAuthenticate runs.
    test()->flushSession();
    auth()->forgetGuards();
    config()->set('auth.defaults.guard', 'web');

    return (int) $response->headers->get('X-RateLimit-Remaining');
}

it('runs MartisAuthenticate before the throttle and keeps the order of the Martis middleware', function () {
    $order = throttleSortedMiddleware('martis.api.meta.guards');

    expect(array_search(MartisAuthenticate::class, $order, true))
        ->toBeLessThan(array_search(ThrottleRequests::class, $order, true))
        ->toBeLessThan(array_search(SubstituteBindings::class, $order, true));

    $martis = array_values(array_filter($order, static fn (string $name): bool => str_starts_with($name, 'Martis\\')));
    expect($martis)->toBe([
        MartisAuthenticate::class,
        EnforceImpersonationDuration::class,
        EnsureTwoFactorChallenge::class,
        ApplyUserPreferencesLocale::class,
        EnsureEmailIsVerified::class,
    ]);

    // The public auth routes do not authenticate: their throttles keep
    // counting per IP (and per email for martis-login).
    foreach (['martis.api.auth.login', 'martis.api.auth.magic-link.request', 'martis.login.attempt'] as $public) {
        expect(throttleSortedMiddleware($public))->not->toContain(MartisAuthenticate::class)
            ->toContain(ThrottleRequests::class);
    }
});

it('gives two admins of a custom guard behind one IP their own throttle bucket', function () {
    config()->set('martis.guard', 'admin');
    expect(config('auth.defaults.guard'))->toBe('web');

    $first = ThrottleGuardAdmin::create(['name' => 'first', 'email' => 'first@example.com', 'password' => bcrypt('secret')]);
    $second = ThrottleGuardAdmin::create(['name' => 'second', 'email' => 'second@example.com', 'password' => bcrypt('secret')]);
    $guardKey = auth()->guard('admin')->getName();
    $limit = (int) config('martis.throttle.max_attempts', 120);

    // Counted by IP, the second admin's first request would read limit - 2.
    expect(throttleRemainingFor([$guardKey => $first->getKey()]))->toBe($limit - 1)
        ->and(throttleRemainingFor([$guardKey => $second->getKey()]))->toBe($limit - 1)
        ->and(throttleRemainingFor([$guardKey => $first->getKey()]))->toBe($limit - 2);
});

it('does not count an admin in the bucket of the site user signed in the same browser', function () {
    config()->set('martis.guard', 'admin');

    // The site user and the admin have different ids, so a bucket keyed on
    // the site user (the default guard's user) is told apart from the
    // admin's.
    ThrottleGuardSiteUser::create(['name' => 'filler', 'email' => 'filler@example.com', 'password' => bcrypt('secret')]);
    $site = ThrottleGuardSiteUser::create(['name' => 'site', 'email' => 'site@example.com', 'password' => bcrypt('secret')]);
    $admin = ThrottleGuardAdmin::create(['name' => 'admin', 'email' => 'admin@example.com', 'password' => bcrypt('secret')]);
    $otherAdmin = ThrottleGuardAdmin::create(['name' => 'other', 'email' => 'other@example.com', 'password' => bcrypt('secret')]);
    $limit = (int) config('martis.throttle.max_attempts', 120);

    $both = fn (Authenticatable $panelUser): array => [
        auth()->guard('web')->getName() => $site->getKey(),
        auth()->guard('admin')->getName() => $panelUser->getKey(),
    ];

    expect(throttleRemainingFor($both($admin)))->toBe($limit - 1)
        ->and(throttleRemainingFor($both($otherAdmin)))->toBe($limit - 1);
});

it('keeps a bucket per user with the default guard', function () {
    config()->set('martis.guard', null);

    $first = ThrottleGuardSiteUser::create(['name' => 'first', 'email' => 'first@example.com', 'password' => bcrypt('secret')]);
    $second = ThrottleGuardSiteUser::create(['name' => 'second', 'email' => 'second@example.com', 'password' => bcrypt('secret')]);
    $guardKey = auth()->guard('web')->getName();
    $limit = (int) config('martis.throttle.max_attempts', 120);

    expect(throttleRemainingFor([$guardKey => $first->getKey()]))->toBe($limit - 1)
        ->and(throttleRemainingFor([$guardKey => $second->getKey()]))->toBe($limit - 1);
});
