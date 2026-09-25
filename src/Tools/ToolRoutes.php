<?php

declare(strict_types=1);

namespace Martis\Tools;

use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Martis\Contracts\ToolContract;
use Martis\Http\Middleware\EnsureEmailIsVerified;
use Martis\Http\Middleware\EnsureTwoFactorChallenge;
use Martis\Http\RouteMiddleware;

/**
 * The middleware and the URL prefix of a Tool's routes, as
 * `Tool::loadRoutes()` applies them by default. A tool that registers its
 * routes itself in `boot()` uses the same two:
 *
 *     Route::middleware(ToolRoutes::middleware($this))
 *         ->prefix(ToolRoutes::prefix($this))
 *         ->group(function () { ... });
 *
 * Static helpers instead of methods on Tool: a method added to the class
 * every consumer tool extends would stop a subclass that already declares
 * one of that name from loading.
 */
final class ToolRoutes
{
    /** The default middleware of `Tool::loadRoutes()` before v1.39.3. */
    public const V1_MIDDLEWARE = ['web', 'martis.auth'];

    /**
     * Where `Tool::loadRoutes()` has always mounted a tool's routes, whatever
     * `martis.path` is: `martis/api/tools/{uriKey}` ({uriKey} replaced).
     */
    public const LEGACY_PREFIX = 'martis/api/tools/{uriKey}';

    /** @var array<string, true> The tools already warned about in this process. */
    private static array $warned = [];

    /**
     * The middleware of a tool's routes: the stack of the protected Martis
     * API routes (`RouteMiddleware::api()`), then `martis.tool:{uriKey}`,
     * which answers 404 to a user the tool is hidden from, as its page does.
     *
     * @return list<string>
     */
    public static function middleware(ToolContract $tool): array
    {
        return [...RouteMiddleware::api(), 'martis.tool:'.$tool->uriKey()];
    }

    /**
     * The URL prefix of a tool's routes, `{martis.path}/api/tools/{uriKey}`:
     * the URL the SPA's `api` client calls for `/api/tools/{uriKey}/...`,
     * since it prefixes every path with `window.MartisConfig.basePath`,
     * `/{martis.path}`.
     */
    public static function prefix(ToolContract $tool): string
    {
        $path = trim((string) config('martis.path', 'martis'), '/');

        return ($path === '' ? '' : $path.'/').'api/tools/'.$tool->uriKey();
    }

    /** The prefix `Tool::loadRoutes()` has always used, `martis/api/tools/{uriKey}`. */
    public static function legacyPrefix(ToolContract $tool): string
    {
        return str_replace('{uriKey}', $tool->uriKey(), self::LEGACY_PREFIX);
    }

    /**
     * Register a copy of the routes a tool just loaded under `$from` under
     * `$to` as well. `Tool::loadRoutes()` keeps mounting a tool's routes at
     * `martis/api/tools/{uriKey}` in 1.x, so a client that calls that URL
     * keeps working, and mirrors them under `{martis.path}/api/tools/{uriKey}`
     * when `martis.path` is another path: the SPA's `api` client prefixes
     * every call with `/{martis.path}` and never reached them there. The
     * copies run the same middleware; a copy of a named route is named
     * `martis.tools.{uriKey}.path.{name}`, so `route()` keeps returning the
     * URL it returned and `route:cache` finds no duplicate name. The routes
     * are copied rather than loaded a second time, so a routes file that
     * declares a function or a class is required once.
     *
     * @internal Called by `Tool::loadRoutes()`.
     *
     * @param  list<Route>  $routes
     */
    public static function mirror(ToolContract $tool, array $routes, string $from, string $to): void
    {
        /** @var Router $router */
        $router = app('router');
        $collection = $router->getRoutes();
        $from = trim($from, '/');
        $to = trim($to, '/');

        foreach ($routes as $route) {
            $uri = $route->uri();
            if ($uri !== $from && ! str_starts_with($uri, $from.'/')) {
                continue;
            }

            $copy = clone $route;
            $copy->setUri($to.substr($uri, strlen($from)));

            $action = $copy->getAction();
            $action['prefix'] = $to.substr((string) ($action['prefix'] ?? $from), strlen($from));
            if (isset($action['as']) && is_string($action['as']) && $action['as'] !== '') {
                $action['as'] = 'martis.tools.'.$tool->uriKey().'.path.'.$action['as'];
            }
            $copy->setAction($action);

            $collection->add($copy);
        }
    }

    /**
     * Warn, once per tool and process, about a middleware list a tool passes
     * to `loadRoutes()` that leaves out a guard of the Martis API that is on:
     * the 2FA challenge (`martis.profile.two_factor.enabled`) or email
     * verification (`martis.auth.email_verification.enabled`). The default
     * before v1.39.3, `['web', 'martis.auth']`, which the docs showed and a tool may
     * pass or forward from an override, always warns. The list still
     * applies as given: nothing tells a copied list from a deliberate one.
     *
     * @internal Called by `Tool::loadRoutes()`.
     *
     * @param  array<mixed>  $middleware
     */
    public static function warnAboutMiddleware(ToolContract $tool, array $middleware): void
    {
        $v1 = $middleware === self::V1_MIDDLEWARE;
        $skipped = self::skippedGuards($middleware);

        if (! $v1 && $skipped === []) {
            return;
        }

        $key = $tool::class.'|'.$tool->uriKey();

        if (isset(self::$warned[$key])) {
            return;
        }

        self::$warned[$key] = true;

        $message = $v1
            ? sprintf(
                "Martis: tool [%s] (%s) loads its routes with ['web', 'martis.auth'], the default of Tool::loadRoutes() before v1.39.3, "
                .'so they skip %s. Do not pass a middleware list: without one, loadRoutes() runs ToolRoutes::middleware(), '
                ."the middleware of the Martis API routes and the tool's gate. See docs/upgrading.md.",
                $tool->uriKey(),
                $tool::class,
                self::sentence([...$skipped, 'the API throttle', "the tool's canSee()"]),
            )
            : sprintf(
                'Martis: tool [%s] (%s) loads its routes with a middleware list that leaves out %s, %s. '
                .'Do not pass a middleware list: without one, loadRoutes() runs ToolRoutes::middleware(), the middleware '
                ."of the Martis API routes and the tool's gate; to add to it, pass [...ToolRoutes::middleware(\$this), ...]. "
                .'See docs/tools.md.',
                $tool->uriKey(),
                $tool::class,
                self::sentence($skipped),
                count($skipped) === 1
                    ? 'which is on, so a user who has not passed it reaches them'
                    : 'which are on, so a user who has not passed them reaches them',
            );

        Log::warning($message, [
            'tool' => $tool::class,
            'uriKey' => $tool->uriKey(),
            'middleware' => $middleware,
        ]);
    }

    /**
     * The guards that are on and that the list leaves out, read through the
     * router's aliases and groups, so a group holding them counts.
     *
     * @param  array<mixed>  $middleware
     * @return list<string>
     */
    private static function skippedGuards(array $middleware): array
    {
        /** @var Router $router */
        $router = app('router');
        $classes = [];

        foreach ($middleware as $name) {
            if (! is_string($name)) {
                continue;
            }

            foreach ((array) MiddlewareNameResolver::resolve($name, $router->getMiddleware(), $router->getMiddlewareGroups()) as $resolved) {
                if (is_string($resolved)) {
                    $classes[] = explode(':', $resolved, 2)[0];
                }
            }
        }

        $skipped = [];

        if (config('martis.profile.two_factor.enabled', true) && ! in_array(EnsureTwoFactorChallenge::class, $classes, true)) {
            $skipped[] = 'the 2FA challenge (martis.2fa)';
        }

        if (config('martis.auth.email_verification.enabled', false) && ! in_array(EnsureEmailIsVerified::class, $classes, true)) {
            $skipped[] = 'email verification (martis.verified)';
        }

        return $skipped;
    }

    /** @param  list<string>  $items */
    private static function sentence(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }
}
