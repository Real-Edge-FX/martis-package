<?php

namespace Martis\Http\Controllers;

use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Martis\Contracts\FieldContract;
use Martis\FieldContext;
use Martis\Fields\Field;
use Martis\Fields\MorphOne;
use Martis\Fields\MorphOneOfMany;
use Martis\Http\Controllers\Concerns\BuildsFieldRules;
use Martis\Http\Controllers\Concerns\DecodesStructuredValues;
use Martis\Http\Controllers\Concerns\SyncsDeferredWrites;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Support\RelationScope;

/**
 * Controller for MorphOne relationship operations.
 *
 * Endpoints:
 *   GET    /api/resources/{resource}/{id}/morph-one/{relationship}   → show (or null)
 *   POST   /api/resources/{resource}/{id}/morph-one/{relationship}   → store (create)
 *   PUT    /api/resources/{resource}/{id}/morph-one/{relationship}   → update
 *   DELETE /api/resources/{resource}/{id}/morph-one/{relationship}   → destroy
 */
class MorphOneController extends MartisController
{
    use BuildsFieldRules;
    use DecodesStructuredValues;
    use SyncsDeferredWrites;

    /** Create the controller and inject the resource registry. */
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * Return the single polymorphically related record (or null).
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
            'morphOneField' => $morphOneField,
        ] = $context;

        $relatedModel = $this->relatedRecord($morphOneField, $relation);

        if ($relatedModel === null) {
            return new IlluminateJsonResponse(['data' => null, 'meta' => [], 'links' => []], 200);
        }

        // A record the user may not view: as Nova, which drops the whole
        // panel, the card is hidden (`meta.hidden`), with no Create, Edit or
        // count, so it neither shows the record nor offers a second one.
        if (! (new $relatedResourceClass($relatedModel))->authorizedToView($request)) {
            return new IlluminateJsonResponse(['data' => null, 'meta' => ['hidden' => true], 'links' => []], 200);
        }

        $resInstance = new $relatedResourceClass($relatedModel);

        // Same "count the underlying morphMany, not the promoted morphOne"
        // concern as HasOneOfMany.
        $ofManyMeta = null;
        if ($morphOneField instanceof MorphOneOfMany) {
            // A morphMany keeps its own query (and its constraints); an
            // Eloquent one-of-many morphOne is rebuilt from its own keys, so
            // a custom local key counts this parent's rows.
            $related = get_class($relation->getRelated());
            $unscoped = method_exists($relation, 'isOneOfMany') && $relation->isOneOfMany()
                ? fn () => $parentModel->morphMany(
                    $related,
                    '',
                    $relation->getMorphType(),
                    $relation->getForeignKeyName(),
                    $relation->getLocalKeyName(),
                )->getQuery()
                : fn () => (clone $relation)->getQuery();

            // Counted as the related index lists them: a record its scopes()
            // or indexQuery() hide is not part of "1 of N" or the aggregate.
            $baseQuery = function () use ($request, $unscoped, $relatedResourceClass): Builder {
                $query = $unscoped();
                RelationScope::constrainByKey($request, $query, $relatedResourceClass);

                return $query;
            };

            $ofManyMeta = ['totalCount' => $baseQuery()->count()];
            $fn = $morphOneField->getAggregateFunction();
            $col = $morphOneField->getAggregateColumn();
            $column = $col;
            if ($col !== null && $col !== '*') {
                $col = $relation->getRelated()->qualifyColumn($col);
            }
            if ($fn !== null && $col !== null) {
                $agg = match ($fn->value) {
                    'count' => (int) $baseQuery()->count($col === '*' ? '*' : $col),
                    'sum' => $baseQuery()->sum($col),
                    'min' => $baseQuery()->min($col),
                    'max' => $baseQuery()->max($col),
                    'avg' => $baseQuery()->avg($col),
                    default => null,
                };
                $ofManyMeta['aggregate'] = ['fn' => $fn->value, 'column' => $column, 'value' => $agg];
            }
        }

        $data = $this->serializeModel(
            $resInstance,
            Field::filterForContext($resInstance->fieldsForDetail($request), FieldContext::DETAIL),
            $relatedModel,
        );

        $meta = $ofManyMeta !== null ? ['ofMany' => $ofManyMeta] : [];

        return JsonResponse::make($data, $meta)->toResponse();
    }

    /**
     * Create the polymorphically related record (only when none exists yet).
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
            'morphOneField' => $morphOneField,
        ] = $context;

        // A MorphOne holds one record: a second one is refused with a 422,
        // whether or not the user may view the one there. The check runs
        // again under a lock on the parent before the insert, so two
        // concurrent creates cannot both pass it. A one-of-many card sits
        // on a many relationship, which takes more records.
        $single = ! $morphOneField instanceof MorphOneOfMany;
        if ($single && $relation->exists()) {
            return $this->alreadyFilled($relationship, 'The MorphOne relationship has already been filled.');
        }

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

        $validationError = $this->validateRequest($request, $fields);
        if ($validationError !== null) {
            return $validationError;
        }

        $this->fillFields($request, $fields, $relatedModel);

        // Set the polymorphic type and id columns
        $relatedModel->setAttribute($relation->getMorphType(), $relation->getMorphClass());
        $relatedModel->setAttribute($relation->getForeignKeyName(), $parentModel->getKey());

        try {
            // The parent's own connection: a model on another connection than
            // the default one would lock nothing in a default transaction.
            $filled = $parentModel->getConnection()->transaction(function () use ($single, $parentModel, $relation, $relatedResourceClass, $relatedModel, $request): bool {
                if ($single) {
                    $parentModel->newQuery()->whereKey($parentModel->getKey())->lockForUpdate()->first();
                    if ($relation->exists()) {
                        return true;
                    }
                }

                $relatedInstance = new $relatedResourceClass($relatedModel);
                $relatedInstance->beforeSave($relatedModel, $request, creating: true);
                $relatedModel->save();
                $relatedInstance->afterSave($relatedModel, $request, creating: true);
                $this->syncDeferredWrites($relatedModel);

                return false;
            });

            if ($filled) {
                return $this->alreadyFilled($relationship, 'The MorphOne relationship has already been filled.');
            }
        } catch (QueryException $e) {
            Log::error('Martis: MorphOne store error', [
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
     * Update the existing polymorphically related record.
     */
    #[QueryParameter('relatedId', description: 'The id of the record the card shows. Required: 422 without it, 409 when the relationship now holds another record (a newer one-of-many record, a replaced MorphOne), so a write never lands on a record the user did not see.', required: true, type: 'string')]
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
            'morphOneField' => $morphOneField,
        ] = $context;

        // The record the card shows, so Edit / Delete write that one.
        $relatedModel = $this->viewableRelatedRecord($request, $relatedResourceClass, $morphOneField, $relation);

        if ($relatedModel === null) {
            return JsonErrorResponse::notFound('Related record not found.')->toResponse();
        }

        $relatedInstance = new $relatedResourceClass($relatedModel);

        // Authorized first, as on the record's own page: a user who may not
        // write the record learns nothing from the id check (403, not 422/409).
        if (! $relatedInstance->authorizedToUpdate($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        if ($mismatch = $this->oneRecordTargetMismatch($request, $relatedModel)) {
            return $mismatch;
        }

        // A field hidden for the related record (canSeeForModel()) is neither
        // validated nor written, as on the resource's own update.
        $fields = Field::filterForModel(
            Field::filterForContext($relatedInstance->fieldsForUpdate($request), FieldContext::UPDATE),
            $request,
            $relatedModel,
        );

        foreach ($fields as $field) {
            if (method_exists($field, 'setUniqueIgnoreId')) {
                $field->setUniqueIgnoreId($relatedModel->getKey());
            }
        }

        $validationError = $this->validateRequest($request, $fields, isUpdate: true, model: $relatedModel);
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
            Log::error('Martis: MorphOne update error', [
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
     * Delete the polymorphically related record.
     */
    #[QueryParameter('relatedId', description: 'The id of the record the card shows. Required: 422 without it, 409 when the relationship now holds another record (a newer one-of-many record, a replaced MorphOne), so a write never lands on a record the user did not see.', required: true, type: 'string')]
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
            'morphOneField' => $morphOneField,
        ] = $context;

        // The record the card shows, so Edit / Delete write that one.
        $relatedModel = $this->viewableRelatedRecord($request, $relatedResourceClass, $morphOneField, $relation);

        if ($relatedModel === null) {
            return JsonErrorResponse::notFound('Related record not found.')->toResponse();
        }

        $relatedInstance = new $relatedResourceClass($relatedModel);

        // Authorized first, as on the record's own page: a user who may not
        // write the record learns nothing from the id check (403, not 422/409).
        if (! $relatedInstance->authorizedToDelete($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        if ($mismatch = $this->oneRecordTargetMismatch($request, $relatedModel)) {
            return $mismatch;
        }

        try {
            $relatedInstance->beforeDelete($relatedModel, $request);
            $relatedModel->delete();
            $relatedInstance->afterDelete($relatedModel, $request);
        } catch (QueryException $e) {
            Log::error('Martis: MorphOne delete error', [
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
     * The record the card shows and writes, when the user may view it: as
     * in Nova, whose one-record panel is the related resource's detail view
     * and disappears when its `view` policy denies the record (nova-dusk-suite
     * HasOneAuthorizationTest). A record the user may not view reads as no
     * record: the card shows empty and a write answers 404.
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     * @param  Relation<Model, Model, mixed>  $relation
     */
    private function viewableRelatedRecord(Request $request, string $relatedResourceClass, MorphOne $morphOneField, Relation $relation): ?Model
    {
        $model = $this->relatedRecord($morphOneField, $relation);

        if ($model === null || ! (new $relatedResourceClass($model))->authorizedToView($request)) {
            return null;
        }

        return $model;
    }

    /**
     * The related record the card shows. A MorphOneOfMany with a runtime
     * scope (latestByTimestamp() / oldestByTimestamp()) picks it from its
     * many relation with that order; any other relation holds one record.
     * show(), update() and destroy() all read it here, so Edit and Delete
     * write the record on screen.
     *
     * @param  Relation<Model, Model, mixed>  $relation
     */
    private function relatedRecord(MorphOne $morphOneField, Relation $relation): ?Model
    {
        $scope = $morphOneField instanceof MorphOneOfMany ? $morphOneField->getRuntimeScope() : null;

        if ($scope === null) {
            /** @var Model|null */
            return $relation->first();
        }

        // Order a clone of the relation and read through the relation's own
        // first(): on a through relation the raw query joins the
        // intermediate table, and a plain `select *` lets its id overwrite
        // the related record's id, so a write would land on another
        // parent's record.
        $scoped = clone $relation;
        $scope($scoped->getQuery());

        /** @var Model|null */
        return $scoped->first();
    }

    /**
     * Resolve all context needed for a MorphOne operation.
     *
     * @return array{parentModel: Model, parentResourceClass: class-string<resource>, relatedResourceClass: class-string<resource>, morphOneField: MorphOne, relation: EloquentMorphOne<Model, Model>|EloquentMorphMany<Model, Model>}|IlluminateJsonResponse
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

        // filterForContext flattens layout containers (Section/Panel/TabGroup)
        // so a relation nested in one still resolves — a raw scan would 404 it.
        // A relationship field hidden for the parent record (canSeeForModel())
        // is not on its detail page, so it answers like an undeclared one.
        $fields = Field::filterForModel(
            Field::filterForContext($parentInstance->fieldsForDetail($request), FieldContext::DETAIL),
            $request,
            $parentModel,
        );
        $morphOneField = null;

        foreach ($fields as $field) {
            if ($field instanceof MorphOne && $field->getRelationship() === $relationship) {
                $morphOneField = $field;
                break;
            }
        }

        if ($morphOneField === null) {
            return JsonErrorResponse::notFound("Relationship '{$relationship}' not found.")->toResponse();
        }

        if (! method_exists($parentModel, $relationship)) {
            return JsonErrorResponse::notFound("Relationship method '{$relationship}' does not exist.")->toResponse();
        }

        $relation = $parentModel->{$relationship}();

        // Accept plain MorphOne and MorphMany when the field is
        // MorphOneOfMany using runtime ordering scope.
        if (! $relation instanceof EloquentMorphOne && ! $relation instanceof EloquentMorphMany) {
            return JsonErrorResponse::notFound("'{$relationship}' is not a morphOne or compatible morphMany-of-many relationship.")->toResponse();
        }

        $relatedResourceKey = $morphOneField->getRelatedResourceKey();

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
            'morphOneField' => $morphOneField,
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
            $data[$field->attribute()] = $field->resolve($model);
        }
        $data += $this->hiddenFieldsEntry($fields, $visible);

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
    private function validateRequest(Request $request, array $fields, bool $isUpdate = false, ?Model $model = null): ?IlluminateJsonResponse
    {
        // Multipart requests carry list / map values as JSON strings; give
        // the rules below and the fill that follows the decoded structure.
        $undecodable = $this->decodeStructuredValues($request, $fields);

        $validation = $this->buildWriteValidation($fields, $request->all(), $isUpdate, $undecodable, $model);

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

    /**
     * The 422 a second record on a one-record relationship answers, with
     * its message on the response too: the form has no input named after
     * the relationship, so only the top-level message reaches the user.
     */
    private function alreadyFilled(string $relationship, string $message): IlluminateJsonResponse
    {
        return JsonErrorResponse::validation([$relationship => [$message]], $message)->toResponse();
    }
}
