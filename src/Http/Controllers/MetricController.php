<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Martis\Contracts\DashboardContract;
use Martis\Contracts\FilterContract;
use Martis\Contracts\MetricContract;
use Martis\Metrics\Metric;
use Martis\Filters\Filter;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\MartisManager;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * API controller for dashboards and metric computation.
 *
 * Routes:
 *   GET /api/dashboards                                → list dashboards
 *   GET /api/dashboards/{dashboard}                    → get dashboard definition + cards
 *   GET /api/dashboards/{dashboard}/cards/{card}       → compute a dashboard metric
 *   GET /api/resources/{resource}/cards/{card}          → compute a resource metric
 */
class MetricController
{
    // -------------------------------------------------------------------------
    // List all dashboards
    // -------------------------------------------------------------------------

    public function dashboards(Request $request): IlluminateJsonResponse
    {
        $manager = app(MartisManager::class);
        $dashboards = $manager->resolveDashboards($request);

        $data = array_map(fn (DashboardContract $d) => $d->toArray(), $dashboards);

        return JsonResponse::make(['dashboards' => array_values($data)])->toResponse();
    }

    // -------------------------------------------------------------------------
    // Get a single dashboard with its cards and filters
    // -------------------------------------------------------------------------

    public function show(Request $request, string $dashboard): IlluminateJsonResponse
    {
        $instance = $this->resolveDashboard($request, $dashboard);

        if ($instance === null) {
            return JsonErrorResponse::notFound('Dashboard not found.')->toResponse();
        }

        $cards = $this->serializeCards($instance->cards($request), $request);
        $filters = $this->serializeFilters($instance->filters($request), $request);

        return JsonResponse::make([
            'dashboard' => $instance->toArray(),
            'cards' => $cards,
            'filters' => $filters,
        ])->toResponse();
    }

    // -------------------------------------------------------------------------
    // Compute a metric from a dashboard
    // -------------------------------------------------------------------------

    public function computeDashboardMetric(Request $request, string $dashboard, string $card): IlluminateJsonResponse
    {
        $instance = $this->resolveDashboard($request, $dashboard);

        if ($instance === null) {
            return JsonErrorResponse::notFound('Dashboard not found.')->toResponse();
        }

        $metric = $this->findMetric($instance->cards($request), $card, $request);

        if ($metric === null) {
            return JsonErrorResponse::notFound('Metric not found.')->toResponse();
        }

        // Apply dashboard-level filters to the metric's query scope
        $this->applyDashboardFiltersToMetric($metric, $instance, $request);

        $result = $metric->resolve($request);

        return JsonResponse::make(['result' => $result])->toResponse();
    }

    // -------------------------------------------------------------------------
    // Compute a metric from a resource
    // -------------------------------------------------------------------------

    public function computeResourceMetric(Request $request, string $resource, string $card): IlluminateJsonResponse
    {
        $registry = app(ResourceRegistry::class);
        $resourceClass = $registry->resolve($resource);

        if ($resourceClass === null) {
            return JsonErrorResponse::notFound('Resource not found.')->toResponse();
        }

        /** @var Resource $instance */
        $instance = new $resourceClass;

        if (! $instance->authorizedToViewAny($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $metric = $this->findMetric($instance->cards($request), $card, $request);

        if ($metric === null) {
            return JsonErrorResponse::notFound('Metric not found.')->toResponse();
        }

        $result = $metric->resolve($request);

        return JsonResponse::make(['result' => $result])->toResponse();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function resolveDashboard(Request $request, string $uriKey): ?DashboardContract
    {
        $manager = app(MartisManager::class);
        $dashboards = $manager->resolveDashboards($request);

        foreach ($dashboards as $dashboard) {
            if ($dashboard->uriKey() === $uriKey) {
                return $dashboard;
            }
        }

        return null;
    }

    /**
     * Find a metric by URI key from a cards array.
     *
     * @param  list<mixed>  $cards
     */
    protected function findMetric(array $cards, string $uriKey, Request $request): ?MetricContract
    {
        foreach ($cards as $card) {
            if ($card instanceof MetricContract && $card->uriKey() === $uriKey && $card->authorizedToSee($request)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Serialize cards for API response, filtering by authorization.
     *
     * @param  list<mixed>  $cards
     * @return list<array<string, mixed>>
     */
    protected function serializeCards(array $cards, Request $request): array
    {
        $result = [];

        foreach ($cards as $card) {
            if ($card instanceof MetricContract) {
                if (! $card->authorizedToSee($request)) {
                    continue;
                }
                $result[] = $card->toArray();
            } elseif (is_array($card)) {
                $result[] = $card;
            } elseif (is_object($card) && method_exists($card, 'toArray')) {
                $result[] = $card->toArray();
            }
        }

        return $result;
    }

    /**
     * Serialize filters for API response.
     *
     * @param  list<mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function serializeFilters(array $filters, Request $request): array
    {
        $result = [];

        foreach ($filters as $filter) {
            if ($filter instanceof Filter) {
                if (! $filter->authorizedToSee($request)) {
                    continue;
                }
                $filter->resolveForSchema($request);
                $result[] = $filter->toArray();
            } elseif (is_array($filter)) {
                $result[] = $filter;
            }
        }

        return $result;
    }

    /**
     * Apply dashboard-level filters to a metric instance.
     *
     * Parses the `filters` query param, resolves matching filter instances
     * from the dashboard, and sets a query scope on the metric.
     */
    protected function applyDashboardFiltersToMetric(MetricContract $metric, DashboardContract $dashboard, Request $request): void
    {
        if (! $metric instanceof Metric) {
            return;
        }

        $rawFilters = $request->query('filters', '');
        if (! is_string($rawFilters) || $rawFilters === '') {
            return;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($rawFilters, true);
        if (! is_array($decoded) || $decoded === []) {
            return;
        }

        $filterInstances = $dashboard->filters($request);

        $metric->withFilterScope(function (Builder $query) use ($filterInstances, $decoded, $request): Builder {
            foreach ($filterInstances as $filter) {
                if (! $filter instanceof FilterContract) {
                    continue;
                }

                $key = $filter->uriKey();
                if (! array_key_exists($key, $decoded)) {
                    continue;
                }

                $value = $decoded[$key];
                if ($value === null || $value === '') {
                    continue;
                }

                $filter->apply($request, $query, $value);
            }

            return $query;
        });
    }
}
