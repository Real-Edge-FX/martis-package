<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough as EloquentHasOneThrough;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Martis\Contracts\FieldContract;
use Martis\FieldContext;
use Martis\Fields\Field;
use Martis\Fields\HasOne;
use Martis\Fields\HasOneOfMany;
use Martis\Fields\HasOneThrough as HasOneThroughField;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * Controller for HasOne relationship operations.
 *
 * Endpoints:
 *   GET    /api/resources/{resource}/{id}/has-one/{relationship}   → show (or null)
 *   POST   /api/resources/{resource}/{id}/has-one/{relationship}   → store (create)
 *   PUT    /api/resources/{resource}/{id}/has-one/{relationship}   → update
 *   DELETE /api/resources/{resource}/{id}/has-one/{relationship}   → destroy
 */
class HasOneController extends MartisController
{
    /** Create the controller and inject the resource registry. */
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * Return the single related record (or null if it does not exist yet).
     */
    public function show(
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
            'parentModel' => $parentModel,
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
            'hasOneField' => $hasOneField,
        ] = $context;

        // ⭐ OfMany runtime scope (Martis differential — latestByTimestamp / oldestByTimestamp).
        if ($hasOneField instanceof HasOneOfMany) {
            $scope = $hasOneField->getRuntimeScope();
            if ($scope !== null) {
                // Apply the scope to the relation's query and pick the first row.
                $scopedQuery = $scope(clone $relation->getQuery());
                $relatedModel = $scopedQuery->first();
            } else {
                $relatedModel = $relation->first();
            }
        } else {
            $relatedModel = $relation->first();
        }

        if ($relatedModel === null) {
            return new IlluminateJsonResponse(['data' => null, 'meta' => [], 'links' => []], 200);
        }

        $resInstance = new $relatedResourceClass($relatedModel);

        // ⭐ Build OfMany meta bag — "Latest of N" affordance + optional aggregate tile.
        // IMPORTANT: `$relation` for a `hasOne()->latestOfMany()` relationship
        // is already narrowed to a single row — calling ->count() on it
        // returns 1. We need the count across the UNDERLYING hasMany, so
        // we rebuild a clean query from the related model + FK.
        $ofManyMeta = null;
        if ($hasOneField instanceof HasOneOfMany) {
            $relatedClass = get_class($relation->getRelated());
            $fk = $relation->getForeignKeyName();
            $parentKey = $parentModel->getKey();
            $baseQuery = fn () => $relatedClass::query()->where($fk, $parentKey);

            $ofManyMeta = [
                'totalCount' => $baseQuery()->count(),
            ];

            $fn = $hasOneField->getAggregateFunction();
            $col = $hasOneField->getAggregateColumn();
            if ($fn !== null && $col !== null) {
                $agg = match ($fn->value) {
                    'count' => (int) $baseQuery()->count($col === '*' ? '*' : $col),
                    'sum' => $baseQuery()->sum($col),
                    'min' => $baseQuery()->min($col),
                    'max' => $baseQuery()->max($col),
                    'avg' => $baseQuery()->avg($col),
                    default => null,
                };
                $ofManyMeta['aggregate'] = [
                    'fn' => $fn->value,
                    'column' => $col,
                    'value' => $agg,
                ];
            }
        }

        $data = $this->serializeModel(
            $resInstance,
            Field::filterForContext($resInstance->fieldsForDetail($request), FieldContext::DETAIL),
            $relatedModel,
        );

        $meta = [];
        if ($ofManyMeta !== null) {
            $meta['ofMany'] = $ofManyMeta;
        }
        if ($hasOneField instanceof HasOneThroughField && $hasOneField->hasThroughBreadcrumb()) {
            $meta['throughBreadcrumb'] = $this->buildThroughBreadcrumb($hasOneField);
        }

        return JsonResponse::make($data, $meta)->toResponse();
    }

    /**
     * ⭐ Martis differential — compose a human-readable hop string for
     * the detail panel tooltip on HasOneThrough fields.
     */
    private function buildThroughBreadcrumb(HasOneThroughField $field): array
    {
        return [
            'enabled' => true,
            'relationship' => $field->getRelationship(),
            // Custom text supplied by the developer via
            // ->throughBreadcrumb(true, 'Custom text'). Null = frontend
            // falls back to the default i18n string "through_tooltip".
            'text' => $field->getThroughBreadcrumbText(),
        ];
    }

    /**
     * Create the related record (only when none exists yet).
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

        // Guard: cannot create if one already exists
        if ($relation->exists()) {
            return JsonErrorResponse::serverError('A related record already exists for this relationship.')->toResponse();
        }

        // Check parent resource authorizedToAdd for this related model class
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
        } catch (QueryException $e) {
            Log::error('Martis: HasOne store error', [
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
     * Update the existing related record.
     */
    public function update(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
    ): IlluminateJsonResponse {
        $context = $this->resolveContext($request, $resource, $id, $relationship, 'update');

        if ($context instanceof IlluminateJsonResponse) {
            return $context;
        }

        [
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
        ] = $context;

        $relatedModel = $relation->first();

        if ($relatedModel === null) {
            return JsonErrorResponse::notFound('Related record not found.')->toResponse();
        }

        $relatedInstance = new $relatedResourceClass($relatedModel);

        if (! $relatedInstance->authorizedToUpdate($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $fields = Field::filterForContext($relatedInstance->fieldsForUpdate($request), FieldContext::UPDATE);

        // Set unique-ignore ID
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
            Log::error('Martis: HasOne update error', [
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
            meta: ['message' => $relatedResourceClass::updatedMessage()],
        )->toResponse();
    }

    /**
     * Delete the related record.
     */
    public function destroy(
        Request $request,
        string $resource,
        int|string $id,
        string $relationship,
    ): IlluminateJsonResponse {
        $context = $this->resolveContext($request, $resource, $id, $relationship, 'delete');

        if ($context instanceof IlluminateJsonResponse) {
            return $context;
        }

        [
            'relatedResourceClass' => $relatedResourceClass,
            'relation' => $relation,
        ] = $context;

        $relatedModel = $relation->first();

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
            Log::error('Martis: HasOne delete error', [
                'resource' => $resource,
                'relationship' => $relationship,
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
     * Resolve all context needed for a HasOne operation.
     *
     * @return array{parentModel: Model, parentResourceClass: class-string<resource>, relatedResourceClass: class-string<resource>, hasOneField: HasOne, relation: EloquentHasOne<Model, Model>}|IlluminateJsonResponse
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

        // Find the HasOne field in the parent resource
        $fields = $parentInstance->fieldsForDetail($request);
        $hasOneField = null;

        foreach ($fields as $field) {
            if ($field instanceof HasOne && $field->getRelationship() === $relationship) {
                $hasOneField = $field;
                break;
            }
        }

        if ($hasOneField === null) {
            return JsonErrorResponse::notFound("Relationship '{$relationship}' not found.")->toResponse();
        }

        // Validate the Eloquent relationship
        if (! method_exists($parentModel, $relationship)) {
            return JsonErrorResponse::notFound("Relationship method '{$relationship}' does not exist.")->toResponse();
        }

        $relation = $parentModel->{$relationship}();

        // Accept plain HasOne (Nova parity), HasMany when the field is
        // HasOneOfMany using latestByTimestamp() / oldestByTimestamp()
        // at runtime, and HasOneThrough for the Through variant.
        if (
            ! $relation instanceof EloquentHasOne
            && ! $relation instanceof EloquentHasOneThrough
            && ! $relation instanceof EloquentHasMany
        ) {
            return JsonErrorResponse::notFound("'{$relationship}' is not a compatible hasOne / hasOneThrough / hasMany-of-many relationship.")->toResponse();
        }

        // Resolve the related resource class
        $relatedResourceKey = $hasOneField->getRelatedResourceKey();

        if ($relatedResourceKey === null || ! $this->registry->has($relatedResourceKey)) {
            return JsonErrorResponse::notFound('Related resource not registered.')->toResponse();
        }

        /** @var class-string<resource> $relatedResourceClass */
        $relatedResourceClass = $this->registry->get($relatedResourceKey);

        // Block mutations on HasOneThrough — the relationship is a traversal,
        // there is no direct FK for Eloquent to create/update/delete on.
        // Defence in depth: even if someone bypasses the UI, the backend
        // refuses. Aligned with the Nova default.
        if ($action !== null && $hasOneField instanceof HasOneThroughField) {
            return JsonErrorResponse::forbidden("hasOneThrough relationships are read-only.")->toResponse();
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
            'hasOneField' => $hasOneField,
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
        }

        $validator = Validator::make($request->all(), $rules);

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

            if ($request->hasFile($attr)) {
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
