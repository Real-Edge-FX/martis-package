<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\SearchResolver;

/**
 * Global Search controller.
 *
 * Single endpoint to search across all registered globallySearchable()
 * resources. Uses SearchResolver for Scout-or-LIKE strategy per resource.
 *
 * GET /api/search?q=term
 *
 * Response:
 * {"results": [{
 *   "resource": "users",
 *   "label": "Users",
 *   "items": [{"id": 1, "title": "...", "subtitle": "...", "image": null, "url": "..."}],
 *   "total": 42,                              // ← present when items count == limit
 *   "viewAllUrl": "/resources/users?search=Cl"
 * }]}
 *
 * Defaults `limit` and `min_query` come from `config('martis.search')`. A
 * resource overrides per-resource by returning an array shape from its
 * `globallySearchable()`:
 *   - `['enabled' => bool, 'limit' => int, 'min_query' => int]`
 * Any omitted key falls back to the global default.
 */
class SearchController extends MartisController
{
    /** Create the controller and inject the resource registry. */
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * Execute a global search across all searchable resources.
     *
     * Only resources where the resolved `globallySearchable()` config
     * has `enabled = true` and the authenticated user passes
     * `authorizedToViewAny()` are searched.
     *
     * @response array{results: array<int, array{
     *   resource: string,
     *   label: string,
     *   items: array<int, array<string, mixed>>,
     *   total?: int,
     *   viewAllUrl: string
     * }>}
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->string('q'));
        $globalDefaults = $this->globalDefaults();

        $results = [];

        foreach ($this->registry->list() as $resourceClass) {
            /** @var class-string<resource> $resourceClass */
            $config = $this->resolveResourceConfig($resourceClass, $globalDefaults);

            if (! $config['enabled']) {
                continue;
            }

            // Per-resource min_query gate. A resource that declares
            // `min_query=1` participates with single-character queries
            // even when the global default is 2.
            if (mb_strlen($q) < $config['min_query']) {
                continue;
            }

            $instance = new $resourceClass;

            if (! $instance->authorizedToViewAny($request)) {
                continue;
            }

            /** @var class-string<Model> $modelClass */
            $modelClass = $resourceClass::model();
            $builder = $modelClass::query();
            $builder = $resourceClass::indexQuery($request, $builder);
            $builder = $resourceClass::applyWith($builder);
            /** @var Builder<Model> $builder */
            $builder = SearchResolver::apply($request, $builder, $resourceClass, $q);
            $builder = $instance->searchOrderBy($builder, $q);

            $models = $builder->limit($config['limit'])->get();

            if ($models->isEmpty()) {
                continue;
            }

            $items = [];
            /** @var Model $model */
            foreach ($models as $model) {
                $resourceInstance = new $resourceClass($model);
                // Delegated transformer — the default emits id/title/
                // subtitle/image/url. Resources that override it can
                // surface arbitrary fields (badges, status, tags) the
                // host frontend is prepared to render.
                $items[] = $resourceInstance->globalSearchResult($model);
            }

            $group = [
                'resource' => $resourceClass::uriKey(),
                'label' => $resourceClass::label(),
                'items' => $items,
                'viewAllUrl' => '/resources/'.$resourceClass::uriKey().'?search='.rawurlencode($q),
            ];

            // ⭐ Differential 2 — surface a total count ONLY when the
            // returned set hit the limit. The extra COUNT query is
            // gated behind that condition so the common path (results
            // < limit) stays one query per resource.
            if (count($items) === $config['limit']) {
                $group['total'] = $this->countAfterSearch($request, $resourceClass, $q);
            }

            $results[] = $group;
        }

        return response()->json(['results' => $results]);
    }

    /**
     * Resolve the per-resource search config, applying global defaults
     * for any key the resource did not specify.
     *
     * @param  class-string<resource>  $resourceClass
     * @param  array{limit: int, min_query: int}  $defaults
     * @return array{enabled: bool, limit: int, min_query: int}
     */
    private function resolveResourceConfig(string $resourceClass, array $defaults): array
    {
        $declared = $resourceClass::globallySearchable();

        if (is_bool($declared)) {
            return [
                'enabled' => $declared,
                'limit' => $defaults['limit'],
                'min_query' => $defaults['min_query'],
            ];
        }

        return [
            'enabled' => (bool) ($declared['enabled'] ?? true),
            'limit' => (int) ($declared['limit'] ?? $defaults['limit']),
            'min_query' => (int) ($declared['min_query'] ?? $defaults['min_query']),
        ];
    }

    /**
     * Resolve global limit / min_query defaults from config, with
     * sensible floors so a misconfigured environment does not yield a
     * non-functional search endpoint.
     *
     * @return array{limit: int, min_query: int}
     */
    private function globalDefaults(): array
    {
        $limit = (int) config('martis.search.default_limit', 5);
        $minQuery = (int) config('martis.search.min_query', 2);

        return [
            'limit' => max(1, $limit),
            'min_query' => max(1, $minQuery),
        ];
    }

    /**
     * Re-run the same indexQuery + search pipeline (without the limit)
     * to compute the total match count. Called only when the limited
     * set was full, so the cost is bounded to "at most one extra COUNT
     * per resource that has overflow".
     *
     * @param  class-string<resource>  $resourceClass
     */
    private function countAfterSearch(Request $request, string $resourceClass, string $term): int
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();
        $builder = $modelClass::query();
        $builder = $resourceClass::indexQuery($request, $builder);
        /** @var Builder<Model> $builder */
        $builder = SearchResolver::apply($request, $builder, $resourceClass, $term);

        return (int) $builder->count();
    }
}
