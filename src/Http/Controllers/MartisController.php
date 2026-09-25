<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Martis\Contracts\ActionContract;
use Martis\Contracts\FieldContract;
use Martis\Enums\SortDirection;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Field;
use Martis\Fields\HasMany;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Repeater;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Support\RelationScope;

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
     * The per-action map a listed row carries under `_actionAuthorization`:
     * whether each action may run on the row's record, by the same predicate
     * `ActionController::execute()` enforces (see `actionRunDenial()`), so an
     * item the menu enables is one the run accepts. The resource index maps
     * every action the user can see; a relationship panel (`$inlineOnly`)
     * maps only its inline actions, the only ones its rows offer. `null`
     * when there is none to map, so the rows skip it.
     *
     * The closure takes the row's serialized `_authorization`, whose
     * `authorizedToRunAction` / `authorizedToRunDestructiveAction` flags are
     * the policy answers the map needs, so the policy is not asked again
     * per action.
     *
     * @param  class-string<resource>  $resourceClass
     * @return (\Closure(Model, array<string, mixed>=): array<string, bool>)|null
     */
    protected function rowActionAuthorizer(Request $request, string $resourceClass, bool $inlineOnly = false): ?\Closure
    {
        $actions = array_filter(
            (new $resourceClass)->actions($request),
            fn (ActionContract $action): bool => $action->authorizedToSee($request)
                && (! $inlineOnly || $action->isShownInline()),
        );

        if ($actions === []) {
            return null;
        }

        return function (Model $model, array $authorization = []) use ($actions, $request, $resourceClass): array {
            $policy = $this->actionPolicy($request, new $resourceClass($model), $authorization);
            $map = [];
            foreach ($actions as $action) {
                $map[$action->uriKey()] = $this->actionRunDenial($request, $action, $model, $policy) === null;
            }

            return $map;
        };
    }

    /**
     * The record's run-action policy, asked at most once per kind:
     * `$policy(true)` is `runDestructiveAction` (falling back to `delete`),
     * `$policy(false)` is `runAction` (falling back to `update`). A known
     * answer (the row's `_authorization` flags) is used as is.
     *
     * @param  array<string, mixed>  $known
     * @return \Closure(bool): bool
     */
    protected function actionPolicy(Request $request, Resource $resource, array $known = []): \Closure
    {
        $answers = [];
        if (is_bool($known['authorizedToRunAction'] ?? null)) {
            $answers[0] = $known['authorizedToRunAction'];
        }
        if (is_bool($known['authorizedToRunDestructiveAction'] ?? null)) {
            $answers[1] = $known['authorizedToRunDestructiveAction'];
        }

        return function (bool $destructive) use (&$answers, $request, $resource): bool {
            return $answers[(int) $destructive] ??= $destructive
                ? $resource->authorizedToRunDestructiveAction($request)
                : $resource->authorizedToRunAction($request);
        };
    }

    /**
     * Why `$action` may not run on `$model`, or `null` when it may: the
     * action's own `canRun()`, then, unless it is standalone, the record's
     * `runDestructiveAction` policy for a destructive action and its
     * `runAction` policy otherwise (see `actionPolicy()`). Both must allow
     * it: unlike Nova 5, where a `canRun()` replaces the policy, a
     * `canRun()` grants nothing the policy refuses. The one predicate behind
     * the row map and the run.
     *
     * @param  \Closure(bool): bool  $policy
     */
    protected function actionRunDenial(Request $request, ActionContract $action, Model $model, \Closure $policy): ?string
    {
        if (! $action->authorizedToRun($request, $model)) {
            return 'You are not authorized to run this action on one or more selected resources.';
        }

        if ($action->isStandalone()) {
            return null;
        }

        if ($action->isDestructive()) {
            return $policy(true) ? null : 'You are not authorized to run this destructive action.';
        }

        return $policy(false) ? null : 'You are not authorized to run this action.';
    }

    /**
     * Scope a relationship panel's rows as the related resource's index is
     * scoped: its declarative `scopes()`, then `indexQuery()`, in the
     * index's order, applied to the relation's own query so the panel
     * still paginates through the relation (a Through relation selects the
     * related columns only there; a pivot relation hydrates `pivot`). As in
     * Nova, whose relationship index runs the related resource's
     * `indexQuery()` on the relationship query. A hook that returns another
     * builder than the one it received constrains the rows by key.
     *
     * @param  Builder<Model>  $query  The relation's query (`$relation->getQuery()`).
     * @param  class-string<resource>  $relatedResourceClass
     */
    protected function scopeRelationQuery(Request $request, Builder $query, string $relatedResourceClass): void
    {
        RelationScope::apply($request, $query, $relatedResourceClass);
    }

    /**
     * Count, in the listing query itself, the related records of every
     * listed field that shows a relationship count on the index
     * (`HasMany`, `MorphMany`, `BelongsToMany`, `MorphToMany` with
     * `showOnIndex()`), scoped as the related index is: one query per page,
     * not one per row, and no hidden record counted. Each field reads its
     * count back from the model (see `CountsScopedRelation`).
     *
     * @param  Builder<Model>  $query
     * @param  list<FieldContract>  $fields
     */
    protected function withScopedRelationCounts(Request $request, Builder $query, array $fields): void
    {
        $counts = [];
        foreach ($fields as $field) {
            if (! $field instanceof HasMany && ! $field instanceof MorphMany && ! $field instanceof BelongsToMany && ! $field instanceof MorphToMany) {
                continue;
            }

            if (! $field->countsOnIndex() || ! method_exists($query->getModel(), $field->getRelationship())) {
                continue;
            }

            $relatedResourceClass = $field->relatedResourceClassForCount();
            $counts[$field->getRelationship().' as '.$field->countAlias()] = function (Builder $related) use ($request, $relatedResourceClass): void {
                if ($relatedResourceClass !== null) {
                    RelationScope::apply($request, $related, $relatedResourceClass);
                }
            };
        }

        if ($counts !== []) {
            $query->withCount($counts);
        }
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
     * of field does not shadow the one the caller needs. With `$repeaterRow`
     * (see repeaterRowOf()) the field is one of that Repeater row's instead,
     * found in the same sets (see inRepeaterRow()).
     *
     * A field the user cannot see is not on the form, so it is not found:
     * one `canSee()` hides, and one `canSeeForModel()` hides for the record
     * the update form edits (the resource's model) or, on a create form, for
     * the new model a create fills. The endpoint answers for it exactly as
     * for an attribute no form declares.
     *
     * @param  'create'|'update'  $context
     * @param  list<class-string<FieldContract>>  $types
     * @param  array{repeater: string, repeatable: string}|null  $repeaterRow
     */
    protected function findFormField(
        Resource $resource,
        Request $request,
        string $context,
        string $attribute,
        array $types = [FieldContract::class],
        bool $orFields = false,
        ?array $repeaterRow = null,
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

        // The record the form writes: the one an update form edits, or the
        // new model a create fills (see Field::filterForModel()).
        $model = $resource->getModel() ?? $resource::newModel();

        return $this->findDeclaredField($sets, $attribute, $request, $types, $model, $repeaterRow);
    }

    /**
     * Find the field a per-field endpoint answers for in the field sets
     * `$sets` (see findField()), or, with `$repeaterRow`, in that Repeater
     * row (see inRepeaterRow()). `$model` is the record the fields of
     * `$sets` belong to, if any: the field, or the Repeater, is found only
     * when the user may see it on that record. A row field is found when
     * the user may see it (`canSee()`): the fields of a row are not decided
     * on a record.
     *
     * @param  iterable<\Closure(): iterable<mixed>>  $sets
     * @param  list<class-string<FieldContract>>  $types
     * @param  array{repeater: string, repeatable: string}|null  $repeaterRow
     */
    protected function findDeclaredField(
        iterable $sets,
        string $attribute,
        Request $request,
        array $types = [FieldContract::class],
        ?Model $model = null,
        ?array $repeaterRow = null,
    ): ?FieldContract {
        if ($repeaterRow !== null) {
            return $this->findField($this->inRepeaterRow($sets, $repeaterRow, $request, $model), $attribute, $request, $types);
        }

        return $this->findField($sets, $attribute, $request, $types, $model);
    }

    /**
     * The Repeater row a per-field request names, or null when it names
     * none: `repeater` is the Repeater's attribute and `repeatable` the row
     * type (`Repeatable::shortName()`), since two row types may declare the
     * same attribute. A request that sends only one of them names a row no
     * Repeater has, so the lookup finds nothing (404) instead of reading
     * the form's own fields.
     *
     * @return array{repeater: string, repeatable: string}|null
     */
    protected function repeaterRowOf(Request $request): ?array
    {
        $repeater = $request->query('repeater');
        $repeatable = $request->query('repeatable');

        if ($repeater === null && $repeatable === null) {
            return null;
        }

        return [
            'repeater' => is_string($repeater) ? $repeater : '',
            'repeatable' => is_string($repeatable) ? $repeatable : '',
        ];
    }

    /**
     * The field sets to search for a field of the Repeater row `$row` (all
     * of `$sets` when `$row` is null): in each set, the fields of the row
     * type the Repeater with that attribute declares (layout containers
     * opened), or none when the set declares no such Repeater or the
     * Repeater no such row type. A set is only built when the sets before
     * it do not declare the field, as in findField().
     *
     * A Repeater the user cannot see (see findField(); `$model` is the
     * record its form writes, if any) declares no row there, so none of its
     * row fields is found.
     *
     * @param  iterable<\Closure(): iterable<mixed>>  $sets
     * @param  array{repeater: string, repeatable: string}|null  $row
     * @return iterable<\Closure(): iterable<mixed>>
     */
    protected function inRepeaterRow(iterable $sets, ?array $row, Request $request, ?Model $model = null): iterable
    {
        if ($row === null) {
            return $sets;
        }

        $rowSets = [];
        foreach ($sets as $set) {
            $rowSets[] = function () use ($set, $row, $request, $model): array {
                foreach ($this->flattenFormItems($set()) as $field) {
                    if ($field instanceof Repeater
                        && $field->attribute() === $row['repeater']
                        && $this->userMaySee($field, $request, $model)) {
                        return $field->findRepeatable($row['repeatable'])?->fields($request) ?? [];
                    }
                }

                return [];
            };
        }

        return $rowSets;
    }

    /**
     * The first field with this attribute that is an instance of one of
     * `$types` and that the user may see, searching the field sets in
     * order. A set is only built when the sets before it do not declare the
     * field.
     *
     * A field the user cannot see is skipped as if the set did not declare
     * it: one `canSee()` hides, and, when `$model` is given (the record the
     * fields belong to), one `canSeeForModel()` hides for that record. So a
     * per-field endpoint answers for a hidden field exactly as for an
     * undeclared attribute, and derives nothing from its declaration.
     *
     * @param  iterable<\Closure(): iterable<mixed>>  $sets
     * @param  list<class-string<FieldContract>>  $types
     */
    protected function findField(
        iterable $sets,
        string $attribute,
        Request $request,
        array $types = [FieldContract::class],
        ?Model $model = null,
    ): ?FieldContract {
        foreach ($sets as $set) {
            foreach ($this->flattenFormItems($set()) as $field) {
                if ($field->attribute() !== $attribute || ! $this->userMaySee($field, $request, $model)) {
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
     * Whether the user may see `$field`: `canSee()` allows it and, when
     * `$model` is given, `canSeeForModel()` allows it for that record.
     */
    private function userMaySee(FieldContract $field, Request $request, ?Model $model): bool
    {
        if (! $field->isAuthorizedToSee($request)) {
            return false;
        }

        return $model === null || Field::filterForModel([$field], $request, $model) !== [];
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
     * The pivot row that attaches `$relatedId` through `$relation`, with
     * every column it stores, as a pivot model of the relationship's class
     * (its `->using()` class, else `Pivot` / `MorphPivot`, whose
     * `pivotParent` is the parent record), or null when the relationship
     * does not attach that record. The `canSeeForModel()` of a pivot field
     * decides on it.
     *
     * @param  EloquentBelongsToMany<Model, Model, covariant Pivot, covariant string>  $relation
     */
    protected function storedPivotRow(EloquentBelongsToMany $relation, int|string $relatedId): ?Pivot
    {
        $row = $relation->newPivotStatementForId($relatedId)->first();

        return $row === null ? null : $relation->newExistingPivot((array) $row);
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
     * standard Martis envelope (`_title`, `_resource`, `_authorization`,
     * and `_hidden` when the record hides a field, see hiddenFieldsEntry()).
     *
     * @param  list<FieldContract>  $fields
     * @return array<string, mixed>
     */
    protected function serializeModelForIndex(Resource $resource, array $fields, Model $model, bool $forDisplay = true): array
    {
        /** @var array<string, mixed> $data */
        $data = ['id' => $model->getKey()];

        // A field hidden for this record (canSeeForModel()) is left out, as
        // on every read of a record.
        $visible = Field::filterForModel($fields, request(), $model);
        foreach ($visible as $field) {
            if ($forDisplay) {
                /** @var FieldContract&Field $fieldInstance */
                $fieldInstance = $field;
                $data[$field->attribute()] = $fieldInstance->resolveForDisplay($model);
            } else {
                $data[$field->attribute()] = $field->resolve($model);
            }
        }
        $data += $this->hiddenFieldsEntry($fields, $visible);

        $data['_title'] = $resource->title();
        $data['_resource'] = $resource->toArray();
        $data['_authorization'] = $resource->authorizationMetadata(request());

        if ($resource::softDeletes() && $model->getAttribute('deleted_at') !== null) {
            $data['deleted_at'] = $model->getAttribute('deleted_at');
        }

        return $data;
    }

    /**
     * The `_hidden` entry of a serialised record: the attributes of the
     * fields of `$fields` that `canSeeForModel()` hides for the record
     * (`$visible` holds the ones it keeps, see `Field::filterForModel()`).
     * The record leaves their values out, and the schema, which describes
     * the resource, still lists them: with this list a page leaves those
     * fields out instead of rendering them empty. Empty, so the record
     * carries no `_hidden` key, when the record hides no field.
     *
     * @param  list<FieldContract>  $fields
     * @param  list<FieldContract>  $visible
     * @return array{_hidden?: list<string>}
     */
    protected function hiddenFieldsEntry(array $fields, array $visible): array
    {
        $hidden = Field::hiddenAttributes($fields, $visible);

        return $hidden === [] ? [] : ['_hidden' => $hidden];
    }

    /**
     * Whether a request may order a list of `$resourceClass` by
     * `$attribute`: a `sortable()` field of its `fields()` (layout
     * containers opened) that the user may see (see
     * `Field::sortableAttributes()`).
     *
     * A `?sort=` never reaches `orderBy()` unvalidated: a column that does
     * not exist fails the query on MySQL / PostgreSQL (SQLite tolerates
     * it), an undeclared real column would order the list by values no
     * field shows, and a field the user cannot see would tell the order of
     * its values.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     */
    protected function isSortableAttribute(string $resourceClass, Request $request, string $attribute): bool
    {
        return in_array($attribute, Field::sortableAttributes((new $resourceClass)->fields($request), $request), true);
    }

    /**
     * Order `$query` by the attribute the request's `?sort=` names, in its
     * `?direction=` (`asc` unless it says `desc`), when a list of
     * `$resourceClass` may be sorted by that attribute (see
     * isSortableAttribute()). Any other `?sort=` is ignored like an unknown
     * attribute and the query keeps its default order. The relationship
     * panels sort their rows this way.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     * @param  Builder<Model>  $query
     * @param  bool  $qualifyJsonPaths  Qualify a JSON path (`meta->code`) with the
     *                                  related table: a panel whose relation joins a table by
     *                                  nature (hasManyThrough, a pivot) sets it.
     */
    protected function applyRequestedSort(Request $request, Builder $query, string $resourceClass, bool $qualifyJsonPaths = false): void
    {
        $sort = $request->query('sort');

        if (! is_string($sort) || $sort === '' || ! $this->isSortableAttribute($resourceClass, $request, $sort)) {
            return;
        }

        // As written, not qualified: the column may be an alias the related
        // model selects (withCount, addSelect), which no table has. A column a
        // joined table shares (a hasManyThrough's intermediate, a pivot) is
        // not ambiguous here: the panels paginate through the relation, which
        // selects the related table's columns, and ORDER BY resolves a bare
        // name against the select list first. A JSON path compiles to an
        // expression, which ORDER BY does not resolve that way, so over a
        // joining relation it is qualified.
        $column = $qualifyJsonPaths && str_contains($sort, '->') ? $query->qualifyColumn($sort) : $sort;
        $query->orderBy($column, SortDirection::fromQuery($request->query('direction'))->value);
    }

    /**
     * The page size a relationship panel's list or attach search asks for
     * with `?per_page=`, clamped between 1 and 100 (`$default` when the
     * request sends none). The lower bound matters: Laravel ignores a
     * negative limit, so an unclamped `?per_page=-1` returned every row of
     * the relation (every attachable record, on an attach search) in one
     * response.
     */
    protected function requestedPerPage(Request $request, int $default): int
    {
        return max(1, min((int) $request->query('per_page', (string) $default), 100));
    }
}
