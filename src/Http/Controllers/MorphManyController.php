<?php

namespace Martis\Http\Controllers;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Martis\Contracts\FieldContract;
use Martis\Enums\SortDirection;
use Martis\Enums\TrashedFilter;
use Martis\FieldContext;
use Martis\Fields\Field;
use Martis\Fields\File;
use Martis\Fields\MorphMany;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonPaginatedResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\SearchResolver;

/**
 * Controller for MorphMany relationship CRUD operations.
 *
 * Endpoints:
 *   GET    /api/resources/{resource}/{id}/morph-many/{relationship}                → index
 *   POST   /api/resources/{resource}/{id}/morph-many/{relationship}                → store
 *   PUT    /api/resources/{resource}/{id}/morph-many/{relationship}/{relatedId}    → update
 *   DELETE /api/resources/{resource}/{id}/morph-many/{relationship}/{relatedId}    → destroy
 */
class MorphManyController extends MartisController
{
    /** Create the controller and inject the resource registry. */
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * List related records for a MorphMany relationship.
     */
    #[QueryParameter('search', description: 'Filter related records by free text.', required: false, type: 'string')]
    #[QueryParameter('per_page', description: 'Records per page. Default: 10, max: 100.', required: false, type: 'integer')]
    #[QueryParameter('sort', description: 'Column to sort by.', required: false, type: 'string')]
    #[QueryParameter('direction', description: 'Sort direction: asc or desc.', required: false, type: 'string')]
    #[QueryParameter('trashed', description: 'Soft-delete filter. Values: empty (active only), with (include trashed), only (trashed only).', required: false, type: 'string')]
    public function index(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
    ): IlluminateJsonResponse {
        $context = $this->resolveContext($request, $resource, $id, $relationship);

        if ($context instanceof IlluminateJsonResponse) {
            return $context;
        }

        [
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
        ] = $context;

        /** @var Builder<Model> $query */
        $query = $relation->getQuery();

        // Soft-delete filter
        if ($relatedResourceClass::softDeletes() && $relatedResourceClass::canViewTrashed()) {
            $trashed = TrashedFilter::tryFrom((string) $request->query('trashed', ''))
                ?? TrashedFilter::Active;
            if ($trashed === TrashedFilter::With) {
                /** @phpstan-ignore-next-line — guarded by softDeletes() check above */
                $query->withTrashed();
            } elseif ($trashed === TrashedFilter::Only) {
                /** @phpstan-ignore-next-line — guarded by softDeletes() check above */
                $query->onlyTrashed();
            }
        }

        $rawSearch = $request->query('search', '');
        $search = trim(is_string($rawSearch) ? $rawSearch : '');

        if ($search !== '') {
            SearchResolver::apply($request, $query, $relatedResourceClass, $search);
        }

        $this->applySorting($request, $query, $relatedResourceClass);

        $perPage = min(
            (int) ($request->query('per_page', '10')),
            100,
        );

        $paginator = $query->paginate($perPage);

        /** @var list<array<string, mixed>> $data */
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
     * Create a new related record linked via the morph relationship.
     */
    public function store(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
    ): IlluminateJsonResponse {
        $context = $this->resolveContext($request, $resource, $id, $relationship, 'create');

        if ($context instanceof IlluminateJsonResponse) {
            return $context;
        }

        [
            'parentModel' => $parentModel,
            'parentResourceClass' => $resourceClass,
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
        ] = $context;

        $parentInstance = new $resourceClass($parentModel);
        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relatedResourceClass::model();
        if (! $parentInstance->authorizedToAdd($request, $relatedModelClass)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $relatedInstance = new $relatedResourceClass;
        $fields = Field::filterForContext($relatedInstance->fieldsForCreate($request), FieldContext::CREATE);

        $validationError = $this->validateRequest($request, $fields);
        if ($validationError !== null) {
            return $validationError;
        }

        $relatedModel = $relatedResourceClass::newModel();
        $this->fillFields($request, $fields, $relatedModel);

        // Set the polymorphic type and id columns
        $relatedModel->setAttribute($relation->getMorphType(), $relation->getMorphClass());
        $relatedModel->setAttribute($relation->getForeignKeyName(), $parentModel->getKey());

        try {
            $relatedInstance = new $relatedResourceClass($relatedModel);
            $relatedInstance->beforeSave($relatedModel, $request, creating: true);
            $relatedModel->save();
            $relatedInstance->afterSave($relatedModel, $request, creating: true);
        } catch (QueryException $e) {
            Log::error('Martis: MorphMany store error', [
                'resource' => $resource,
                'relationship' => $relationship,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        $resInstance = new $relatedResourceClass($relatedModel);

        return JsonResponse::make(
            $this->serializeModel(
                $resInstance,
                Field::filterForContext($resInstance->fieldsForDetail($request), FieldContext::DETAIL),
                $relatedModel,
            ),
            meta: ['message' => $relatedResourceClass::createdMessage()],
        )->toResponse(201);
    }

    /**
     * Update an existing related record.
     */
    public function update(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
        int|string $relatedId,
    ): IlluminateJsonResponse {
        $context = $this->resolveContext($request, $resource, $id, $relationship, 'update');

        if ($context instanceof IlluminateJsonResponse) {
            return $context;
        }

        [
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
        ] = $context;

        $relatedModel = $relation->find($relatedId);

        if ($relatedModel === null) {
            return JsonErrorResponse::notFound('Related record not found.')->toResponse();
        }

        $relatedInstance = new $relatedResourceClass($relatedModel);

        if (! $relatedInstance->authorizedToUpdate($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $fields = Field::filterForContext($relatedInstance->fieldsForUpdate($request), FieldContext::UPDATE);

        foreach ($fields as $field) {
            if (method_exists($field, 'setUniqueIgnoreId')) {
                $field->setUniqueIgnoreId($relatedModel->getKey());
            }
        }

        $validationError = $this->validateRequest($request, $fields, isUpdate: true);
        if ($validationError !== null) {
            return $validationError;
        }

        $this->fillFields($request, $fields, $relatedModel);

        try {
            $relatedInstance->beforeSave($relatedModel, $request, creating: false);
            $relatedModel->save();
            $relatedInstance->afterSave($relatedModel, $request, creating: false);
        } catch (QueryException $e) {
            Log::error('Martis: MorphMany update error', [
                'resource' => $resource,
                'relationship' => $relationship,
                'relatedId' => $relatedId,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        $resInstance = new $relatedResourceClass($relatedModel);

        return JsonResponse::make(
            $this->serializeModel(
                $resInstance,
                Field::filterForContext($resInstance->fieldsForDetail($request), FieldContext::DETAIL),
                $relatedModel,
            ),
            meta: ['message' => $relatedResourceClass::updatedMessage()],
        )->toResponse();
    }

    /**
     * Delete a related record.
     */
    public function destroy(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
        int|string $relatedId,
    ): IlluminateJsonResponse {
        $context = $this->resolveContext($request, $resource, $id, $relationship, 'delete');

        if ($context instanceof IlluminateJsonResponse) {
            return $context;
        }

        [
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
        ] = $context;

        $relatedModel = $relation->find($relatedId);

        if ($relatedModel === null) {
            return JsonErrorResponse::notFound('Related record not found.')->toResponse();
        }

        $relatedInstance = new $relatedResourceClass($relatedModel);

        if (! $relatedInstance->authorizedToDelete($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        try {
            $relatedInstance->beforeDelete($relatedModel, $request);
            $relatedModel->delete();
            $relatedInstance->afterDelete($relatedModel, $request);
        } catch (QueryException $e) {
            Log::error('Martis: MorphMany delete error', [
                'resource' => $resource,
                'relationship' => $relationship,
                'relatedId' => $relatedId,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        return new IlluminateJsonResponse(
            ['data' => [], 'meta' => ['message' => $relatedResourceClass::deletedMessage()], 'links' => []],
            200,
        );
    }

    /**
     * Resolve all context needed for a MorphMany operation.
     *
     * @return array{parentModel: Model, parentResourceClass: class-string<resource>, relatedResourceClass: class-string<resource>, morphManyField: MorphMany, relation: EloquentMorphMany<Model, Model>}|IlluminateJsonResponse
     */
    private function resolveContext(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
        ?string $action = null,
    ): array|IlluminateJsonResponse {
        if (! $this->registry->has($resource)) {
            return JsonErrorResponse::notFound("Resource '{$resource}' not found.")->toResponse();
        }

        /** @var class-string<resource> $resourceClass */
        $resourceClass = $this->registry->get($resource);

        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();

        /** @phpstan-ignore staticMethod.notFound */
        $parentModel = $modelClass::find($id);

        if ($parentModel === null) {
            return JsonErrorResponse::notFound('Parent record not found.')->toResponse();
        }

        $parentInstance = new $resourceClass($parentModel);

        if (! $parentInstance->authorizedToView($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $fields = $parentInstance->fieldsForDetail($request);
        $morphManyField = null;

        foreach ($fields as $field) {
            if ($field instanceof MorphMany && $field->getRelationship() === $relationship) {
                $morphManyField = $field;
                break;
            }
        }

        if ($morphManyField === null) {
            return JsonErrorResponse::notFound("Relationship '{$relationship}' not found.")->toResponse();
        }

        if (! method_exists($parentModel, $relationship)) {
            return JsonErrorResponse::notFound("Relationship method '{$relationship}' does not exist.")->toResponse();
        }

        $relation = $parentModel->{$relationship}();

        if (! $relation instanceof EloquentMorphMany) {
            return JsonErrorResponse::notFound("'{$relationship}' is not a morphMany relationship.")->toResponse();
        }

        $relatedResourceKey = $morphManyField->getRelatedResourceKey();

        if ($relatedResourceKey === null || ! $this->registry->has($relatedResourceKey)) {
            return JsonErrorResponse::notFound('Related resource not registered.')->toResponse();
        }

        /** @var class-string<resource> $relatedResourceClass */
        $relatedResourceClass = $this->registry->get($relatedResourceKey);

        if ($action === 'create') {
            $relatedCheck = new $relatedResourceClass;
            if (! $relatedCheck->authorizedToCreate($request)) {
                return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
            }
        }

        return [
            'parentModel' => $parentModel,
            'parentResourceClass' => $resourceClass,
            'relatedResourceClass' => $relatedResourceClass,
            'morphManyField' => $morphManyField,
            'relation' => $relation,
        ];
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
            'softDeletes' => $resource::softDeletes(),
            'group' => $resource->group(),
        ];
        $data['_authorization'] = $resource->authorizationMetadata(request());

        foreach ($fields as $field) {
            $data[$field->attribute()] = $field->resolve($model);
        }

        if ($resource::softDeletes() && $model->getAttribute('deleted_at') !== null) {
            $data['deleted_at'] = $model->getAttribute('deleted_at');
        }

        return $data;
    }

    /**
     * Validate request against field rules.
     *
     * @param  list<FieldContract>  $fields
     */
    private function validateRequest(Request $request, array $fields, bool $isUpdate = false): ?IlluminateJsonResponse
    {
        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $fieldRules = $field->buildRules();

            if ($isUpdate) {
                $fieldRules = array_values(array_filter($fieldRules, fn (string|Rule $r): bool => is_string($r) && $r !== 'required'));
                if (empty($fieldRules)) {
                    $fieldRules = ['sometimes'];
                } elseif (! in_array('sometimes', $fieldRules, true)) {
                    array_unshift($fieldRules, 'sometimes');
                }
            }

            $rules[$field->attribute()] = $fieldRules;
            if ($field instanceof Field) {
                $attributes[$field->attribute()] = $field->label();
            }
        }

        $validator = Validator::make($request->all(), $rules, [], $attributes);

        if ($validator->fails()) {
            return JsonErrorResponse::validation(
                $validator->errors()->toArray(),
                'Validation failed.',
            )->toResponse();
        }

        return null;
    }

    /**
     * Fill fields from request data.
     *
     * @param  list<FieldContract>  $fields
     */
    private function fillFields(Request $request, array $fields, Model $model): void
    {
        foreach ($fields as $field) {
            $attr = $field->attribute();

            if ($field instanceof File && $field->isMultiple()) {
                $newFiles = [];
                $rawFiles = $request->file($attr);
                if (is_array($rawFiles)) {
                    $newFiles = $rawFiles;
                }

                $existingPaths = $request->input($attr.'_keep', []);
                if (! is_array($existingPaths)) {
                    $existingPaths = [];
                }

                if (! empty($newFiles) || $request->has($attr.'_keep') || $request->has($attr)) {
                    $field->fill($model, [
                        'files' => $newFiles,
                        'existing' => $existingPaths,
                    ]);
                }
            } elseif ($request->hasFile($attr)) {
                $field->fill($model, $request->file($attr));
            } elseif ($request->has($attr)) {
                $field->fill($model, $request->input($attr));
            }
        }
    }

    /**
     * Handle database exceptions.
     */
    private function handleDatabaseError(QueryException $e): IlluminateJsonResponse
    {
        $code = (string) ($e->errorInfo[1] ?? '');

        $message = match ($code) {
            '1048' => 'A required field is missing.',
            '1062' => 'A record with this value already exists.',
            '1364' => 'A required field was not provided.',
            '1451' => 'This record is referenced by other records.',
            '1452' => 'The referenced record does not exist.',
            default => 'A database error occurred.',
        };

        return JsonErrorResponse::serverError($message)->toResponse();
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  Builder<Model>  $query
     */
    private function applySorting(Request $request, Builder $query, string $resourceClass): void
    {
        $rawSort = $request->query('sort');
        $rawDirection = $request->query('direction', SortDirection::Asc->value);
        $direction = SortDirection::tryFrom(strtolower(is_string($rawDirection) ? $rawDirection : SortDirection::Asc->value))
            ?? SortDirection::Asc;

        if (! is_string($rawSort) || $rawSort === '') {
            return;
        }

        $sort = $rawSort;
        $instance = new $resourceClass;

        // Flatten Section / Panel / TabGroup before iterating —
        // otherwise the closure's `FieldContract` type hint trips on
        // layout nodes and sorting on related-model index requests
        // 500s. Mirrors the parent `ResourceController::applySorting`
        // fix.
        $flatFields = Field::flattenLayoutFields($instance->fields($request));

        $sortableAttributes = array_map(
            fn (FieldContract $field): string => $field->attribute(),
            array_values(array_filter(
                $flatFields,
                fn (FieldContract $field): bool => $field->isSortable(),
            )),
        );

        if (! in_array($sort, $sortableAttributes, true)) {
            return;
        }

        $query->orderBy($sort, $direction->value);
    }
}
