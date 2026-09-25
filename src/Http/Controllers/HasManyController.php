<?php

namespace Martis\Http\Controllers;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EloquentHasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Martis\Contracts\FieldContract;
use Martis\Enums\TrashedFilter;
use Martis\FieldContext;
use Martis\Fields\Field;
use Martis\Fields\File;
use Martis\Fields\HasMany;
use Martis\Fields\HasManyThrough as HasManyThroughField;
use Martis\Http\Controllers\Concerns\BuildsFieldRules;
use Martis\Http\Controllers\Concerns\DecodesStructuredValues;
use Martis\Http\Controllers\Concerns\SyncsDeferredWrites;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonPaginatedResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Rules\RelatableWrite;
use Martis\SearchResolver;

/**
 * Controller for HasMany relationship CRUD operations.
 *
 * Endpoints:
 *   GET    /api/resources/{resource}/{id}/has-many/{relationship}                → index
 *   POST   /api/resources/{resource}/{id}/has-many/{relationship}                → store
 *   PUT    /api/resources/{resource}/{id}/has-many/{relationship}/{relatedId}    → update
 *   DELETE /api/resources/{resource}/{id}/has-many/{relationship}/{relatedId}    → destroy
 */
class HasManyController extends MartisController
{
    use BuildsFieldRules;
    use DecodesStructuredValues;
    use SyncsDeferredWrites;

    /** Create the controller and inject the resource registry. */
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * List related records for a HasMany relationship.
     *
     * Returns a paginated list of related records with support for search,
     * sorting, and pagination. Uses the related resource's field definitions
     * and search pipeline (including Scout when applicable).
     */
    #[QueryParameter('search', description: 'Filter related records by free text, on the searchable fields of the related resource the user can see.', required: false, type: 'string')]
    #[QueryParameter('per_page', description: 'Records per page, from 1 to 100. Default: 10.', required: false, type: 'integer')]
    #[QueryParameter('sort', description: 'Attribute to sort by: a sortable field of the related resource the user can see; any other value is ignored.', required: false, type: 'string')]
    #[QueryParameter('direction', description: 'Sort direction: asc or desc (asc for any other value).', required: false, type: 'string')]
    #[QueryParameter('trashed', description: 'Soft-delete filter. Values: empty (active only), with (include trashed), only (trashed only); any other value means active only.', required: false, type: 'string')]
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
            $trashed = TrashedFilter::fromQuery($request->query('trashed'));
            if ($trashed === TrashedFilter::With) {
                /** @phpstan-ignore-next-line — guarded by softDeletes() check above */
                $query->withTrashed();
            } elseif ($trashed === TrashedFilter::Only) {
                /** @phpstan-ignore-next-line — guarded by softDeletes() check above */
                $query->onlyTrashed();
            }
        }

        // The related resource's scopes() and indexQuery() hide rows here as
        // on its index (tenancy, visibility), as Nova's relationship index.
        $this->scopeRelationQuery($request, $query, $relatedResourceClass, byKey: $relation instanceof EloquentHasManyThrough);

        // Apply search using the related resource's search pipeline
        $rawSearch = $request->query('search', '');
        $search = trim(is_string($rawSearch) ? $rawSearch : '');

        if ($search !== '') {
            // Qualified only through a hasManyThrough, whose intermediate may
            // share a column; a plain hasMany may search a column of a table
            // its relation joins, which the related table does not have.
            SearchResolver::apply($request, $query, $relatedResourceClass, $search, qualifyColumns: $relation instanceof HasOneOrManyThrough);
        }

        // Only a sortable field of the related resource the user can see
        // orders the rows.
        $this->applyRequestedSort($request, $query, $relatedResourceClass, qualifyJsonPaths: $relation instanceof HasOneOrManyThrough);

        // The relationship counts of the related rows' index columns, scoped
        // and aggregated in this query.
        $this->withScopedRelationCounts($request, $query, Field::filterForContext((new $relatedResourceClass)->fieldsForIndex($request), FieldContext::INDEX));

        // Pagination
        $perPage = $this->requestedPerPage($request, 10);

        // Paginate through the relation, not its bare query: a hasManyThrough
        // selects only the related table's columns (plus its through key)
        // there. The bare query selected both tables of the join, so the
        // intermediate's id, timestamps and deleted_at overwrote the record's.
        $paginator = $relation->paginate($perPage);

        // The panel offers the related resource's inline row actions, as
        // the resource index does, so each row carries whether each may run
        // on it (its inline actions).
        $actionAuthorization = $this->rowActionAuthorizer($request, $relatedResourceClass, inlineOnly: true);

        /** @var list<array<string, mixed>> $data */
        $data = array_values(
            collect($paginator->items())->map(function (Model $model) use ($relatedResourceClass, $request, $actionAuthorization): array {
                $res = new $relatedResourceClass($model);

                $row = $this->serializeModel(
                    $res,
                    Field::filterForContext($res->fieldsForIndex($request), FieldContext::INDEX),
                    $model,
                );

                if ($actionAuthorization !== null) {
                    $row['_actionAuthorization'] = $actionAuthorization($model, is_array($row['_authorization'] ?? null) ? $row['_authorization'] : []);
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
     * Create a new related record in the context of the parent.
     *
     * Automatically sets the foreign key to link the new record to the parent.
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

        // Check parent resource authorizedToAdd for this related model class
        $parentInstance = new $resourceClass($parentModel);
        /** @var class-string<Model> $relatedModelClass */
        $relatedModelClass = $relatedResourceClass::model();
        if (! $parentInstance->authorizedToAdd($request, $relatedModelClass)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $relatedInstance = new $relatedResourceClass;
        $relatedModel = $relatedResourceClass::newModel();
        // A field hidden for the new record (canSeeForModel(), decided on the
        // unsaved model before any value is written) is neither validated
        // nor written, as on the resource's own create.
        $fields = Field::filterForModel(
            Field::filterForContext($relatedInstance->fieldsForCreate($request), FieldContext::CREATE),
            $request,
            $relatedModel,
        );

        // The store writes the parent key itself after the fill, so an
        // inverse relationship field the form sends for the parent writes
        // nothing and is not checked against its picker.
        $validationError = $this->validateRequest($request, $fields, relatable: new RelatableWrite($request, $relatedResourceClass, $relatedModel, [$relation->getForeignKeyName()]));
        if ($validationError !== null) {
            return $validationError;
        }

        $this->fillFields($request, $fields, $relatedModel);

        // Set the foreign key to the parent
        $relatedModel->setAttribute(
            $relation->getForeignKeyName(),
            $parentModel->getKey(),
        );

        try {
            $relatedInstance = new $relatedResourceClass($relatedModel);
            $relatedInstance->beforeSave($relatedModel, $request, creating: true);
            $relatedModel->save();
            $relatedInstance->afterSave($relatedModel, $request, creating: true);
            $this->syncDeferredWrites($relatedModel);
        } catch (QueryException $e) {
            Log::error('Martis: HasMany store error', [
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
                Field::filterForContext($resInstance->resolveDetailFields($request), FieldContext::DETAIL),
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

        // Find the related model within the relationship scope
        $relatedModel = $relation->find($relatedId);

        if ($relatedModel === null) {
            return JsonErrorResponse::notFound('Related record not found.')->toResponse();
        }

        $relatedInstance = new $relatedResourceClass($relatedModel);

        if (! $relatedInstance->authorizedToUpdate($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        // A field hidden for the related record (canSeeForModel()) is neither
        // validated nor written, as on the resource's own update.
        $fields = Field::filterForModel(
            Field::filterForContext($relatedInstance->fieldsForUpdate($request), FieldContext::UPDATE),
            $request,
            $relatedModel,
        );

        // Set unique-ignore ID
        foreach ($fields as $field) {
            if (method_exists($field, 'setUniqueIgnoreId')) {
                $field->setUniqueIgnoreId($relatedModel->getKey());
            }
        }

        $validationError = $this->validateRequest($request, $fields, isUpdate: true, model: $relatedModel, relatable: new RelatableWrite($request, $relatedResourceClass, $relatedModel));
        if ($validationError !== null) {
            return $validationError;
        }

        $this->fillFields($request, $fields, $relatedModel);

        try {
            $relatedInstance->beforeSave($relatedModel, $request, creating: false);
            $relatedModel->save();
            $relatedInstance->afterSave($relatedModel, $request, creating: false);
            $this->syncDeferredWrites($relatedModel);
        } catch (QueryException $e) {
            Log::error('Martis: HasMany update error', [
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
                Field::filterForContext($resInstance->resolveDetailFields($request), FieldContext::DETAIL),
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
            Log::error('Martis: HasMany delete error', [
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
     * Resolve all context needed for a HasMany operation.
     *
     * @return array{parentModel: Model, parentResourceClass: class-string<resource>, relatedResourceClass: class-string<resource>, hasManyField: HasMany, relation: EloquentHasMany<Model, Model>|EloquentHasManyThrough<Model, Model, Model>}|IlluminateJsonResponse
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

        // Collection gate first: a resource the caller cannot list answers 403
        // before the parent query runs (no id probing, no 500 from a
        // fail-closed scope). The record-level view check follows the query.
        if ($forbidden = $this->forbiddenUnlessAuthorizedToViewAny($request, $resourceClass)) {
            return $forbidden;
        }

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

        // Find the HasMany field in the parent resource. filterForContext
        // flattens layout containers (Section/Panel/TabGroup) so a relation
        // nested in one still resolves — a raw scan would 404 it.
        // A relationship field hidden for the parent record (canSeeForModel())
        // is not on its detail page, so it answers like an undeclared one.
        $fields = Field::filterForModel(
            Field::filterForContext($parentInstance->resolveDetailFields($request), FieldContext::DETAIL),
            $request,
            $parentModel,
        );
        $hasManyField = null;

        foreach ($fields as $field) {
            if ($field instanceof HasMany && $field->getRelationship() === $relationship) {
                $hasManyField = $field;
                break;
            }
        }

        if ($hasManyField === null) {
            return $this->forbiddenWhenRelatedResourceClosed($request, $parentInstance, $parentModel, HasMany::class, $relationship)
                ?? JsonErrorResponse::notFound("Relationship '{$relationship}' not found.")->toResponse();
        }

        // Validate the Eloquent relationship
        if (! method_exists($parentModel, $relationship)) {
            return JsonErrorResponse::notFound("Relationship method '{$relationship}' does not exist.")->toResponse();
        }

        $relation = $parentModel->{$relationship}();

        if (! $relation instanceof EloquentHasMany && ! $relation instanceof EloquentHasManyThrough) {
            return JsonErrorResponse::notFound("'{$relationship}' is not a hasMany or hasManyThrough relationship.")->toResponse();
        }

        // Resolve the related resource class
        $relatedResourceKey = $hasManyField->getRelatedResourceKey();

        if ($relatedResourceKey === null || ! $this->registry->has($relatedResourceKey)) {
            return JsonErrorResponse::notFound('Related resource not registered.')->toResponse();
        }

        /** @var class-string<resource> $relatedResourceClass */
        $relatedResourceClass = $this->registry->get($relatedResourceKey);

        // A write through the relationship writes a record of the related
        // resource, so it needs that resource's viewAny, as its own per-id
        // endpoints do. routable() is not required: a headless resource
        // stays usable as a relation target.
        if ($action !== null && ($forbidden = $this->forbiddenUnlessAuthorizedToViewAny($request, $relatedResourceClass))) {
            return $forbidden;
        }

        // No create through a HasManyThrough relationship, as in Nova: it is
        // a traversal with no foreign key of its own, so a create would write
        // the parent's key into the related record's key to the intermediate
        // model, filing the record under whichever intermediate has that id.
        // A plain HasMany field declared on a hasManyThrough relationship is
        // refused too. An update or a delete reaches a record the
        // relationship holds, under the related resource's policies.
        if ($action === 'create' && ($hasManyField instanceof HasManyThroughField || $relation instanceof EloquentHasManyThrough)) {
            return JsonErrorResponse::forbidden('Records cannot be created through a hasManyThrough relationship.')->toResponse();
        }

        // Check authorization for the action
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
            'hasManyField' => $hasManyField,
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

        // A field hidden for this record (canSeeForModel()) is left out, as
        // on every read of a record, and listed under `_hidden`.
        $visible = Field::filterForModel($fields, request(), $model);
        foreach ($visible as $field) {
            // What the index shows, displayUsing() included (Nova's
            // index and detail fields), like ResourceController.
            $data[$field->attribute()] = $field->resolveForDisplay($model);
        }
        $data += $this->hiddenFieldsEntry($fields, $visible);

        if ($resource::softDeletes() && $model->getAttribute('deleted_at') !== null) {
            $data['deleted_at'] = $model->getAttribute('deleted_at');
        }

        return $data;
    }

    /**
     * Validate request against field rules (see
     * `BuildsFieldRules::buildWriteValidation()`), the fields inside a
     * Repeater's rows included. `$model` is the related record an update
     * writes, whose stored Repeater rows the rows sent continue.
     *
     * @param  list<FieldContract>  $fields
     */
    private function validateRequest(Request $request, array $fields, bool $isUpdate = false, ?Model $model = null, ?RelatableWrite $relatable = null): ?IlluminateJsonResponse
    {
        // Multipart requests carry list / map values as JSON strings; give
        // the rules below and the fill that follows the decoded structure.
        $undecodable = $this->decodeStructuredValues($request, $fields);

        $validation = $this->buildWriteValidation($fields, $request->all(), $isUpdate, $undecodable, $model, $relatable);

        $validator = Validator::make($request->all(), $validation['rules'], $validation['messages'], $validation['attributes']);

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
        // The related record exists on update and is a fresh model on create.
        $isUpdate = $model->exists;

        foreach ($fields as $field) {
            $attr = $field->attribute();

            // Immutable fields are writable on create and silently skipped
            // on update, as ResourceController::fillFields() does.
            if ($isUpdate && $field instanceof Field && $field->isImmutable()) {
                continue;
            }

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
}
