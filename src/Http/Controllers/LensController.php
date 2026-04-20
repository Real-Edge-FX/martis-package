<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Martis\Contracts\FilterContract;
use Martis\FieldContext;
use Martis\Fields\Field;
use Martis\Http\Requests\LensRequest;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonPaginatedResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * Controller for Lens index endpoints.
 *
 * Route: GET /martis/api/resources/{resource}/lenses/{lens}
 *
 * Handles query composition (filters, ordering, pagination, search),
 * authorization, caching (Martis extension) and summary row aggregation
 * (Martis extension).
 */
class LensController extends MartisController
{
    public function __construct(private readonly ResourceRegistry $registry) {}

    /**
     * Return a paginated list of records for the lens, plus optional
     * summary aggregates.
     */
    public function index(Request $request, string $resource, string $lens): IlluminateJsonResponse
    {
        [$resourceClass, $error] = $this->resolveResourceClass($this->registry, $resource);
        if ($error !== null) {
            return $error;
        }

        /** @var class-string<Resource> $resourceClass */
        $resourceInstance = new $resourceClass;

        if (! $resourceInstance->authorizedToViewAny($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $lensInstance = $this->findLens($resourceInstance, $lens, $request);
        if ($lensInstance instanceof IlluminateJsonResponse) {
            return $lensInstance;
        }

        if (! $lensInstance->authorizedToSee($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $filtersByUriKey = $this->collectAuthorizedFilters($lensInstance, $resourceInstance, $request);
        $lensRequest = LensRequest::fromRequest($request, $filtersByUriKey);

        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();

        // Soft-delete filter, mirrored from ResourceController.
        // Applied to the base query so both the lens dataset and the summary
        // aggregation see the same rows.
        /** @var Builder<Model> $baseQuery */
        $baseQuery = $modelClass::query();
        $trashedMode = '';
        if ($resourceClass::softDeletes() && $resourceClass::canViewTrashed()) {
            $trashedMode = (string) $request->query('trashed', '');
            if ($trashedMode === 'with') {
                /** @phpstan-ignore-next-line — guarded by softDeletes() check */
                $baseQuery = $modelClass::withTrashed();
            } elseif ($trashedMode === 'only') {
                /** @phpstan-ignore-next-line — guarded by softDeletes() check */
                $baseQuery = $modelClass::onlyTrashed();
            }
        }

        $ttl = $lensInstance->cacheTtl();
        // Lens inherits the resource's perPageOptions / perPage when it
        // does not declare the method itself. Reflection distinguishes
        // a deliberate override (including returning the base default)
        // from no override at all.
        $lensClass = get_class($lensInstance);
        $perPageOptions = $lensInstance->hasOverride('perPageOptions')
            ? $lensClass::perPageOptions()
            : $resourceClass::perPageOptions();
        $defaultPerPage = $lensInstance->hasOverride('perPage')
            ? $lensClass::perPage()
            : $resourceClass::perPage();
        // Clamp to options (Option A) so the dropdown and the real filter stay in sync.
        if ($perPageOptions !== [] && ! in_array($defaultPerPage, $perPageOptions, true)) {
            $defaultPerPage = $perPageOptions[0];
        }
        $perPage = max(1, min((int) $request->query('per_page', (string) $defaultPerPage), 100));
        $page = max(1, (int) $request->query('page', '1'));

        // Auto-invalidate lens caches when the underlying table changes.
        // A cheap "table version" query combining COUNT(*) and MAX(updated_at)
        // becomes part of the cache key, so inserts/deletes bump COUNT and
        // updates bump MAX — either way the next request misses the cache.
        // This provides "just works" caching without needing model
        // observers or cache tags.
        $tableVersion = $this->resolveTableVersion($modelClass, $resourceClass::softDeletes());
        $cacheKey = $this->buildCacheKey($lensInstance, $lensRequest, $perPage, $page, $trashedMode, $tableVersion);

        $fields = $this->resolveLensFields($lensInstance, $request);

        $builder = function () use ($lensInstance, $lensRequest, $baseQuery, $perPage, $page, $resourceClass, $fields, $request): array {
            $result = $this->executeLensQuery($lensInstance, $lensRequest, $baseQuery, $perPage, $page);
            [$items, $meta, $links] = $result;

            $data = array_values(array_map(function (Model $model) use ($resourceClass, $fields): array {
                /** @var Resource $res */
                $res = new $resourceClass($model);

                return $this->serializeModelForIndex($res, $fields, $model);
            }, $items));

            // Summary must aggregate over the filtered lens dataset — not
            // the base (unfiltered) query. We call lens.query() a second
            // time to get a fresh Builder with the lens's restrictions +
            // user filters + user search applied, minus pagination. The
            // cloned baseQuery is disposable — it won't be used again.
            $summaryQuery = $lensInstance->query($lensRequest, clone $baseQuery);
            if ($summaryQuery instanceof Builder) {
                $summary = $lensInstance->summary($request, $summaryQuery);
            } else {
                // If the lens returned a Paginator directly it already
                // materialised the results; skip summary rather than
                // re-running an expensive query.
                $summary = [];
            }

            return [$data, $meta, $links, $summary];
        };

        if ($ttl > 0) {
            /** @var array{0: list<array<string, mixed>>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>} $cached */
            $cached = Cache::remember($cacheKey, $ttl, $builder);
            [$data, $meta, $links, $summary] = $cached;
        } else {
            [$data, $meta, $links, $summary] = $builder();
        }

        $extraMeta = [
            'fields' => array_map(fn (Field $field): array => $field->toArray(), $fields),
            'actions' => $this->resolveLensActions($lensInstance, $resourceInstance, $request),
            'perPage' => $defaultPerPage,
            'perPageOptions' => $perPageOptions,
            'polling' => $lensInstance->pollingEnabled(),
            'pollingInterval' => $lensInstance->pollingInterval(),
            'showPollingToggle' => $lensInstance->pollingToggleVisible(),
            'defaultFilters' => $lensInstance->defaultFilters(),
        ];
        if ($summary !== []) {
            $extraMeta['summary'] = $summary;
        }

        /** @var array{current_page: int, from: int|null, last_page: int, per_page: int, to: int|null, total: int} $meta */
        return JsonPaginatedResponse::make($data, $meta, $links, $extraMeta)->toResponse();
    }

    /**
     * Resolve the actions list for a lens. Lenses that do not override
     * `actions()` inherit the parent resource's actions.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveLensActions(Lens $lensInstance, Resource $resourceInstance, Request $request): array
    {
        $actions = $lensInstance->hasOverride('actions')
            ? $lensInstance->actions($request)
            : $resourceInstance->actions($request);

        return array_values(array_map(
            fn ($action): array => $action->jsonSerialize(),
            array_filter($actions, fn ($action) => is_object($action)
                && method_exists($action, 'authorizedToSee')
                && $action->authorizedToSee($request)
                && method_exists($action, 'jsonSerialize'))
        ));
    }

    /**
     * Run the lens-defined query and paginate the result.
     *
     * @param  Builder<Model>  $baseQuery
     * @return array{list<Model>, array<string, mixed>, array<string, mixed>}
     */
    private function executeLensQuery(
        Lens $lensInstance,
        LensRequest $lensRequest,
        Builder $baseQuery,
        int $perPage,
        int $page,
    ): array {
        /** @var Builder<Model>|Paginator $result */
        $result = $lensInstance->query($lensRequest, clone $baseQuery);

        if ($result instanceof Paginator) {
            return $this->extractPaginatorPayload($result);
        }

        /** @var Builder<Model> $builder */
        $builder = $result;
        $paginator = $builder->paginate($perPage, ['*'], 'page', $page);

        return $this->extractPaginatorPayload($paginator);
    }

    /**
     * @param  Paginator<int, Model>  $paginator
     * @return array{list<Model>, array<string, mixed>, array<string, mixed>}
     */
    private function extractPaginatorPayload(Paginator $paginator): array
    {
        $isLengthAware = $paginator instanceof LengthAwarePaginator;

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'last_page' => $isLengthAware ? $paginator->lastPage() : 1,
            'total' => $isLengthAware ? $paginator->total() : count($paginator->items()),
        ];

        $links = [
            'first' => $paginator->url(1),
            'last' => $isLengthAware ? $paginator->url($paginator->lastPage()) : null,
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        /** @var list<Model> $items */
        $items = array_values($paginator->items());

        return [$items, $meta, $links];
    }

    /**
     * Build the deterministic cache key for the current lens view.
     */
    private function buildCacheKey(
        Lens $lensInstance,
        LensRequest $lensRequest,
        int $perPage,
        int $page,
        string $trashed = '',
        string $tableVersion = '',
    ): string {
        $parts = [
            'martis-lens',
            static::classHash($lensInstance),
            $lensInstance->uriKey(),
            $tableVersion,
            $lensRequest->search,
            $lensRequest->sortColumn ?? '',
            $lensRequest->sortDirection->value,
            md5(json_encode($lensRequest->selectedFilters) ?: ''),
            $trashed,
            $perPage,
            $page,
        ];

        return implode(':', array_map('strval', $parts));
    }

    /**
     * Cheap signature of the current table state used for cache-key auto
     * invalidation. Combines row count and max updated_at so either a
     * write or a delete bumps the signature.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function resolveTableVersion(string $modelClass, bool $withTrashed): string
    {
        /** @var Builder<Model> $q */
        $q = $withTrashed
            /** @phpstan-ignore-next-line */
            ? $modelClass::withTrashed()
            : $modelClass::query();

        $row = $q->selectRaw('COUNT(*) as c, MAX(updated_at) as u')->first();
        $count = (int) ($row?->c ?? 0);
        $updated = (string) ($row?->u ?? '');

        return $count.'|'.$updated;
    }

    private static function classHash(Lens $lens): string
    {
        return substr(sha1(get_class($lens)), 0, 10);
    }

    /**
     * Locate a lens declared by the resource, honouring `authorizedToSee`.
     * Returns a 404 JsonResponse when not found.
     */
    private function findLens(Resource $resourceInstance, string $uriKey, Request $request): Lens|IlluminateJsonResponse
    {
        foreach ($resourceInstance->lenses($request) as $lens) {
            if ($lens instanceof Lens && $lens->uriKey() === $uriKey) {
                return $lens;
            }
        }

        return JsonErrorResponse::notFound("Lens '{$uriKey}' not found on resource.")->toResponse();
    }

    /**
     * Collect filters available inside the lens, indexed by uriKey, and
     * stripping those the user is not allowed to see.
     *
     * Inheritance rule (explicit override semantics):
     *   - Lens overrode `filters()` → use its value verbatim (even []).
     *     This lets developers disable filters entirely on a lens.
     *   - Lens did NOT override → inherit the parent resource's filters.
     *
     * @return array<string, FilterContract>
     */
    private function collectAuthorizedFilters(Lens $lensInstance, Resource $resourceInstance, Request $request): array
    {
        $inheriting = ! $lensInstance->hasOverride('filters');
        $filters = $inheriting
            ? $resourceInstance->filters($request)
            : $lensInstance->filters($request);

        $result = [];
        foreach ($filters as $filter) {
            if (! $filter instanceof FilterContract) {
                continue;
            }
            if (method_exists($filter, 'authorizedToSee') && ! $filter->authorizedToSee($request)) {
                continue;
            }
            // Martis extension: the resource can tag filters as
            // "not-for-lenses" with `->excludeFromLens()`. Such filters are
            // skipped when the lens is inheriting from the resource; an
            // explicit lens override trumps the tag.
            if ($inheriting
                && method_exists($filter, 'isExcludedFromLens')
                && $filter->isExcludedFromLens()
            ) {
                continue;
            }

            $result[$filter->uriKey()] = $filter;
        }

        return $result;
    }

    /**
     * Resolve the fields declared by the lens for index rendering. When
     * the lens returns an empty list, fall back to the parent resource's
     * index fields so that developers can opt into full parity without
     * redeclaring columns.
     *
     * @return list<Field>
     */
    private function resolveLensFields(Lens $lensInstance, Request $request): array
    {
        $fields = $lensInstance->fields($request);

        /** @var list<Field> $filtered */
        $filtered = array_values(array_filter(
            $fields,
            fn ($field) => $field instanceof Field,
        ));

        return Field::filterForContext($filtered, FieldContext::INDEX);
    }
}
