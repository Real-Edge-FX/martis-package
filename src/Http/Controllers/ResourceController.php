<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Martis\Contracts\FieldContract;
use Martis\Fields\File;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonPaginatedResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * Generic CRUD controller for all registered Martis resources.
 *
 * Routes:
 *   GET    /martis/api/{resource}                  → index (paginated, sortable, searchable)
 *   POST   /martis/api/{resource}                  → store
 *   GET    /martis/api/{resource}/{id}              → show
 *   PUT    /martis/api/{resource}/{id}              → update
 *   DELETE /martis/api/{resource}/{id}              → destroy (soft-delete when supported)
 *   PUT    /martis/api/{resource}/{id}/restore      → restore (soft-deleted record)
 *
 * All responses use the JsonResponse / JsonPaginatedResponse / JsonErrorResponse
 * envelopes defined in Bloco 2 (Contracts).
 */
class ResourceController extends MartisController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    // -------------------------------------------------------------------------
    // Index — GET /api/{resource}
    // -------------------------------------------------------------------------

    public function index(Request $request, string $resource): IlluminateJsonResponse
    {
        [$resourceClass, $error] = $this->resolveResource($resource);

        if ($error !== null) {
            return $error;
        }

        /** @var class-string<resource> $resourceClass */
        $instance = new $resourceClass;

        if (! $instance->authorizedToViewAny($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();

        /** @var Builder<Model> $query */
        $query = $modelClass::query();

        $this->applySearch($request, $query, $resourceClass);
        $this->applySorting($request, $query, $resourceClass);

        $perPage = min(
            (int) ($request->query('per_page', config('martis.pagination.default_per_page', 25))),
            (int) config('martis.pagination.max_per_page', 100),
        );

        $paginator = $query->paginate($perPage);

        /** @var list<array<string, mixed>> $data */
        $data = array_values(
            collect($paginator->items())->map(function (Model $model) use ($resourceClass, $request): array {
                $res = new $resourceClass($model);

                return $this->serializeModel($res, $res->fieldsForIndex($request), $model);
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

    // -------------------------------------------------------------------------
    // Show — GET /api/{resource}/{id}
    // -------------------------------------------------------------------------

    public function show(Request $request, string $resource, int|string $id): IlluminateJsonResponse
    {
        [$resourceClass, $error] = $this->resolveResource($resource);

        if ($error !== null) {
            return $error;
        }

        /** @var class-string<resource> $resourceClass */
        $model = $this->findModel($resourceClass, $id);

        if ($model === null) {
            return JsonErrorResponse::notFound()->toResponse();
        }

        $res = new $resourceClass($model);

        if (! $res->authorizedToView($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        return JsonResponse::make(
            $this->serializeModel($res, $res->fieldsForDetail($request), $model),
        )->toResponse();
    }

    // -------------------------------------------------------------------------
    // Store — POST /api/{resource}
    // -------------------------------------------------------------------------

    public function store(Request $request, string $resource): IlluminateJsonResponse
    {
        [$resourceClass, $error] = $this->resolveResource($resource);

        if ($error !== null) {
            return $error;
        }

        /** @var class-string<resource> $resourceClass */
        $instance = new $resourceClass;

        if (! $instance->authorizedToCreate($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        $model = $resourceClass::newModel();
        $res = new $resourceClass($model);
        $fields = $res->fieldsForForms($request);

        $validationError = $this->validateRequest($request, $fields, validationMessage: $resourceClass::validationMessage());
        if ($validationError !== null) {
            return $validationError;
        }

        $this->fillFields($request, $fields, $model);

        try {
            $res->beforeSave($model, $request, creating: true);
            $model->save();
            $res->afterSave($model, $request, creating: true);
        } catch (QueryException $e) {
            Log::error('Martis: database error on store', [
                'resource' => $resource,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        $res = new $resourceClass($model);

        return JsonResponse::make(
            $this->serializeModel($res, $res->fieldsForDetail($request), $model),
            meta: ['message' => $resourceClass::createdMessage()],
        )->toResponse(201);
    }

    // -------------------------------------------------------------------------
    // Update — PUT /api/{resource}/{id}
    // -------------------------------------------------------------------------

    public function update(Request $request, string $resource, int|string $id): IlluminateJsonResponse
    {
        [$resourceClass, $error] = $this->resolveResource($resource);

        if ($error !== null) {
            return $error;
        }

        /** @var class-string<resource> $resourceClass */
        $model = $this->findModel($resourceClass, $id);

        if ($model === null) {
            return JsonErrorResponse::notFound()->toResponse();
        }

        $res = new $resourceClass($model);

        if (! $res->authorizedToUpdate($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        $fields = $res->fieldsForForms($request);

        // Set unique-ignore ID so unique validation skips the current record
        foreach ($fields as $field) {
            if (method_exists($field, 'setUniqueIgnoreId')) {
                $field->setUniqueIgnoreId($model->getKey());
            }
        }

        $validationError = $this->validateRequest($request, $fields, isUpdate: true, validationMessage: $resourceClass::validationMessage());
        if ($validationError !== null) {
            return $validationError;
        }

        $this->fillFields($request, $fields, $model);

        try {
            $res->beforeSave($model, $request, creating: false);
            $model->save();
            $res->afterSave($model, $request, creating: false);
        } catch (QueryException $e) {
            Log::error('Martis: database error on update', [
                'resource' => $resource,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        $res = new $resourceClass($model);

        return JsonResponse::make(
            $this->serializeModel($res, $res->fieldsForDetail($request), $model),
            meta: ['message' => $resourceClass::updatedMessage()],
        )->toResponse();
    }

    // -------------------------------------------------------------------------
    // Destroy — DELETE /api/{resource}/{id}
    // -------------------------------------------------------------------------

    public function destroy(Request $request, string $resource, int|string $id): IlluminateJsonResponse
    {
        [$resourceClass, $error] = $this->resolveResource($resource);

        if ($error !== null) {
            return $error;
        }

        /** @var class-string<resource> $resourceClass */
        $model = $this->findModel($resourceClass, $id);

        if ($model === null) {
            return JsonErrorResponse::notFound()->toResponse();
        }

        $res = new $resourceClass($model);

        if (! $res->authorizedToDelete($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        try {
            $res->beforeDelete($model, $request);
            $this->deleteUploadedFiles($res->fieldsForDetail($request), $model);
            $model->delete();
            $res->afterDelete($model, $request);
        } catch (QueryException $e) {
            Log::error('Martis: database error on delete', [
                'resource' => $resource,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        return new IlluminateJsonResponse(['data' => [], 'meta' => ['message' => $resourceClass::deletedMessage()], 'links' => []], 200);
    }

    // -------------------------------------------------------------------------
    // Restore — PUT /api/{resource}/{id}/restore
    // -------------------------------------------------------------------------

    public function restore(Request $request, string $resource, int|string $id): IlluminateJsonResponse
    {
        [$resourceClass, $error] = $this->resolveResource($resource);

        if ($error !== null) {
            return $error;
        }

        /** @var class-string<resource> $resourceClass */
        if (! $resourceClass::softDeletes()) {
            return JsonErrorResponse::notFound('This resource does not support soft deletes.')->toResponse();
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();

        /** @phpstan-ignore-next-line */
        $model = $modelClass::withTrashed()->find($id);

        if ($model === null) {
            return JsonErrorResponse::notFound()->toResponse();
        }

        $res = new $resourceClass($model);

        if (! $res->authorizedToUpdate($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        $model->restore();

        $res = new $resourceClass($model);

        return JsonResponse::make(
            $this->serializeModel($res, $res->fieldsForDetail($request), $model),
            meta: ['message' => $resourceClass::restoredMessage()],
        )->toResponse();
    }

    // -------------------------------------------------------------------------
    // Schema — GET /api/{resource}/schema
    // -------------------------------------------------------------------------

    /**
     * Return the field schema for a resource (used by the React frontend
     * to know how to render each field).
     *
     * GET /martis/api/resources/{resource}/schema
     */
    public function schema(Request $request, string $resource): IlluminateJsonResponse
    {
        [$resourceClass, $error] = $this->resolveResource($resource);

        if ($error !== null) {
            return $error;
        }

        /** @var class-string<resource> $resourceClass */
        $instance = new $resourceClass;

        if (! $instance->authorizedToViewAny($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        $fields = $instance->fields($request);
        $fieldData = array_map(fn (FieldContract $field): array => $field->toArray(), $fields);

        $data = [
            'uriKey' => $resourceClass::uriKey(),
            'label' => $resourceClass::label(),
            'singularLabel' => $resourceClass::singularLabel(),
            'softDeletes' => $resourceClass::softDeletes(),
            'group' => $instance->group(),
            'icon' => $instance->icon(),
            'fields' => $fieldData,
            'messages' => [
                'created' => $resourceClass::createdMessage(),
                'updated' => $resourceClass::updatedMessage(),
                'deleted' => $resourceClass::deletedMessage(),
                'restored' => $resourceClass::restoredMessage(),
                'deleteConfirm' => $resourceClass::deleteConfirmMessage(),
                'archiveConfirm' => $resourceClass::archiveConfirmMessage(),
            ],
            'errorDisplay' => $resourceClass::errorDisplay(),
            'validationMessage' => $resourceClass::validationMessage(),
        ];

        return JsonResponse::make($data)->toResponse();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Handle a database exception and return a sanitized JSON error.
     *
     * Never expose raw SQL, credentials, or connection details to the client.
     */
    private function handleDatabaseError(QueryException $e): IlluminateJsonResponse
    {
        $code = (string) ($e->errorInfo[1] ?? '');

        $message = match ($code) {
            '1048' => 'A required field is missing. Please check all mandatory fields.',
            '1062' => 'A record with this value already exists. Please use a unique value.',
            '1364' => 'A required field was not provided. Please fill in all mandatory fields.',
            '1451' => 'This record cannot be modified because it is referenced by other records.',
            '1452' => 'The referenced record does not exist. Please check relationship fields.',
            default => 'A database error occurred. Please check your input and try again.',
        };

        return JsonErrorResponse::serverError($message)->toResponse();
    }

    /**
     * Fill all fields from request data, handling single and multiple file uploads.
     *
     * @param  list<FieldContract>  $fields
     */
    private function fillFields(Request $request, array $fields, Model $model): void
    {
        foreach ($fields as $field) {
            $attr = $field->attribute();

            if ($field instanceof File && $field->isMultiple()) {
                // Multiple file mode: gather new uploads + existing paths to keep
                $newFiles = [];
                $rawFiles = $request->file($attr);
                if (is_array($rawFiles)) {
                    $newFiles = $rawFiles;
                }

                $existingPaths = $request->input($attr.'_keep', []);
                if (! is_array($existingPaths)) {
                    $existingPaths = [];
                }

                // Only fill if something was actually submitted for this field
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
     * Delete stored files for all File/Image fields before model deletion.
     *
     * @param  list<FieldContract>  $fields
     */
    private function deleteUploadedFiles(array $fields, Model $model): void
    {
        foreach ($fields as $field) {
            if ($field instanceof File) {
                $field->deleteStoredFile($model);
            }
        }
    }

    /**
     * Resolve the resource class from the URI key.
     *
     * @return array{class-string<resource>|null, IlluminateJsonResponse|null}
     */
    private function resolveResource(string $uriKey): array
    {
        if (! $this->registry->has($uriKey)) {
            return [null, JsonErrorResponse::notFound("Resource '{$uriKey}' not found.")->toResponse()];
        }

        return [$this->registry->get($uriKey), null];
    }

    /**
     * Find a model by primary key.
     */
    private function findModel(string $resourceClass, int|string $id): ?Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();

        /** @phpstan-ignore staticMethod.notFound */
        return $modelClass::find($id);
    }

    /**
     * Apply search filter to the query.
     *
     * @param  class-string<resource>  $resourceClass
     * @param  Builder<Model>  $query
     */
    private function applySearch(Request $request, Builder $query, string $resourceClass): void
    {
        $rawSearch = $request->query('search', '');
        $search = trim(is_string($rawSearch) ? $rawSearch : '');

        if ($search === '') {
            return;
        }

        $instance = new $resourceClass;
        $searchableFields = array_filter(
            $instance->fields($request),
            fn (FieldContract $field): bool => method_exists($field, 'isSearchable') && $field->isSearchable(),
        );

        if (empty($searchableFields)) {
            return;
        }

        $query->where(function (Builder $q) use ($searchableFields, $search): void {
            foreach ($searchableFields as $field) {
                $q->orWhere($field->attribute(), 'like', "%{$search}%");
            }
        });
    }

    /**
     * Apply column sorting to the query.
     *
     * @param  class-string<resource>  $resourceClass
     * @param  Builder<Model>  $query
     */
    private function applySorting(Request $request, Builder $query, string $resourceClass): void
    {
        $rawSort = $request->query('sort');
        $rawDirection = $request->query('direction', 'asc');
        $direction = strtolower(is_string($rawDirection) ? $rawDirection : 'asc');

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        if (! is_string($rawSort) || $rawSort === '') {
            return;
        }

        $sort = $rawSort;
        $instance = new $resourceClass;
        $sortableAttributes = array_map(
            fn (FieldContract $field): string => $field->attribute(),
            array_values(array_filter(
                $instance->fields($request),
                fn (FieldContract $field): bool => method_exists($field, 'isSortable') && $field->isSortable(),
            )),
        );

        if (! in_array($sort, $sortableAttributes, true)) {
            return;
        }

        $query->orderBy($sort, $direction);
    }

    /**
     * Validate the incoming request against field rules.
     *
     * @param  list<FieldContract>  $fields
     */
    private function validateRequest(Request $request, array $fields, bool $isUpdate = false, ?string $validationMessage = null): ?IlluminateJsonResponse
    {
        $rules = [];

        foreach ($fields as $field) {
            if (! method_exists($field, 'buildRules')) {
                continue;
            }

            $fieldRules = $field->buildRules();

            if ($isUpdate) {
                // On update, fields are optional unless explicitly provided
                $fieldRules = array_values(array_filter($fieldRules, fn (string $r): bool => $r !== 'required'));
                if (empty($fieldRules)) {
                    $fieldRules = ['sometimes'];
                } elseif (! in_array('sometimes', $fieldRules, true)) {
                    array_unshift($fieldRules, 'sometimes');
                }
            }

            $rules[$field->attribute()] = $fieldRules;

            // Add item-level validation rules for multiple file fields
            if (method_exists($field, 'buildItemRules')) {
                $itemRules = $field->buildItemRules();
                if (! empty($itemRules)) {
                    $rules[$field->attribute().'.*'] = $itemRules;
                }
            }
        }

        if (empty($rules)) {
            return null;
        }

        // Collect custom validation messages from fields
        $customMessages = [];
        foreach ($fields as $field) {
            if (method_exists($field, 'validationMessages')) {
                $customMessages = array_merge($customMessages, $field->validationMessages());
            }
        }

        $validator = Validator::make($request->all(), $rules, $customMessages);

        if ($validator->fails()) {
            $msg = $validationMessage ?? 'The given data was invalid.';

            return JsonErrorResponse::validation($validator->errors()->toArray(), $msg)->toResponse();
        }

        return null;
    }

    /**
     * Serialize a single model to array using the given fields.
     *
     * @param  list<FieldContract>  $fields
     * @return array<string, mixed>
     */
    private function serializeModel(Resource $resource, array $fields, Model $model): array
    {
        /** @var array<string, mixed> $data */
        $data = ['id' => $model->getKey()];

        foreach ($fields as $field) {
            $data[$field->attribute()] = $field->resolve($model);
        }

        $data['_resource'] = $resource->toArray();

        return $data;
    }
}
