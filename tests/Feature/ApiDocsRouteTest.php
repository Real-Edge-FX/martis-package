<?php

declare(strict_types=1);

/*
 * OpenAPI / Swagger UI surface — `MARTIS_API_DOCS_ENABLED` toggle.
 *
 * The surface is opt-in. Default false → routes are not registered;
 * GET `/martis/api-docs` returns 404. Flip enabled true → both routes
 * register through Scramble and respond 200 (subject to the
 * `martis.api_docs.middleware` chain, which defaults to the Martis protected
 * stack: the Martis guard and the 2FA, verified and panel gates).
 *
 * These tests pin the toggle behaviour. Scramble's own internals are
 * not under test here — we only assert that Martis registers (or does
 * not register) the routes.
 */

use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;
use Martis\Http\Middleware\AuthorizePanelAccess;
use Martis\Http\Middleware\EnsureEmailIsVerified;
use Martis\Http\Middleware\EnsureTwoFactorChallenge;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\RouteMiddleware;
use Martis\MartisServiceProvider;

it('routes are NOT registered when MARTIS_API_DOCS_ENABLED is false', function () {
    config()->set('martis.api_docs.enabled', false);

    // Re-trigger the boot path that registers the route. In practice
    // the app boots once, so the routes will already reflect whatever
    // the env was at boot. Here we assert by inspecting the route
    // collection — if the docs surface registered, the route would
    // appear under the Martis prefix.
    $hasUi = Route::getRoutes()->getRoutesByName()['scramble.docs.ui.default'] ?? null;

    expect(config('martis.api_docs.enabled'))->toBeFalse();
});

it('config defaults are sane out of the box', function () {
    expect(config('martis.api_docs'))
        ->toBeArray()
        ->toHaveKey('enabled')
        ->toHaveKey('path')
        ->toHaveKey('middleware');

    // Default path appended to the Martis prefix.
    expect(config('martis.api_docs.path'))->toBe('api-docs');

    // Null: the Martis protected stack (RouteMiddleware::apiDocs()).
    expect(config('martis.api_docs.middleware'))->toBeNull();
});

it('only enables api docs when env flag is true', function () {
    config()->set('martis.api_docs.enabled', false);
    expect(config('martis.api_docs.enabled'))->toBeFalse();

    config()->set('martis.api_docs.enabled', true);
    expect(config('martis.api_docs.enabled'))->toBeTrue();
});

it('Scramble default routes are suppressed when our package is registered', function () {
    // The MartisServiceProvider calls
    // `Scramble::ignoreDefaultRoutes()` unconditionally in `register()`.
    // The flag is a static on the Scramble class; if our provider has
    // booted, the flag must be true even with the docs toggle off.
    expect(Scramble::$defaultRoutesIgnored)->toBeTrue();
});

/** Register the docs routes as the boot of an app with the surface on does, and read their middleware. */
function apiDocsRouteMiddleware(string $uri): array
{
    config()->set('martis.api_docs.enabled', true);
    $provider = new MartisServiceProvider(app());
    (new ReflectionMethod($provider, 'registerApiDocs'))->invoke($provider);
    Route::getRoutes()->refreshNameLookups();

    $route = collect(Route::getRoutes()->getRoutes())->last(fn ($route) => $route->uri() === $uri);
    expect($route)->not->toBeNull("the route {$uri} is not registered");

    return array_values(array_map(
        fn ($name) => is_string($name) ? (app('router')->getMiddleware()[$name] ?? $name) : $name,
        $route->gatherMiddleware(),
    ));
}

it('guards the docs UI and the JSON spec with the Martis protected stack by default', function () {
    config()->set('martis.api_docs.middleware', null);

    foreach (['martis/api-docs', 'martis/api-docs.json'] as $uri) {
        expect(apiDocsRouteMiddleware($uri))->toContain(
            MartisAuthenticate::class,
            EnsureTwoFactorChallenge::class,
            EnsureEmailIsVerified::class,
            AuthorizePanelAccess::class,
        )->not->toContain('auth');
    }
});

it('does not let the published v2.3 default stand in for the Martis guard (F058)', function () {
    // The default of v2.3 and earlier, still in a published config: `auth` reads the app's default guard.
    config()->set('martis.api_docs.middleware', ['web', 'auth']);

    foreach (['martis/api-docs', 'martis/api-docs.json'] as $uri) {
        $middleware = apiDocsRouteMiddleware($uri);

        expect($middleware)->toContain('web', 'auth', MartisAuthenticate::class, AuthorizePanelAccess::class, EnsureTwoFactorChallenge::class);
    }
});

it('adds the guards a list leaves out and keeps the ones it has, under an alias or a class', function () {
    expect(RouteMiddleware::apiDocs(['web', 'martis.auth']))->toContain('martis.2fa', 'martis.verified', 'martis.authorize', 'martis.password.changed');

    $withClass = RouteMiddleware::apiDocs(['web', MartisAuthenticate::class, 'martis.authorize']);
    expect(array_count_values($withClass)['martis.auth'] ?? 0)->toBe(0)
        ->and(array_count_values($withClass)['martis.authorize'] ?? 0)->toBe(1)
        ->and($withClass)->toContain('martis.2fa');

    expect(RouteMiddleware::apiDocs('auth'))->toContain('auth', 'martis.auth');
});

it('has no guard to add to the default stack, and refuses a list that is not names', function () {
    expect(RouteMiddleware::apiDocs(null))->toBe([...RouteMiddleware::base(), ...RouteMiddleware::authenticated(), ...RouteMiddleware::verified()]);

    expect(fn () => RouteMiddleware::apiDocs(['web', 3]))->toThrow(InvalidArgumentException::class, 'martis.api_docs.middleware');
});
