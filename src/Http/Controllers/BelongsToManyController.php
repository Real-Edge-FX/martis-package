<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Martis\Contracts\FieldContract;
use Martis\FieldContext;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Field;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonPaginatedResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\SearchResolver;

/**
 * Controller for BelongsToMany relationship operations.
 *
 * Endpoints:
 *   GET    /api/resources/{resource}/{id}/belongs-to-many/{relationship}                     → index
 *   GET    /api/resources/{resource}/{id}/belongs-to-many/{relationship}/attachable          → attachableIndex
 *   POST   /api/resources/{resource}/{id}/belongs-to-many/{relationship}/attach              → attach
 *   DELETE /api/resources/{resource}/{id}/belongs-to-many/{relationship}/{relatedId}/detach  → detach
 *   PUT    /api/resources/{resource}/{id}/belongs-to-many/{relationship}/{relatedId}/pivot   → updatePivot
 */
class BelongsToManyController extends MartisController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * List records currently attached to the parent via this BelongsToMany relationship.
     * Includes pivot data as a `_pivot` key on each record.
     */
    public function index(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
    ): IlluminateJsonResponse {
        $ctx = $this->resolveContext($request, $resource, $id, $relationship);
        if ($ctx instanceof IlluminateJsonResponse) {
            return $ctx;
        }

        ['relatedResourceClass' => $relatedResourceClass, 'relation' => $relation, 'field' => $field] = $ctx;

        /** @var Builder<Model> $query */
        $query = $relation->getQuery();

        // Eager load pivot columns
        $pivotColumns = $this->getPivotColumnNames($field);
        if (! empty($pivotColumns)) {
            $relation->withPivot($pivotColumns);
            $query = $relation->getQuery();
        }

        // Search
        $rawSearch = $request->query('search', '');
        $search = trim(is_string($rawSearch) ? $rawSearch : '');
        if ($search !== '') {
            SearchResolver::apply($request, $query, $relatedResourceClass, $search);
        }

        // Sort
        $rawSort = $request->query('sort');
        $rawDir = $request->query('direction', 'asc');
        $dirStr = is_string($rawDir) ? $rawDir : 'asc';
        $direction = in_array(strtolower($dirStr), ['asc', 'desc'], true)
            ? strtolower($dirStr)
            : 'asc';

        if (is_string($rawSort) && $rawSort !== '') {
            $query->orderBy($rawSort, $direction);
        }

        // Pagination — use $relation->paginate() (not $query->paginate()) so Laravel
        // can hydrate the pivot accessor on each resulting Model instance.
        $perPage = min((int) ($request->query('per_page', '10')), 100);
        $paginator = $relation->paginate($perPage);

        $data = array_values(
            collect($paginator->items())->map(function (Model $model) use ($relatedResourceClass, $request, $field): array {
                $res = new $relatedResourceClass($model);
                $row = $this->serializeModel(
                    $res,
                    Field::filterForContext($res->fieldsForIndex($request), FieldContext::INDEX),
                    $model,
                );

                // Attach pivot data
                $pivotColumns = $this->getPivotColumnNames($field);
                if (! empty($pivotColumns) && isset($model->pivot)) {
                    $pivotData = [];
                    foreach ($pivotColumns as $col) {
                        $pivotData[$col] = $model->pivot->{$col} ?? null;
                    }
                    $row['_pivot'] = $pivotData;
                }

                return $row;
            })->all()
        );

        return JsonPaginatedResponse::make(
            data: $data,
            paginationMeta: [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            links: [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        )->toResponse();
    }

    /**
     * List records available for attachment (not yet attached, or all if allowDuplicates).
     * Applies relatableQueryUsing closure if defined.
     */
    public function attachableIndex(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
    ): IlluminateJsonResponse {
        $ctx = $this->resolveContext($request, $resource, $id, $relationship);
        if ($ctx instanceof IlluminateJsonResponse) {
            return $ctx;
        }

        [
            'parentModel' => $parentModel,
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
            'field' => $field,
        ] = $ctx;

        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relatedResourceClass::model();

        /** @var Builder<Model> $query */
        $query = $relatedModelClass::query();

        // Apply relatableQueryUsing closure
        $relatableClosure = $field->getRelatableQueryClosure();
        if ($relatableClosure !== null) {
            $relatableClosure($request, $query);
        }

        // Exclude already-attached records unless allowDuplicateRelations
        if (! $field->isAllowDuplicates()) {
            $attachedIds = $relation->pluck((new $relatedModelClass)->getKeyName())->all();
            if (! empty($attachedIds)) {
                $query->whereNotIn((new $relatedModelClass)->getKeyName(), $attachedIds);
            }
        }

        // Search
        $rawSearch = $request->query('search', '');
        $search = trim(is_string($rawSearch) ? $rawSearch : '');
        if ($search !== '') {
            SearchResolver::apply($request, $query, $relatedResourceClass, $search);
        }

        $perPage = min((int) ($request->query('per_page', '20')), 100);
        $paginator = $query->paginate($perPage);

        $titleAttr = $field->getRelationship();

        $data = array_values(
            collect($paginator->items())->map(function (Model $model) use ($relatedResourceClass, $request): array {
                $res = new $relatedResourceClass($model);

                return $this->serializeModel(
                    $res,
                    Field::filterForContext($res->fieldsForIndex($request), FieldContext::INDEX),
                    $model,
                );
            })->all()
        );

        return JsonPaginatedResponse::make(
            data: $data,
            paginationMeta: [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            links: [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        )->toResponse();
    }

    /**
     * Attach one or more related records to the parent, optionally with pivot data.
     *
     * Accepts either `related_id` (single) or `related_ids` (array) for batch attach.
     */
    public function attach(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
    ): IlluminateJsonResponse {
        $ctx = $this->resolveContext($request, $resource, $id, $relationship);
        if ($ctx instanceof IlluminateJsonResponse) {
            return $ctx;
        }

        [
            'parentModel' => $parentModel,
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
            'field' => $field,
        ] = $ctx;

        // Accept related_ids (array) or related_id (single)
        $relatedIds = $request->input('related_ids');
        if (is_array($relatedIds) && ! empty($relatedIds)) {
            /** @var list<int|string> $relatedIds */
            return $this->attachMany($request, $relatedIds, $parentModel, $relatedResourceClass, $relation, $field, $ctx, $resource, $relationship);
        }

        $relatedId = $request->input('related_id');
        if ($relatedId === null || $relatedId === '') {
            return JsonErrorResponse::validation(
                ['related_id' => ['The related_id or related_ids field is required.']],
                'Validation failed.',
            )->toResponse();
        }

        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relatedResourceClass::model();

        /** @var Model|null $relatedModel */
        $relatedModel = $relatedModelClass::find($relatedId); // @phpstan-ignore-line
        if ($relatedModel === null) {
            return JsonErrorResponse::notFound('Related record not found.')->toResponse();
        }

        // Authorization
        if (! $this->canAttach($request, $parentModel, $relatedModel, $ctx)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        // Check duplicates
        if (! $field->isAllowDuplicates()) {
            $alreadyAttached = $relation->where($relatedModel->getKeyName(), $relatedModel->getKey())->exists();
            if ($alreadyAttached) {
                return JsonErrorResponse::validation(
                    ['related_id' => ['This record is already attached.']],
                    'Validation failed.',
                )->toResponse();
            }
        }

        // Pivot data
        $pivotData = $this->extractPivotData($request, $field);
        if ($pivotData instanceof IlluminateJsonResponse) {
            return $pivotData;
        }

        try {
            $relation->attach($relatedModel->getKey(), $pivotData);
        } catch (QueryException $e) {
            Log::error('Martis: BelongsToMany attach error', [
                'resource' => $resource,
                'relationship' => $relationship,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        return JsonResponse::make(
            ['id' => $relatedModel->getKey(), '_title' => (new $relatedResourceClass($relatedModel))->title()],
            meta: ['message' => 'Record attached successfully.'],
        )->toResponse(201);
    }

    /**
     * Batch-attach multiple related records.
     *
     * @param  list<int|string>  $relatedIds
     * @param  EloquentBelongsToMany<Model, Model>  $relation
     * @param  array<string, mixed>  $ctx
     */
    private function attachMany(
        Request $request,
        array $relatedIds,
        Model $parentModel,
        string $relatedResourceClass,
        EloquentBelongsToMany $relation,
        BelongsToMany $field,
        array $ctx,
        string $resource,
        string $relationship,
    ): IlluminateJsonResponse {
        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relatedResourceClass::model();

        // Pivot data (shared across all records in batch mode)
        $pivotData = $this->extractPivotData($request, $field);
        if ($pivotData instanceof IlluminateJsonResponse) {
            return $pivotData;
        }

        $attached = [];
        $errors = [];

        foreach ($relatedIds as $relatedId) {
            /** @var Model|null $relatedModel */
            $relatedModel = $relatedModelClass::find($relatedId); // @phpstan-ignore-line
            if ($relatedModel === null) {
                $errors[] = "Record {$relatedId} not found.";

                continue;
            }

            if (! $this->canAttach($request, $parentModel, $relatedModel, $ctx)) {
                $errors[] = "Not authorized to attach record {$relatedId}.";

                continue;
            }

            if (! $field->isAllowDuplicates()) {
                $alreadyAttached = $relation->where($relatedModel->getKeyName(), $relatedModel->getKey())->exists();
                if ($alreadyAttached) {
                    continue; // Skip silently for batch
                }
            }

            try {
                $relation->attach($relatedModel->getKey(), $pivotData);
                $attached[] = [
                    'id' => $relatedModel->getKey(),
                    '_title' => (new $relatedResourceClass($relatedModel))->title(), // @phpstan-ignore-line
                ];
            } catch (QueryException $e) {
                Log::error('Martis: BelongsToMany batch attach error', [
                    'resource' => $resource,
                    'relationship' => $relationship,
                    'relatedId' => $relatedId,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = "Failed to attach record {$relatedId}.";
            }
        }

        $count = count($attached);
        $message = $count === 1
            ? '1 record attached successfully.'
            : "{$count} records attached successfully.";

        /** @var array<string, mixed> $responseData */
        $responseData = ['attached' => $attached, 'count' => $count];

        return JsonResponse::make(
            $responseData,
            meta: array_filter([
                'message' => $message,
                'errors' => ! empty($errors) ? $errors : null,
            ]),
        )->toResponse(201);
    }

    /**
     * Extract and validate pivot data from the request.
     *
     * @return array<string, mixed>|IlluminateJsonResponse
     */
    private function extractPivotData(Request $request, BelongsToMany $field): array|IlluminateJsonResponse
    {
        $pivotData = [];
        $pivotFields = $field->getPivotFields();
        if (! empty($pivotFields)) {
            $pivotRules = [];
            foreach ($pivotFields as $pf) {
                if ($pf instanceof Field) {
                    $pivotRules[$pf->attribute()] = $pf->buildRules();
                }
            }
            if (! empty($pivotRules)) {
                $validator = Validator::make($request->all(), $pivotRules);
                if ($validator->fails()) {
                    return JsonErrorResponse::validation(
                        $validator->errors()->toArray(),
                        'Validation failed.',
                    )->toResponse();
                }
            }
            foreach ($pivotFields as $pf) {
                if (! $pf instanceof Field) {
                    continue;
                }
                if ($request->has($pf->attribute())) {
                    $pivotData[$pf->attribute()] = $request->input($pf->attribute());
                } elseif ($pf->getDefaultValue() !== null) {
                    $pivotData[$pf->attribute()] = $pf->getDefaultValue();
                }
            }
        }

        return $pivotData;
    }

    /**
     * Detach a related record from the parent.
     */
    public function detach(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
        int|string $relatedId,
    ): IlluminateJsonResponse {
        $ctx = $this->resolveContext($request, $resource, $id, $relationship);
        if ($ctx instanceof IlluminateJsonResponse) {
            return $ctx;
        }

        [
            'parentModel' => $parentModel,
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
        ] = $ctx;

        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relatedResourceClass::model();

        /** @var Model|null $relatedModel */
        $relatedModel = $relatedModelClass::find($relatedId); // @phpstan-ignore-line
        if ($relatedModel === null) {
            return JsonErrorResponse::notFound('Related record not found.')->toResponse();
        }

        // Authorization
        if (! $this->canDetach($request, $parentModel, $relatedModel, $ctx)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        try {
            $relation->detach($relatedModel->getKey());
        } catch (QueryException $e) {
            Log::error('Martis: BelongsToMany detach error', [
                'resource' => $resource,
                'relationship' => $relationship,
                'relatedId' => $relatedId,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        return new IlluminateJsonResponse(
            ['data' => [], 'meta' => ['message' => 'Record detached successfully.'], 'links' => []],
            200,
        );
    }

    /**
     * Update pivot data for an attached record.
     */
    public function updatePivot(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
        int|string $relatedId,
    ): IlluminateJsonResponse {
        $ctx = $this->resolveContext($request, $resource, $id, $relationship);
        if ($ctx instanceof IlluminateJsonResponse) {
            return $ctx;
        }

        [
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
            'field' => $field,
        ] = $ctx;

        $pivotFields = $field->getPivotFields();
        if (empty($pivotFields)) {
            return JsonErrorResponse::notFound('No pivot fields defined for this relationship.')->toResponse();
        }

        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relatedResourceClass::model();

        /** @var Model|null $relatedModel */
        $relatedModel = $relatedModelClass::find($relatedId); // @phpstan-ignore-line
        if ($relatedModel === null) {
            return JsonErrorResponse::notFound('Related record not found.')->toResponse();
        }

        // Validate pivot fields
        $pivotRules = [];
        foreach ($pivotFields as $pf) {
            if ($pf instanceof Field) {
                $fieldRules = array_values(array_filter($pf->buildRules(), fn ($r) => is_string($r) && $r !== 'required'));
                $pivotRules[$pf->attribute()] = empty($fieldRules) ? ['sometimes'] : array_merge(['sometimes'], $fieldRules);
            }
        }

        $validator = Validator::make($request->all(), $pivotRules);
        if ($validator->fails()) {
            return JsonErrorResponse::validation(
                $validator->errors()->toArray(),
                'Validation failed.',
            )->toResponse();
        }

        $pivotData = [];
        foreach ($pivotFields as $pf) {
            if ($pf instanceof Field && $request->has($pf->attribute())) {
                $pivotData[$pf->attribute()] = $request->input($pf->attribute());
            }
        }

        try {
            $relation->updateExistingPivot($relatedModel->getKey(), $pivotData);
        } catch (QueryException $e) {
            Log::error('Martis: BelongsToMany updatePivot error', [
                'resource' => $resource,
                'relationship' => $relationship,
                'relatedId' => $relatedId,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        return JsonResponse::make(
            ['id' => $relatedId, 'pivot' => $pivotData],
            meta: ['message' => 'Pivot updated successfully.'],
        )->toResponse();
    }

    /**
     * Resolve all context needed for a BelongsToMany operation.
     *
     * @return array{parentModel: Model, parentResourceClass: class-string<resource>, relatedResourceClass: class-string<resource>, field: BelongsToMany, relation: EloquentBelongsToMany<Model, Model>}|IlluminateJsonResponse
     */
    private function resolveContext(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
    ): array|IlluminateJsonResponse {
        if (! $this->registry->has($resource)) {
            return JsonErrorResponse::notFound("Resource '{$resource}' not found.")->toResponse();
        }

        /** @var class-string<resource> $resourceClass */
        $resourceClass = $this->registry->get($resource);

        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();

        /** @phpstan-ignore staticMethod.notFound */
        $parentModel = $modelClass::find($id); // @phpstan-ignore-line

        if ($parentModel === null) {
            return JsonErrorResponse::notFound('Parent record not found.')->toResponse();
        }

        $parentInstance = new $resourceClass($parentModel);

        if (! $parentInstance->authorizedToView($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        // Find the BelongsToMany field in the parent resource
        $fields = $parentInstance->fieldsForDetail($request);
        $btmField = null;

        foreach ($fields as $fieldItem) {
            if ($fieldItem instanceof BelongsToMany && $fieldItem->getRelationship() === $relationship) {
                $btmField = $fieldItem;
                break;
            }
        }

        if ($btmField === null) {
            return JsonErrorResponse::notFound("Relationship '{$relationship}' not found.")->toResponse();
        }

        // Validate the Eloquent relationship
        if (! method_exists($parentModel, $relationship)) {
            return JsonErrorResponse::notFound("Relationship method '{$relationship}' does not exist.")->toResponse();
        }

        $relation = $parentModel->{$relationship}();

        if (! $relation instanceof EloquentBelongsToMany) {
            return JsonErrorResponse::notFound("'{$relationship}' is not a belongsToMany relationship.")->toResponse();
        }

        // Resolve the related resource class
        $relatedResourceKey = $btmField->getRelatedResourceKey();

        if ($relatedResourceKey === null || ! $this->registry->has($relatedResourceKey)) {
            return JsonErrorResponse::notFound('Related resource not registered.')->toResponse();
        }

        /** @var class-string<resource> $relatedResourceClass */
        $relatedResourceClass = $this->registry->get($relatedResourceKey);

        return [
            'parentModel' => $parentModel,
            'parentResourceClass' => $resourceClass,
            'relatedResourceClass' => $relatedResourceClass,
            'field' => $btmField,
            'relation' => $relation,
        ];
    }

    /**
     * Get pivot column names from the field's pivot fields.
     *
     * @return list<string>
     */
    private function getPivotColumnNames(BelongsToMany $field): array
    {
        return array_values(array_map(
            fn (Field $f): string => $f->attribute(),
            array_filter($field->getPivotFields(), fn ($f): bool => $f instanceof Field),
        ));
    }

    /**
     * Check attach authorization on the parent resource.
     */
    /**
     * @param  array<string, mixed>  $ctx
     */
    private function canAttach(Request $request, Model $parentModel, Model $relatedModel, array $ctx): bool
    {
        $parentInstance = new $ctx['parentResourceClass']($parentModel);
        if (method_exists($parentInstance, 'authorizedToAttach')) {
            return $parentInstance->authorizedToAttach($request, $relatedModel);
        }

        return $parentInstance->authorizedToUpdate($request); // @phpstan-ignore-line
    }

    /**
     * Check detach authorization on the parent resource.
     */
    /**
     * @param  array<string, mixed>  $ctx
     */
    private function canDetach(Request $request, Model $parentModel, Model $relatedModel, array $ctx): bool
    {
        $parentInstance = new $ctx['parentResourceClass']($parentModel);
        if (method_exists($parentInstance, 'authorizedToDetach')) {
            return $parentInstance->authorizedToDetach($request, $relatedModel);
        }

        return $parentInstance->authorizedToUpdate($request); // @phpstan-ignore-line
    }

    /**
     * Serialize a model into the API response format.
     *
     * @param  list<FieldContract>  $fields
     * @return array<string, mixed>
     */
    private function serializeModel(Resource $resource, array $fields, Model $model): array
    {
        $data = ['id' => $model->getKey()];
        $data['_title'] = $resource->title();
        $data['_resource'] = [
            'uriKey' => $resource::uriKey(),
            'label' => $resource::label(),
            'singularLabel' => $resource::singularLabel(),
        ];
        $data['_authorization'] = $resource->authorizationMetadata(request());

        foreach ($fields as $field) {
            $data[$field->attribute()] = $field->resolve($model);
        }

        return $data;
    }

    private function handleDatabaseError(QueryException $e): IlluminateJsonResponse
    {
        $code = (string) ($e->errorInfo[1] ?? '');

        $message = match ($code) {
            '1062' => 'A record with this value already exists.',
            '1451' => 'This record is referenced by other records.',
            '1452' => 'The referenced record does not exist.',
            default => 'A database error occurred.',
        };

        return JsonErrorResponse::serverError($message)->toResponse();
    }
}
