<?php

declare(strict_types=1);

namespace Martis\Tools;

use Illuminate\Routing\MiddlewareNameResolver;
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
    /** The default middleware of `Tool::loadRoutes()` before v2.0. */
    public const V1_MIDDLEWARE = ['web', 'martis.auth'];

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

    /**
     * Warn, once per tool and process, about a middleware list a tool passes
     * to `loadRoutes()` that leaves out a guard of the Martis API that is on:
     * the 2FA challenge (`martis.profile.two_factor.enabled`) or email
     * verification (`martis.auth.email_verification.enabled`). The v1.x
     * default `['web', 'martis.auth']`, which the docs showed and a tool may
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
                "Martis: tool [%s] (%s) loads its routes with ['web', 'martis.auth'], the v1.x default of Tool::loadRoutes(), "
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
     * Warn, once per tool and process, about a route a tool registered
     * itself (in `boot()`, not through `loadRoutes()`) under its tool path,
     * `ToolRoutes::prefix()` or the v1.x `martis/api/tools/{uriKey}`, whose
     * middleware is the v1.x `['web', 'martis.auth']` or leaves out a guard
     * of the Martis API that is on, as `warnAboutMiddleware()` warns about a
     * list passed to `loadRoutes()`. The v1.x docs showed that pattern, and
     * nothing else tells such a route runs without the 2FA challenge.
     *
     * Reads the routes' own middleware (`Route::middleware()`, the groups
     * included), so a controller's is not resolved at boot. A tool already
     * warned about for its `loadRoutes()` list is not warned about again.
     *
     * @internal Called by `MartisManager::bootTools()`.
     *
     * @param  iterable<mixed>  $tools  The registered tools; a class name that did not boot is skipped.
     */
    public static function warnAboutRegisteredRoutes(iterable $tools): void
    {
        $byPrefix = [];
        foreach ($tools as $tool) {
            if (! $tool instanceof ToolContract) {
                continue;
            }

            $byPrefix[self::prefix($tool)] = $tool;
            $byPrefix['martis/api/tools/'.$tool->uriKey()] ??= $tool;
        }

        if ($byPrefix === []) {
            return;
        }

        /** @var Router $router */
        $router = app('router');

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_contains($uri, 'api/tools/')) {
                continue;
            }

            foreach ($byPrefix as $prefix => $tool) {
                if ($uri !== $prefix && ! str_starts_with($uri, $prefix.'/')) {
                    continue;
                }

                $key = $tool::class.'|'.$tool->uriKey();
                if (isset(self::$warned[$key])) {
                    break;
                }

                $middleware = $route->middleware();
                $v1 = $middleware === self::V1_MIDDLEWARE;
                $skipped = self::skippedGuards($middleware);

                if ($v1 || $skipped !== []) {
                    self::$warned[$key] = true;

                    Log::warning(sprintf(
                        'Martis: tool [%s] (%s) registers the route [%s] with the middleware [%s], so it skips %s. '
                        .'Register it with Route::middleware(ToolRoutes::middleware($this))->prefix(ToolRoutes::prefix($this)), '
                        .'or move it to a routes file loaded by $this->loadRoutes(), which applies them. See docs/upgrading.md.',
                        $tool->uriKey(),
                        $tool::class,
                        $uri,
                        implode(', ', array_map(static fn (mixed $name): string => is_string($name) ? $name : get_debug_type($name), $middleware)),
                        self::sentence($v1 ? [...$skipped, 'the API throttle', "the tool's canSee()"] : $skipped),
                    ), [
                        'tool' => $tool::class,
                        'uriKey' => $tool->uriKey(),
                        'route' => $uri,
                        'middleware' => $middleware,
                    ]);
                }

                break;
            }
        }
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
