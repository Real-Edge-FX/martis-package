<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\SearchResolver;

/**
 * Global Search controller — Nova v5 parity.
 *
 * Single endpoint to search across all registered globallySearchable()
 * resources. Uses SearchResolver for Scout-or-LIKE strategy per resource.
 *
 * GET /api/search?q=term
 *
 * Response:
 * {"results": [{"resource": "users", "label": "Users", "items": [
 *   {"id": 1, "title": "John Doe", "subtitle": "john@email.com", "url": "/resources/users/1"}
 * ]}]}
 */
class SearchController extends MartisController
{
    /** Maximum results returned per resource. */
    private const RESULTS_PER_RESOURCE = 5;

    /** Minimum query length before search is triggered. */
    private const MIN_QUERY_LENGTH = 2;

    /** Create a new controller instance. */
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * Execute a global search across all searchable resources.
     *
     * Only resources where globallySearchable() returns true and the
     * authenticated user passes authorizedToViewAny() are searched.
     *
     * @response array{results: array<int, array{resource: string, label: string, items: array<int, array{id: int|string, title: string, subtitle: string|null, url: string}>}>}
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->string('q'));

        if (mb_strlen($q) < self::MIN_QUERY_LENGTH) {
            return response()->json(['results' => []]);
        }

        $results = [];

        foreach ($this->registry->list() as $resourceClass) {
            /** @var class-string<resource> $resourceClass */
            if (! $resourceClass::globallySearchable()) {
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
            $builder = SearchResolver::apply($request, $builder, $resourceClass, $q);

            $models = $builder->limit(self::RESULTS_PER_RESOURCE)->get();

            if ($models->isEmpty()) {
                continue;
            }

            $items = [];
            /** @var Model $model */
            foreach ($models as $model) {
                $resourceInstance = new $resourceClass($model);
                $items[] = [
                    'id' => $model->getKey(),
                    'title' => $resourceInstance->title(),
                    'subtitle' => $resourceInstance->searchSubtitle($model),
                    'url' => '/resources/'.$resourceClass::uriKey().'/'.$model->getKey(),
                ];
            }

            $results[] = [
                'resource' => $resourceClass::uriKey(),
                'label' => $resourceClass::label(),
                'items' => $items,
            ];
        }

        return response()->json(['results' => $results]);
    }
}
