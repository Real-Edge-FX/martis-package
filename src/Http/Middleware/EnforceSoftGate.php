<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Martis\Gates\SoftGate;
use Martis\Lenses\Lens;
use Martis\MartisManager;
use Martis\Resource;
use Martis\ResourceRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * The soft lock (`lockedFor()`, `requirePlan()`) on the routes that serve an
 * entity's data, `martis.gate`.
 *
 * The page endpoints of a dashboard and a tool answer a locked user `200`
 * with `{ locked: true, lock }` (the SPA renders the lock modal as a page).
 * Every other route that names a locked entity answers `403` with the same
 * `lock` payload before the controller runs, so nothing the lock withholds
 * from the page is read or written through its data URL:
 *
 *  - `{resource}`: every route of a locked Resource (its list, record,
 *    writes, relationships, actions, fields, pickers, cards, lenses and the
 *    rest);
 *  - `{resource}/lenses/{lens}`: a locked Lens (page, actions and pickers);
 *  - `{resource}/cards/{card}` and `dashboards/{dashboard}/cards/{card}`: a
 *    locked card;
 *  - `dashboards/{dashboard}/...`: a locked Dashboard;
 *  - `tools/{uriKey}/...`: a locked Tool (its field schema and option search;
 *    the tool's own routes answer through `martis.tool`, see
 *    {@see AuthorizeTool}).
 *
 * `canSee()` and the policies win over the lock: a user who may not see the
 * entity (a Resource's `viewAny`, a Dashboard's, Tool's, Lens's or card's
 * `authorizedToSee()`) is passed on, and the controller answers as it always
 * did, so the lock is only told to a user who may see the entity.
 * A route that names an entity the application does not know passes too: the
 * controller answers 404.
 *
 * It sits on the routes of `routes/martis.php` that name an entity, not in
 * `RouteMiddleware::api()`: the page endpoints (`GET /dashboards/{dashboard}`,
 * `GET /tools/{uriKey}`) answer the lock themselves.
 */
class EnforceSoftGate
{
    public function __construct(
        private readonly MartisManager $martis,
        private readonly ResourceRegistry $registry,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $lock = $this->lockFor($request);

        return $lock === null ? $next($request) : SoftGate::refusal($lock);
    }

    /**
     * The lock the route's entities hold for the request, outermost first.
     *
     * @return array<string, mixed>|null
     */
    private function lockFor(Request $request): ?array
    {
        $dashboard = $this->parameter($request, 'dashboard');
        if ($dashboard !== null) {
            return $this->dashboardLock($request, $dashboard);
        }

        $tool = $this->parameter($request, 'uriKey');
        if ($tool !== null) {
            return SoftGate::lockOf($this->martis->findTool($request, $tool), $request);
        }

        $resource = $this->parameter($request, 'resource');
        if ($resource !== null) {
            return $this->resourceLock($request, $resource);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dashboardLock(Request $request, string $uriKey): ?array
    {
        // resolveDashboards() leaves out a dashboard the user may not see.
        foreach ($this->martis->resolveDashboards($request) as $dashboard) {
            if ($dashboard->uriKey() !== $uriKey) {
                continue;
            }

            return SoftGate::lockOf($dashboard, $request)
                ?? $this->cardLock($request, $dashboard->cards($request));
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resourceLock(Request $request, string $uriKey): ?array
    {
        // `_` is the context-free picker (the endpoint checks the related
        // resource it names); an unknown key is the controller's 404.
        if (! $this->registry->has($uriKey)) {
            return null;
        }

        $resourceClass = $this->registry->get($uriKey);
        /** @var resource $resource */
        $resource = new $resourceClass;

        $lock = SoftGate::lockOf($resource, $request)
            ?? $this->lensLock($request, $resource)
            ?? $this->cardLock($request, $resource->cards($request));

        // `viewAny` is the resource's visibility: a user who fails it gets
        // the controller's own 403, never the lock.
        return $lock !== null && $resource->authorizedToViewAny($request) ? $lock : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lensLock(Request $request, Resource $resource): ?array
    {
        $uriKey = $this->parameter($request, 'lens');
        if ($uriKey === null) {
            return null;
        }

        foreach ($resource->lenses($request) as $lens) {
            if ($lens instanceof Lens && $lens->uriKey() === $uriKey) {
                return $lens->authorizedToSee($request) ? SoftGate::lockOf($lens, $request) : null;
            }
        }

        return null;
    }

    /**
     * The lock of the card the route names, among `$cards` (those of a
     * Resource or a Dashboard).
     *
     * @param  iterable<mixed>  $cards
     * @return array<string, mixed>|null
     */
    private function cardLock(Request $request, iterable $cards): ?array
    {
        $uriKey = $this->parameter($request, 'card');
        if ($uriKey === null) {
            return null;
        }

        foreach ($cards as $card) {
            if (! is_object($card) || ! method_exists($card, 'uriKey') || $card->uriKey() !== $uriKey) {
                continue;
            }

            if (method_exists($card, 'authorizedToSee') && ! $card->authorizedToSee($request)) {
                return null;
            }

            return SoftGate::lockOf($card, $request);
        }

        return null;
    }

    /** A route parameter the request carries, when it is a non-empty string. */
    private function parameter(Request $request, string $name): ?string
    {
        $value = $request->route($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
