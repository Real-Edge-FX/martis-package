<?php

namespace Martis\Http\Controllers;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Martis\Contracts\ActionContract;
use Martis\Contracts\FieldContract;
use Martis\Contracts\FilterContract;
use Martis\FieldContext;
use Martis\Fields\BelongsTo;
use Martis\Fields\DeferredRelationSync;
use Martis\Fields\Field;
use Martis\Fields\File;
use Martis\Fields\MorphTo;
use Martis\Fields\Tag as TagField;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonPaginatedResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\RelationshipQueryResolver;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\SearchResolver;

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
 * envelopes defined in the contracts layer (see Contracts/).
 */
class ResourceController extends MartisController
{
    /** Create the controller and inject the resource registry. */
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    // -------------------------------------------------------------------------
    // Index — GET /api/{resource}
    // -------------------------------------------------------------------------

    /**
     * List resource records with pagination, sorting and search.
     *
     * Returns a paginated list of records for the specified resource.
     * Supports free-text search, column sorting and page size control.
     * Available search and sort fields depend on the Resource definition.
     *
     * Authentication: requires an authenticated session (Laravel session cookie).
     * The response includes an envelope with data, meta (pagination) and links (navigation).
     */
    #[QueryParameter('search', description: 'Filter records by free text. Searches all fields marked as searchable in the Resource.', required: false, type: 'string')]
    #[QueryParameter('per_page', description: 'Number of records per page. Maximum: 100. Default defined by the Resource (usually 15).', required: false, type: 'integer', example: 15)]
    #[QueryParameter('sort', description: 'Attribute name to sort by (e.g. "name", "created_at"). Must be a field marked as sortable in the Resource.', required: false, type: 'string')]
    #[QueryParameter('direction', description: 'Sort direction. Accepted values: "asc" (ascending) or "desc" (descending). Default: "asc".', required: false, type: 'string', example: 'asc')]
    #[QueryParameter('trashed', description: 'Soft-delete filter. Values: empty (active only), with (include trashed), only (trashed only).', required: false, type: 'string', example: 'with')]
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

        // Soft-delete filter — Nova v5 parity
        // ?trashed=with  → include soft-deleted alongside active records
        // ?trashed=only  → show only soft-deleted (trashed) records
        // default        → active records only (standard Eloquent behavior)
        if ($resourceClass::softDeletes() && $resourceClass::canViewTrashed()) {
            $trashed = $request->query('trashed', '');
            if ($trashed === 'with') {
                /** @phpstan-ignore-next-line — guarded by softDeletes() check above */
                $query = $modelClass::withTrashed();
            } elseif ($trashed === 'only') {
                /** @phpstan-ignore-next-line — guarded by softDeletes() check above */
                $query = $modelClass::onlyTrashed();
            }
        }

        // Apply indexQuery hook — Nova v5 parity
        $query = $resourceClass::indexQuery($request, $query);

        // Apply filters — Nova v5 parity
        $this->applyFilters($request, $query, $instance);

        $rawSearch = $request->query('search', '');
        $search = trim(is_string($rawSearch) ? $rawSearch : '');
        SearchResolver::apply($request, $query, $resourceClass, $search);
        $this->applySorting($request, $query, $resourceClass);

        $perPage = min(
            (int) ($request->query('per_page', (string) $resourceClass::perPage())),
            (int) config('martis.pagination.max_per_page', 100),
        );

        $paginator = $query->paginate($perPage);

        // Resolve actions once for per-row canRun authorization
        $actionsForAuth = $instance->actions($request);
        $actionsWithCanRun = array_filter($actionsForAuth, fn (ActionContract $a) => $a->authorizedToSee($request));

        /** @var list<array<string, mixed>> $data */
        $data = array_values(
            collect($paginator->items())->map(function (Model $model) use ($resourceClass, $request, $actionsWithCanRun): array {
                $res = new $resourceClass($model);

                $serialized = $this->serializeModel($res, Field::filterForContext($res->fieldsForIndex($request), FieldContext::INDEX), $model);

                // Per-action canRun authorization map
                $actionAuth = [];
                foreach ($actionsWithCanRun as $action) {
                    $actionAuth[$action->uriKey()] = $action->authorizedToRun($request, $model);
                }
                $serialized['_actionAuthorization'] = $actionAuth;

                return $serialized;
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

    /**
     * Retrieve a single resource record by primary key.
     *
     * Returns the full detail representation of a resource record.
     * Uses the fields defined in `fieldsForDetail()`, which may differ from the index view.
     *
     * Authentication: requires an authenticated session (Laravel session cookie).
     *
     * @param  string  $resource  Resource URI key (e.g. `users`, `products`)
     * @param  int|string  $id  Primary key of the record to retrieve
     *
     * @response array{data: array<string, mixed>, meta: array{message: string}, links: array<string, string>}
     */
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

        // Support ?context=update so edit forms get raw attribute values
        $context = $request->query('context', 'detail');
        if ($context === 'update') {
            $fields = Field::filterForContext($res->fieldsForUpdate($request), FieldContext::UPDATE);
        } elseif ($context === 'create') {
            $fields = Field::filterForContext($res->fieldsForCreate($request), FieldContext::CREATE);
        } else {
            $fields = Field::filterForContext($res->fieldsForDetail($request), FieldContext::DETAIL);
        }

        return JsonResponse::make(
            $this->serializeModel($res, $fields, $model),
        )->toResponse();
    }

    // -------------------------------------------------------------------------
    // Store — POST /api/{resource}
    // -------------------------------------------------------------------------

    /**
     * Create a new resource record.
     *
     * Validates the request against the resource's field rules and persists the record.
     * Triggers `beforeSave()` / `afterSave()` lifecycle hooks defined on the Resource.
     * Returns the newly created record using the detail representation.
     *
     * Authentication: requires an authenticated session (Laravel session cookie).
     *
     * @param  string  $resource  Resource URI key (e.g. `users`, `products`)
     *
     * @response 201 array{data: array<string, mixed>, meta: array{message: string}, links: array<string, string>}
     * @response 422 array{message: string, errors: array<string, list<string>>}
     */
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
        $fields = Field::filterForContext($res->fieldsForCreate($request), FieldContext::CREATE);

        $validationError = $this->validateRequest($request, $fields, validationMessage: $resourceClass::validationMessage());
        if ($validationError !== null) {
            return $validationError;
        }

        $this->fillFields($request, $fields, $model);

        try {
            $res->beforeSave($model, $request, creating: true);
            $model->save();
            $res->afterSave($model, $request, creating: true);
            $this->syncDeferredRelations($model);
        } catch (QueryException $e) {
            Log::error('Martis: database error on store', [
                'resource' => $resource,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        $res = new $resourceClass($model);

        return JsonResponse::make(
            $this->serializeModel($res, Field::filterForContext($res->fieldsForDetail($request), FieldContext::DETAIL), $model),
            meta: ['message' => $resourceClass::createdMessage()],
        )->toResponse(201);
    }

    // -------------------------------------------------------------------------
    // Update — PUT /api/{resource}/{id}
    // -------------------------------------------------------------------------

    /**
     * Update an existing resource record.
     *
     * Validates the request (all fields are optional on update) and persists the changes.
     * Unique validation automatically ignores the current record to avoid false conflicts.
     * Triggers `beforeSave()` / `afterSave()` lifecycle hooks defined on the Resource.
     *
     * Authentication: requires an authenticated session (Laravel session cookie).
     *
     * @param  string  $resource  Resource URI key (e.g. `users`, `products`)
     * @param  int|string  $id  Primary key of the record to update
     *
     * @response array{data: array<string, mixed>, meta: array{message: string}, links: array<string, string>}
     * @response 422 array{message: string, errors: array<string, list<string>>}
     */
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

        $fields = Field::filterForContext($res->fieldsForUpdate($request), FieldContext::UPDATE);

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
            $this->syncDeferredRelations($model);
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
            $this->serializeModel($res, Field::filterForContext($res->fieldsForDetail($request), FieldContext::DETAIL), $model),
            meta: ['message' => $resourceClass::updatedMessage()],
        )->toResponse();
    }

    // -------------------------------------------------------------------------
    // Destroy — DELETE /api/{resource}/{id}
    // -------------------------------------------------------------------------

    /**
     * Delete a resource record by primary key.
     *
     * Performs a soft delete when the Resource supports it (i.e. the underlying model uses `SoftDeletes`).
     * Otherwise performs a hard delete. Deletes any stored files associated with File/Image fields.
     * Triggers `beforeDelete()` / `afterDelete()` lifecycle hooks defined on the Resource.
     *
     * Authentication: requires an authenticated session (Laravel session cookie).
     *
     * @param  string  $resource  Resource URI key (e.g. `users`, `products`)
     * @param  int|string  $id  Primary key of the record to delete
     *
     * @response array{data: array<mixed>, meta: array{message: string}, links: array<string, string>}
     */
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
            $this->deleteUploadedFiles(Field::filterForContext($res->fieldsForDetail($request), FieldContext::DETAIL), $model);
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

    /**
     * Restore a soft-deleted resource record.
     *
     * Only available for resources whose model uses `SoftDeletes`.
     * Returns 404 when the resource does not support soft deletes or the record is not found.
     *
     * Authentication: requires an authenticated session (Laravel session cookie).
     *
     * @param  string  $resource  Resource URI key (e.g. `users`, `products`)
     * @param  int|string  $id  Primary key of the record to restore
     *
     * @response array{data: array<string, mixed>, meta: array{message: string}, links: array<string, string>}
     */
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

        /** @phpstan-ignore staticMethod.notFound */
        $model = $modelClass::withTrashed()->find($id);

        if ($model === null) {
            return JsonErrorResponse::notFound()->toResponse();
        }

        $res = new $resourceClass($model);

        if (! $res->authorizedToRestore($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        $model->restore();

        $res = new $resourceClass($model);

        return JsonResponse::make(
            $this->serializeModel($res, Field::filterForContext($res->fieldsForDetail($request), FieldContext::DETAIL), $model),
            meta: ['message' => $resourceClass::restoredMessage()],
        )->toResponse();
    }

    // -------------------------------------------------------------------------
    // Force Delete — DELETE /api/{resource}/{id}/force
    // -------------------------------------------------------------------------

    /**
     * Permanently delete a soft-deleted resource record.
     *
     * Only available for resources whose model uses `SoftDeletes`.
     * Unlike destroy(), this bypasses the trash and removes the record permanently.
     *
     * @param  string  $resource  Resource URI key
     * @param  int|string  $id  Primary key of the record
     */
    public function forceDelete(Request $request, string $resource, int|string $id): IlluminateJsonResponse
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

        /** @phpstan-ignore staticMethod.notFound */
        $model = $modelClass::withTrashed()->find($id);

        if ($model === null) {
            return JsonErrorResponse::notFound()->toResponse();
        }

        $res = new $resourceClass($model);

        if (! $res->authorizedToForceDelete($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        try {
            $res->beforeDelete($model, $request);
            $this->deleteUploadedFiles(Field::filterForContext($res->fieldsForDetail($request), FieldContext::DETAIL), $model);
            $model->forceDelete();
            $res->afterDelete($model, $request);
        } catch (QueryException $e) {
            Log::error('Martis: database error on forceDelete', [
                'resource' => $resource,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        return new IlluminateJsonResponse(
            ['data' => [], 'meta' => ['message' => $resourceClass::forceDeletedMessage()], 'links' => []],
            200,
        );
    }

    /**
     * Get replicated field values for pre-filling the create form.
     *
     * Returns the field values from a replicated model WITHOUT saving to the database.
     * Used by the frontend to pre-populate the create form when replicating a resource.
     * Nova v5 parity: the user passes through an editable form before saving.
     *
     * @param  string  $resource  Resource URI key
     * @param  int|string  $id  Record ID to replicate
     */
    public function replicateFields(Request $request, string $resource, int|string $id): IlluminateJsonResponse
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

        if (! $res->authorizedToReplicate($request)) {
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
        }

        // Create in-memory replica using Eloquent replicate() — does NOT save to DB
        $replica = $model->replicate();

        // Resolve fields for create context and extract current values from the replica
        $resReplica = new $resourceClass($replica);
        $fields = Field::filterForContext($resReplica->fieldsForCreate($request), FieldContext::CREATE);
        $values = [];
        foreach ($fields as $field) {
            // Skip file fields — files cannot be replicated (Nova v5 limitation)
            if ($field instanceof File) {
                continue;
            }
            $resolved = $field->resolve($replica);
            $values[$field->attribute()] = $resolved;
        }

        return JsonResponse::make([
            'values' => $values,
            'fromResourceId' => $id,
        ])->toResponse();
    }

    // -------------------------------------------------------------------------
    // Inline Create — GET /api/{resource}/inline-create-schema, POST /api/{resource}/inline-create
    // -------------------------------------------------------------------------

    /**
     * Return the field schema for inline creation of a resource.
     *
     * Used when creating a related record inline from a relationship field (e.g. BelongsTo).
     * Returns fieldsForInlineCreate if defined, otherwise falls back to fieldsForCreate.
     * Nova v5 parity: inline create only supports one level of depth.
     *
     * @param  string  $resource  Resource URI key
     */
    public function inlineCreateSchema(Request $request, string $resource): IlluminateJsonResponse
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

        // Use fieldsForInlineCreate (falls back to fieldsForCreate -> fields)
        $fields = Field::filterForContext($instance->fieldsForInlineCreate($request), FieldContext::INLINE_CREATE);

        // Strip showCreateRelationButton from BelongsTo fields to prevent nesting (max 1 level deep)
        $fieldData = array_map(function (FieldContract $f): array {
            $arr = $f->toArray();
            if (($arr['type'] ?? '') === 'belongs_to' || ($arr['type'] ?? '') === 'morph_to') {
                $arr['showCreateRelationButton'] = false;
            }

            return $arr;
        }, $fields);

        return JsonResponse::make([
            'fields' => $fieldData,
            'singularLabel' => $resourceClass::singularLabel(),
            'label' => $resourceClass::label(),
            'icon' => $instance->icon(),
            'iconColor' => $instance->iconColor(),
            'subtitle' => $resourceClass::subtitle(),
        ])->toResponse();
    }

    /**
     * Store a new resource record created via inline create modal.
     *
     * Same as store() but uses fieldsForInlineCreate context for field resolution.
     * Returns the new record in a format suitable for immediate selection in the
     * originating relationship field.
     *
     * @param  string  $resource  Resource URI key
     */
    public function inlineCreateStore(Request $request, string $resource): IlluminateJsonResponse
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

        // Block nested inline create (Nova v5: only one level deep)
        if ($request->header('X-Martis-Inline-Create-Depth', '0') !== '0') {
            return JsonErrorResponse::validation([], 'Inline create only supports one level of depth.')->toResponse();
        }

        $model = $resourceClass::newModel();
        $res = new $resourceClass($model);
        $fields = Field::filterForContext($res->fieldsForInlineCreate($request), FieldContext::INLINE_CREATE);

        $validationError = $this->validateRequest($request, $fields, validationMessage: $resourceClass::validationMessage());
        if ($validationError !== null) {
            return $validationError;
        }

        $this->fillFields($request, $fields, $model);

        try {
            $res->beforeSave($model, $request, creating: true);
            $model->save();
            $res->afterSave($model, $request, creating: true);
            $this->syncDeferredRelations($model);
        } catch (QueryException $e) {
            Log::error('Martis: database error on inline create', [
                'resource' => $resource,
                'error' => $e->getMessage(),
            ]);

            return $this->handleDatabaseError($e);
        }

        // Determine the title attribute for the newly created record
        $titleAttr = $resourceClass::titleAttribute();
        $title = $model->getAttribute($titleAttr);

        return JsonResponse::make([
            'id' => $model->getKey(),
            'title' => $title,
        ], meta: ['message' => $resourceClass::createdMessage()])->toResponse(201);
    }

    // -------------------------------------------------------------------------
    // Schema — GET /api/{resource}/schema
    // -------------------------------------------------------------------------

    /**
     * Return the field schema for a resource.
     *
     * Used by the React frontend to determine how to render each field (type, label,
     * validation rules, options for selects, etc.). Also returns resource metadata
     * such as labels, soft-delete support, icon and per-page options.
     *
     * Authentication: requires an authenticated session (Laravel session cookie).
     *
     * @param  string  $resource  Resource URI key (e.g. `users`, `products`)
     *
     * @response array{data: array<string, mixed>, meta: array{message: string}, links: array<string, string>}
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

        // Context-specific field arrays — resolved then filtered by visibility
        $fieldsForIndex = array_map(fn (FieldContract $f): array => $f->toArray(), Field::filterForContext($instance->fieldsForIndex($request), FieldContext::INDEX));
        $fieldsForDetail = array_map(fn ($item): array => $item->toArray(), Field::filterLayoutForContext($instance->fieldsForDetail($request), FieldContext::DETAIL));
        $fieldsForCreate = array_map(fn ($item): array => $item->toArray(), Field::filterLayoutForContext($instance->fieldsForCreate($request), FieldContext::CREATE));
        $fieldsForUpdate = array_map(fn ($item): array => $item->toArray(), Field::filterLayoutForContext($instance->fieldsForUpdate($request), FieldContext::UPDATE));
        $fieldsForInlineCreate = array_map(fn (FieldContract $f): array => $f->toArray(), Field::filterForContext($instance->fieldsForInlineCreate($request), FieldContext::INLINE_CREATE));
        $fieldsForPreview = array_map(fn (FieldContract $f): array => $f->toArray(), Field::filterForContext($instance->fieldsForPreview($request), FieldContext::PREVIEW));
        $filters = $this->serializeFilters($instance->filters($request), $request);
        $lenses = $this->serializeSchemaDescriptors($instance->lenses($request));
        $cards = $this->serializeSchemaDescriptors($instance->cards($request));
        $dashboards = $this->serializeSchemaDescriptors($resourceClass::dashboards());

        // Serialize actions inline so the frontend doesn't need a second
        // round-trip to /api/resources/{res}/actions — this eliminates the
        // "inline actions flash" where they disappear briefly on refresh.
        $actions = array_values(array_map(
            fn (ActionContract $a) => $a->jsonSerialize(),
            array_filter(
                $instance->actions($request),
                fn (ActionContract $a) => $a->authorizedToSee($request),
            ),
        ));

        $data = [
            'uriKey' => $resourceClass::uriKey(),
            'label' => $resourceClass::label(),
            'singularLabel' => $resourceClass::singularLabel(),
            'subtitle' => $resourceClass::subtitle(),
            'softDeletes' => $resourceClass::softDeletes() && $resourceClass::canViewTrashed(),
            'authorization' => $instance->collectionAuthorizationMetadata($request),
            'group' => $instance->group(),
            'icon' => $instance->icon(),
            'iconColor' => $instance->iconColor(),
            'titleAttribute' => $resourceClass::titleAttribute(),
            'indexSearchable' => $resourceClass::indexSearchable(),
            'usesScout' => $resourceClass::usesScout(),
            'perPageOptions' => $resourceClass::perPageOptions(),
            'perPage' => $resourceClass::perPage(),
            'searchPlaceholder' => $resourceClass::searchPlaceholder(),
            'fields' => $fieldData,
            'fieldsForIndex' => $fieldsForIndex,
            'fieldsForDetail' => $fieldsForDetail,
            'fieldsForCreate' => $fieldsForCreate,
            'fieldsForUpdate' => $fieldsForUpdate,
            'fieldsForInlineCreate' => $fieldsForInlineCreate,
            'fieldsForPreview' => $fieldsForPreview,
            'filters' => $filters,
            'lenses' => $lenses,
            'cards' => $cards,
            'dashboards' => $dashboards,
            'messages' => [
                'created' => $resourceClass::createdMessage(),
                'updated' => $resourceClass::updatedMessage(),
                'deleted' => $resourceClass::deletedMessage(),
                'restored' => $resourceClass::restoredMessage(),
                'forceDeleted' => $resourceClass::forceDeletedMessage(),
                'replicated' => $resourceClass::replicatedMessage(),
                'deleteConfirm' => $resourceClass::deleteConfirmMessage(),
                'archiveConfirm' => $resourceClass::archiveConfirmMessage(),
                'forceDeleteConfirm' => $resourceClass::forceDeleteConfirmMessage(),
            ],
            'errorDisplay' => $resourceClass::errorDisplay()->value,
            'actionsMenuLabel' => $resourceClass::actionsMenuLabel(),
            'bulkActionsMenuLabel' => $resourceClass::bulkActionsMenuLabel(),
            'validationMessage' => $resourceClass::validationMessage(),
            'overrides' => $instance->overrides(),
            'defaultRowActions' => $instance->resolveDefaultRowActions($request),
            'rowClickOpensDetail' => $instance->resolveRowClickOpensDetail($request),
            'actions' => $actions,
        ];

        return JsonResponse::make($data)->toResponse();
    }

    /**
     * Serialize filter instances for the schema endpoint.
     *
     * Calls resolveForSchema() on each filter to pre-resolve options
     * before calling toArray().
     *
     * @param  array<int, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function serializeFilters(array $filters, Request $request): array
    {
        $result = [];

        foreach ($filters as $filter) {
            // Skip filters the user is not authorized to see (Martis extension)
            if ($filter instanceof \Martis\Filters\Filter) {
                if (! $filter->authorizedToSee($request)) {
                    continue;
                }

                $filter->resolveForSchema($request);
                $result[] = $filter->toArray();

                continue;
            }

            if (is_array($filter)) {
                /** @var array<string, mixed> $filter */
                $result[] = $filter;

                continue;
            }

            if (is_object($filter) && method_exists($filter, 'toArray')) {
                $serialized = $filter->toArray();
                $result[] = is_array($serialized) ? $serialized : ['value' => $filter];

                continue;
            }

            $result[] = ['value' => $filter];
        }

        return array_values($result);
    }

    /**
     * Normalize schema descriptors to plain arrays.
     *
     * Accepts plain arrays or lightweight descriptor objects that expose
     * a toArray() method. This keeps task-1 hooks flexible while the full
     * implementations are still pending.
     *
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function serializeSchemaDescriptors(array $items): array
    {
        return array_values(array_map(function (mixed $item): array {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                return $item;
            }

            if (is_object($item) && method_exists($item, 'toArray')) {
                $serialized = $item->toArray();

                if (is_array($serialized)) {
                    /** @var array<string, mixed> $serialized */
                    return $serialized;
                }
            }

            return ['value' => $item];
        }, $items));
    }

    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Relatable — GET /api/{resource}/{id}/relatable/{field}
    // -------------------------------------------------------------------------

    /**
     * Return filtered options for a relationship field.
     *
     * Applies relatable query hooks (relatable{PluralModelName} or relatableQuery)
     * so that relationship selectors respect server-side filtering.
     *
     * Nova v5 parity: relationship option endpoint with query hooks.
     *
     * Query params: search, per_page (default 20, max 100).
     */
    public function relatableOptions(
        Request $request,
        string $resource,
        string $id,
        string $fieldAttr,
    ): IlluminateJsonResponse {
        // Support context-free relatable calls (resource='_') with related_resource param.
        // This allows relationship selectors to always use the relatable endpoint,
        // even without source resource context (e.g. standalone forms).
        $hasSourceContext = $resource !== '_';

        /** @var class-string<resource>|null $resourceClass */
        $resourceClass = null;
        $relationField = null;
        $relatedUriKey = null;

        if ($hasSourceContext) {
            [$resourceClass, $error] = $this->resolveResource($resource);

            if ($error !== null) {
                return $error;
            }

            /** @var class-string<resource> $resourceClass */
            $instance = new $resourceClass;

            if (! $instance->authorizedToViewAny($request)) {
                return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
            }

            // Find the relationship field in the resource fields
            $fields = $instance->fields($request);
            foreach ($fields as $field) {
                if ($field->attribute() === $fieldAttr) {
                    $relationField = $field;
                    break;
                }
            }

            if ($relationField === null) {
                return JsonErrorResponse::notFound("Field '{$fieldAttr}' not found.")->toResponse();
            }

            // Determine the related resource class from the field
            if ($relationField instanceof BelongsTo) {
                $relatedUriKey = $this->getRelatedResourceKey($relationField);
            } elseif ($relationField instanceof MorphTo) {
                // MorphTo: resolve from related_resource query param (type-specific)
                $rawRelated = $request->query('related_resource', '');
                $relatedUriKey = is_string($rawRelated) ? $rawRelated : null;
            } elseif ($relationField instanceof TagField) {
                $relatedUriKey = $relationField->getRelatedResource();
            }
        } else {
            // No source context - resolve related resource from query param
            $rawRelated = $request->query('related_resource', '');
            $relatedUriKey = is_string($rawRelated) ? $rawRelated : null;
        }

        // Auth check: ensure user can viewAny the related resource (F-2 fix)
        if ($relatedUriKey !== null && $this->registry->has($relatedUriKey)) {
            $relatedCheck = new ($this->registry->get($relatedUriKey));
            if (! $relatedCheck->authorizedToViewAny($request)) {
                return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
            }
        }

        if ($relatedUriKey === null || ! $this->registry->has($relatedUriKey)) {
            return JsonErrorResponse::notFound('Related resource not found.')->toResponse();
        }

        /** @var class-string<resource> $relatedResourceClass */
        $relatedResourceClass = $this->registry->get($relatedUriKey);

        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relatedResourceClass::model();

        /** @var Builder<Model> $query */
        $query = $relatedModelClass::query();

        // Apply relatable query hooks via central resolver
        if ($hasSourceContext && $resourceClass !== null) {
            $query = RelationshipQueryResolver::resolve(
                $resourceClass,
                $relatedResourceClass,
                $request,
                $query,
                $relationField,
            );
        } else {
            // No source context - apply only the target's generic relatableQuery
            $query = $relatedResourceClass::relatableQuery($request, $query);
        }

        // Apply search if provided
        $rawSearch = $request->query('search', '');
        $search = trim(is_string($rawSearch) ? $rawSearch : '');

        if ($search !== '') {
            $relatedInstance = new $relatedResourceClass;
            $searchableFields = array_filter(
                $relatedInstance->fields($request),
                fn (FieldContract $field): bool => $field->isSearchable(),
            );

            if (empty($searchableFields)) {
                $titleAttr = $relatedResourceClass::titleAttribute();
                if ($titleAttr !== 'id') {
                    $query->where($titleAttr, 'like', "%{$search}%");
                }
            } else {
                $query->where(function (Builder $q) use ($searchableFields, $search): void {
                    foreach ($searchableFields as $field) {
                        $q->orWhere($field->attribute(), 'like', "%{$search}%");
                    }
                });
            }
        }

        $perPage = min(
            (int) ($request->query('per_page', '20')),
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
     * Extract the related resource URI key from a BelongsTo field.
     */
    private function getRelatedResourceKey(BelongsTo $field): ?string
    {
        $reflection = new \ReflectionProperty($field, 'relatedUriKey');

        return $reflection->getValue($field);
    }

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

        // Unique constraint violations are validation errors — return 422 with field mapping
        if ($code === '1062') {
            $field = null;
            $errorDetail = $e->errorInfo[2] ?? '';
            // MySQL: "Duplicate entry 'val' for key 'table.table_column_unique'"
            if (preg_match("/for key '(?:[^.]+\.)?(?:\w+_)?(\w+)_unique'/", $errorDetail, $m)) {
                $field = $m[1];
            }
            if ($field) {
                return JsonErrorResponse::validation([$field => [$message]], $message)->toResponse();
            }

            return JsonErrorResponse::validation([], $message)->toResponse();
        }

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

        // Include soft-deleted records so detail pages work for archived records
        if ($resourceClass::softDeletes()) {
            /** @phpstan-ignore-next-line — guarded by softDeletes() check */
            return $modelClass::withTrashed()->find($id);
        }

        /** @phpstan-ignore staticMethod.notFound */
        return $modelClass::find($id);
    }

    /**
     * Apply column sorting to the query.
     *
     * @param  class-string<resource>  $resourceClass
     * @param  Builder<Model>  $query
     */
    /**
     * Apply user-selected filters to the index query.
     *
     * Reads the `filters` query parameter (JSON-encoded object mapping
     * filter URI keys to their selected values) and calls apply()
     * on each matching filter instance.
     *
     * @param  Builder<Model>  $query
     */
    private function applyFilters(Request $request, Builder $query, Resource $instance): void
    {
        $rawFilters = $request->query('filters', '');

        if (! is_string($rawFilters) || $rawFilters === '') {
            return;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($rawFilters, true);

        if (! is_array($decoded) || $decoded === []) {
            return;
        }

        $filterInstances = $instance->filters($request);

        foreach ($filterInstances as $filter) {
            if (! $filter instanceof FilterContract) {
                continue;
            }

            // Skip unauthorized filters (Martis extension)
            if (! $filter->authorizedToSee($request)) {
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
    }

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
                fn (FieldContract $field): bool => $field->isSortable(),
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
            $fieldRules = $field->buildRules();

            if ($isUpdate) {
                // On update, fields are optional unless explicitly provided
                $fieldRules = array_values(array_filter($fieldRules, fn (string|Rule $r): bool => is_string($r) && $r !== 'required'));
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
     * Sync deferred many-to-many relationships after model save.
     *
     * BelongsTo fields in multiple mode register pending syncs during fill().
     * This method executes them after the model has been persisted.
     */

    // -------------------------------------------------------------------------
    // Peek — GET /api/resources/{resource}/{id}/peek
    // -------------------------------------------------------------------------

    /**
     * Return a compact peek card payload for the given resource record.
     *
     * Used by BelongsTo and MorphTo frontend components to load peek content
     * lazily when the user hovers the preview icon. Content is derived from
     * fieldsForPreview() on the related resource — aligned with the resource's
     * own field definitions, not a custom column list on the field.
     *
     * Nova v5 parity: peek content comes from the resource (fieldsForPreview),
     * not from a custom peekColumns() list defined on the BelongsTo field.
     *
     * @response array{data: array{title: string, attributes: list<array{label: string, value: mixed}>}}
     */
    public function peek(Request $request, string $resource, int|string $id): IlluminateJsonResponse
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

        $fields = Field::filterForContext($res->fieldsForPreview($request), FieldContext::PREVIEW);

        $attributes = [];
        foreach ($fields as $field) {
            /** @var FieldContract&Field $fieldInstance */
            $fieldInstance = $field;
            $value = $fieldInstance->resolveForDisplay($model);
            if ($value === null) {
                continue;
            }
            $attributes[] = [
                'label' => $field->label(),
                'value' => $value,
            ];
        }

        return JsonResponse::make([
            'title' => $res->title(),
            'attributes' => $attributes,
        ])->toResponse();
    }

    private function syncDeferredRelations(Model $model): void
    {
        DeferredRelationSync::sync($model);
    }

    /**
     * Serialize a model into an array of attribute values using its fields.
     *
     * @param  list<FieldContract>  $fields
     * @param  bool  $forDisplay  When true, uses resolveForDisplay() which applies displayUsing() callbacks
     * @return array<string, mixed>
     */
    private function serializeModel(Resource $resource, array $fields, Model $model, bool $forDisplay = true): array
    {
        /** @var array<string, mixed> $data */
        $data = ['id' => $model->getKey()];

        foreach ($fields as $field) {
            if ($forDisplay) {
                /** @var FieldContract&Field $fieldInstance */
                $fieldInstance = $field;
                $data[$field->attribute()] = $fieldInstance->resolveForDisplay($model);
            } else {
                $data[$field->attribute()] = $field->resolve($model);
            }
        }

        $data['_title'] = $resource->title();
        $data['_resource'] = $resource->toArray();
        $data['_authorization'] = $resource->authorizationMetadata(request());

        // Include deleted_at for soft-delete resources so the frontend can show Archived badge
        if ($resource::softDeletes() && $model->getAttribute('deleted_at') !== null) {
            $data['deleted_at'] = $model->getAttribute('deleted_at');
        }

        return $data;
    }
}
