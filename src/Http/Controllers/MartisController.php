<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Martis\Contracts\ActionContract;
use Martis\Contracts\FieldContract;
use Martis\Fields\Field;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Resource;
use Martis\ResourceRegistry;

abstract class MartisController extends Controller
{
    /**
     * Resolve a resource class from its URI key.
     *
     * @return array{class-string<\Martis\Resource>|null, IlluminateJsonResponse|null}
     */
    protected function resolveResourceClass(ResourceRegistry $registry, string $uriKey): array
    {
        if (! $registry->has($uriKey)) {
            return [null, JsonErrorResponse::notFound("Resource '{$uriKey}' not found.")->toResponse()];
        }

        return [$registry->get($uriKey), null];
    }

    /**
     * Consult the collection-level gate before any record query on a per-id
     * endpoint.
     *
     * `viewAny` is the entry gate to a resource (it already protects index,
     * schema, search, lenses and metrics). Checking it before `find()` means
     * a resource the user cannot list answers 403 before any query runs, so
     * a model scope that fails closed never turns a deep-link into a 500, and
     * record ids cannot be probed (404 vs 403) through a resource the user is
     * not allowed to see. The record-level checks stay after the query.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     */
    protected function forbiddenUnlessAuthorizedToViewAny(Request $request, string $resourceClass): ?IlluminateJsonResponse
    {
        $instance = new $resourceClass;

        if (! $instance->authorizedToViewAny($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        return null;
    }

    /**
     * Resolve the resource instance a form-scoped endpoint (sync-field,
     * field-options) gates on, and run that gate.
     *
     * Create context: a bare instance, gated on the create ability. Update
     * context: the record named by `$id`, bound to the instance so the update
     * ability receives the model the way every Laravel policy expects. A bare
     * instance would call `update($user)` with no model and raise
     * ArgumentCountError on any policy typed `update(User, Model)`, a 500
     * where a 403 was meant. `viewAny` runs before the record query and a
     * missing id or record is reported as such, mirroring the per-id
     * endpoints. v1.37.0.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     * @return array{0: \Martis\Resource|null, 1: IlluminateJsonResponse|null}
     */
    protected function resolveFormScopedResource(Request $request, string $resourceClass, string $context, int|string|null $id): array
    {
        if ($context !== 'update') {
            $instance = new $resourceClass;

            if (! $instance->authorizedToCreate($request)) {
                return [null, JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse()];
            }

            return [$instance, null];
        }

        if ($id === null || $id === '') {
            return [null, JsonErrorResponse::validation(['id' => ['A record id is required in the update context.']])->toResponse()];
        }

        if ($forbidden = $this->forbiddenUnlessAuthorizedToViewAny($request, $resourceClass)) {
            return [null, $forbidden];
        }

        $model = $this->findModelByKey($resourceClass, $id);
        if ($model === null) {
            return [null, JsonErrorResponse::notFound()->toResponse()];
        }

        $instance = new $resourceClass($model);

        if (! $instance->authorizedToUpdate($request)) {
            return [null, JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse()];
        }

        return [$instance, null];
    }

    /**
     * Resolve the form a per-field endpoint answers for from the record id
     * in its URL (relatable options, slug check).
     *
     * An id that names a record the user may update is the update form, on
     * an instance bound to that record so closures on its fields can read
     * it. Anything else is the create form on a fresh instance: the `_`
     * placeholder, no id, an id that names no record (a form nested in
     * another resource's page can send that page's id), or a record the
     * user may not update, so nothing derived from that record reaches the
     * answer and the answer does not tell it apart from a missing one. The
     * caller keeps its own gates (viewAny on the resource).
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     * @return array{0: \Martis\Resource, 1: 'create'|'update'}
     */
    protected function resolveFormFromRecordId(Request $request, string $resourceClass, int|string|null $id): array
    {
        $model = $id === null || $id === '' || $id === '_'
            ? null
            : $this->findFormRecord($resourceClass, $id);

        if ($model !== null) {
            $instance = new $resourceClass($model);

            if ($instance->authorizedToUpdate($request)) {
                return [$instance, 'update'];
            }
        }

        return [new $resourceClass, 'create'];
    }

    /**
     * Find the field a per-field form endpoint (relatable options, slug
     * check, dependsOn sync, remote select options) answers for.
     *
     * Searches the field sets of the form `$context` names, in order: the
     * update form (`fieldsForUpdate()`), or the two create forms, the page
     * (`fieldsForCreate()`) and the inline-create modal
     * (`fieldsForInlineCreate()`); then `fields()` when `$orFields` is set.
     * A field declared on one form only resolves, and the form's own
     * declaration wins over a differently configured one in `fields()`.
     * Layout containers are searched too, and only an instance of one of
     * `$types` matches, so a form that reuses an attribute for another kind
     * of field does not shadow the one the caller needs.
     *
     * @param  'create'|'update'  $context
     * @param  list<class-string<FieldContract>>  $types
     */
    protected function findFormField(
        Resource $resource,
        Request $request,
        string $context,
        string $attribute,
        array $types = [FieldContract::class],
        bool $orFields = false,
    ): ?FieldContract {
        $sets = $context === 'update'
            ? [fn (): array => $resource->fieldsForUpdate($request)]
            : [
                fn (): array => $resource->fieldsForCreate($request),
                fn (): array => $resource->fieldsForInlineCreate($request),
            ];

        if ($orFields) {
            $sets[] = fn (): array => $resource->fields($request);
        }

        return $this->findField($sets, $attribute, $types);
    }

    /**
     * The first field with this attribute that is an instance of one of
     * `$types`, searching the field sets in order. A set is only built when
     * the sets before it do not declare the field.
     *
     * @param  iterable<\Closure(): iterable<mixed>>  $sets
     * @param  list<class-string<FieldContract>>  $types
     */
    protected function findField(iterable $sets, string $attribute, array $types = [FieldContract::class]): ?FieldContract
    {
        foreach ($sets as $set) {
            foreach ($this->flattenFormItems($set()) as $field) {
                if ($field->attribute() !== $attribute) {
                    continue;
                }

                foreach ($types as $type) {
                    if ($field instanceof $type) {
                        return $field;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The fields of a field set, with the layout containers opened: Section,
     * Panel and TabGroup, and custom containers exposing `flattenFields()`,
     * `getFields()` or `fields()`.
     *
     * @param  iterable<mixed>  $items
     * @return \Generator<int, FieldContract>
     */
    private function flattenFormItems(iterable $items): \Generator
    {
        foreach ($items as $item) {
            if ($item instanceof FieldContract) {
                yield $item;

                continue;
            }

            if (! is_object($item)) {
                continue;
            }

            $nested = match (true) {
                method_exists($item, 'flattenFields') => $item->flattenFields(),
                method_exists($item, 'getFields') => $item->getFields(),
                method_exists($item, 'fields') => $item->fields(),
                default => [],
            };

            if (is_iterable($nested)) {
                yield from $this->flattenFormItems($nested);
            }
        }
    }

    /**
     * Find an Action a resource registers, by URI key. The caller runs the
     * Action's gates (authorizedToSee(), authorizedToRun()).
     */
    protected function findAction(Resource $resource, string $uriKey, Request $request): ?ActionContract
    {
        $actions = $resource->actions($request);

        foreach ($actions as $action) {
            if ($action->uriKey() === $uriKey) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Find the record a form request names without letting an id its key
     * column cannot hold reach the database: PostgreSQL rejects `abc` for an
     * integer key, or `42` for a uuid one, where other drivers match
     * nothing, and a nested form can send the id of another resource.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     */
    private function findFormRecord(string $resourceClass, int|string $id): ?Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();

        if ((new $modelClass)->getKeyType() === 'int' && preg_match('/^-?\d+$/', (string) $id) !== 1) {
            return null;
        }

        try {
            return $this->findModelByKey($resourceClass, $id);
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Find a model by primary key, respecting soft-delete inclusion.
     */
    protected function findModelByKey(string $resourceClass, int|string $id): ?Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();

        if ($resourceClass::softDeletes()) {
            /** @phpstan-ignore-next-line — guarded by softDeletes() check */
            return $modelClass::withTrashed()->find($id);
        }

        /** @phpstan-ignore staticMethod.notFound */
        return $modelClass::find($id);
    }

    /**
     * Serialize a model into an array using the provided fields and the
     * standard Martis envelope (`_title`, `_resource`, `_authorization`).
     *
     * @param  list<FieldContract>  $fields
     * @return array<string, mixed>
     */
    protected function serializeModelForIndex(Resource $resource, array $fields, Model $model, bool $forDisplay = true): array
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

        if ($resource::softDeletes() && $model->getAttribute('deleted_at') !== null) {
            $data['deleted_at'] = $model->getAttribute('deleted_at');
        }

        return $data;
    }

    /**
     * Whether `$attribute` is a declared-sortable field on `$resourceClass`.
     *
     * Relationship index endpoints accept a `?sort=` query param. It must
     * never reach `orderBy()` unvalidated: a non-existent column 500s on
     * MySQL/Postgres (SQLite tolerates it), and an undeclared real column
     * would be silently honoured (unintended ordering / minor info-oracle).
     * Validate against the resource's fields where `isSortable()`. Layout
     * nodes (Section/Panel/TabGroup) are flattened first so the
     * `FieldContract` filter does not trip on non-field nodes — mirrors
     * `HasManyController::applySorting()`.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     */
    protected function isSortableAttribute(string $resourceClass, Request $request, string $attribute): bool
    {
        $instance = new $resourceClass;

        $sortable = array_map(
            fn (FieldContract $field): string => $field->attribute(),
            array_values(array_filter(
                Field::flattenLayoutFields($instance->fields($request)),
                fn (FieldContract $field): bool => $field->isSortable(),
            )),
        );

        return in_array($attribute, $sortable, true);
    }
}
