<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * `routeRegistry` (resources/js/lib/routeRegistry.ts) refuses a registered
 * page whose first segment is in RESERVED_ROUTE_SEGMENTS. A server route
 * under the Martis path answers a reload before the SPA catch-all does, so
 * a registered page on one of those segments would work client-side and be
 * replaced by the server's answer on reload. These tests tie the TypeScript
 * list to the routes the package really registers.
 */

/** @return list<string> */
function reservedRouteSegments(): array
{
    $source = (string) file_get_contents(__DIR__.'/../../resources/js/lib/routeRegistry.ts');
    preg_match('/RESERVED_ROUTE_SEGMENTS[^=]*=\s*Object\.freeze\(\[(.*?)\]\)/s', $source, $list);
    preg_match_all("/'([^']+)'/", $list[1] ?? '', $names);

    return $names[1];
}

it('reads the reserved list from routeRegistry.ts', function () {
    // Guards the parser: an empty or partial read would make the next test pass vacuously.
    expect(reservedRouteSegments())->toContain('tools', 'resources', 'login', 'api', 'favicon.ico');
});

it('reserves the first segment of every route Martis registers under its path', function () {
    $prefix = trim((string) config('martis.path', 'martis'), '/').'/';

    $firstSegments = collect(Route::getRoutes()->getRoutes())
        ->map(fn (RoutingRoute $route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, $prefix))
        ->map(fn (string $uri): string => explode('/', substr($uri, strlen($prefix)))[0])
        // The SPA catch-all itself, `{path}`, is what registered pages extend.
        ->reject(fn (string $segment): bool => $segment === '{path}')
        ->unique()
        ->values();

    // The collection must see the package's routes, or the check proves nothing.
    expect($firstSegments->all())->toContain('login', 'api', 'register');

    $missing = $firstSegments->map(fn (string $segment): string => strtolower($segment))
        ->reject(fn (string $segment): bool => in_array($segment, reservedRouteSegments(), true))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

it('reserves the default path of the API documentation, registered under the Martis path when enabled', function () {
    // registerApiDocs() adds `{martis.path}/{api_docs.path}` and its `.json`
    // only when MARTIS_API_DOCS_ENABLED is on, so the route listing above
    // does not see them in this suite.
    expect(reservedRouteSegments())->toContain(trim((string) config('martis.api_docs.path'), '/'));
});
