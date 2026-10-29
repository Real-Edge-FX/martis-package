<?php

namespace Martis\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EloquentHasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Contracts\ActionContract;
use Martis\FieldContext;
use Martis\Fields\BelongsToMany as BelongsToManyField;
use Martis\Fields\Field as MartisField;
use Martis\Fields\HasMany as HasManyField;
use Martis\Fields\MorphMany as MorphManyField;
use Martis\Fields\MorphToMany as MorphToManyField;
use Martis\Http\Requests\LensRequest;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\Support\IndexScope;
use Martis\Support\RelationScope;

/**
 * The records an Action runs on, looked up as the run looks them up.
 *
 * The run, the Action's fields endpoint and the endpoints of its relation
 * pickers all resolve the record the request names (`resources` on a run,
 * `resourceId` on the GETs) through the same scope and the same refusal, so
 * `selectedModel()` is the record a user may run the action on in every one
 * of them, and a record outside what the index, the lens, the panel or the
 * relationship lists is a 404 in all of them.
 *
 * For controllers that extend `MartisController` and hold a `$registry`
 * (`ResourceRegistry`); they also use `ResolvesPivotActions`.
 */
trait ResolvesActionRecords
{
    /**
     * The 404 that refuses a run on a record the action may not run on, or
     * `null` when it may run on all of `$models`: the predicate the rows'
     * `_actionAuthorization` map shows (see `actionRunDenial()`). A
     * standalone action resolves no model, so nothing is checked.
     *
     * @param  Collection<int, Model>  $models
     * @param  class-string<resource>  $resourceClass
     */
    protected function runDenied(Request $request, ActionContract $action, Collection $models, string $resourceClass): ?IlluminateJsonResponse
    {
        foreach ($models as $model) {
            if (($denial = $this->actionRunDenial($request, $action, $model, $this->actionPolicy($request, new $resourceClass($model)))) !== null) {
                return JsonErrorResponse::notFound($denial)->toResponse();
            }
        }

        return null;
    }

    /**
     * The record an Action's `selectedModel()` is during a run: the one
     * record the request names when it resolves, `null` for a standalone or
     * bulk run. A run that names several ids and finds one of them is bulk
     * all the same, as the modal it came from showed no record.
     *
     * @param  Collection<int, Model>  $models
     */
    protected function singleModel(Request $request, ActionContract $action, Collection $models): ?Model
    {
        if ($action->isStandalone() || $models->count() !== 1) {
            return null;
        }

        $raw = $request->input('resources', []);

        return is_array($raw) && count(array_unique(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            $raw,
        ))) === 1 ? $models->first() : null;
    }

    /**
     * The record an Action's fields request names with `resourceId`: the one
     * the run resolves for that id (see `resolveModels()`, so the index's
     * scopes, the lens's query or the relationship panel the `via*` params
     * name bound it), that the run lets the action run on (see
     * `runDenied()`). `null` when the request names none, and for a
     * standalone action, which runs on no record. A record that does not
     * resolve or that the run refuses is a 404, as the run answers, so the
     * fields endpoint never confirms or serves a record the run would not
     * run on. Only one id is accepted.
     */
    protected function selectedModelFor(Request $request, Resource $resource, ActionContract $action, ?Lens $lens): Model|IlluminateJsonResponse|null
    {
        $id = $request->query('resourceId');

        if ($action->isStandalone() || $id === null || $id === '') {
            return null;
        }

        if (! is_scalar($id)) {
            return JsonErrorResponse::notFound('The selected resource could not be found.')->toResponse();
        }

        // Read as the run reads its records.
        $request->merge(['resources' => [(string) $id]]);

        $models = $this->resolveModels($resource, $action, $request, $lens);

        if ($models instanceof IlluminateJsonResponse) {
            return $models;
        }

        if ($denied = $this->runDenied($request, $action, $models, $resource::class)) {
            return $denied;
        }

        return $models->first();
    }

    /**
     * The models an action runs on: the records the request's `resources`
     * name that resolve, as Nova runs an action on the selected records it
     * finds, or a 404 when none does (all outside the scope, trashed out of
     * reach or forged), so a run never handles nothing and answers "Done".
     * An action that is not standalone and names no record is a 422.
     *
     * A standalone action runs on no record, whatever the request names. The
     * records are looked up as the index lists them (see `indexRecords()`),
     * on a lens route as the lens lists them (see `lensRecords()`),
     * or, with `viaResource`, `viaResourceId` and `viaRelationship`, as the
     * relationship panel that sends them lists them (see `panelRecords()`,
     * as Nova runs a panel's action through its relationship): an id the
     * list does not hold does not resolve.
     *
     * @return Collection<int, Model>|IlluminateJsonResponse
     */
    protected function resolveModels(Resource $resource, ActionContract $action, Request $request, ?Lens $lens = null): Collection|IlluminateJsonResponse
    {
        /** @var Collection<int, Model> $empty */
        $empty = new Collection;

        // The relationship a panel's run names is checked first, a
        // standalone action's too: it runs on no record, but may read the
        // parent the request names.
        $via = $lens === null ? $this->viaRelation($request, $resource) : null;

        if ($via instanceof IlluminateJsonResponse) {
            return $via;
        }

        if ($action->isStandalone()) {
            return $empty;
        }

        $raw = $request->input('resources', []);
        /** @var list<int|string> $ids */
        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            is_array($raw) ? $raw : [],
        )));

        // An action that runs on records needs at least one, as a pivot
        // action does: an empty run would handle nothing and answer "Done".
        if ($ids === []) {
            return JsonErrorResponse::validation(
                ['resources' => ['At least one resource must be selected.']],
            )->toResponse();
        }

        // Scoped before selecting by id: without it an action could resolve
        // and act on records outside the user's visible scope just by
        // passing their ids (IDOR).
        $query = match (true) {
            $lens !== null => $this->lensRecords($request, $resource, $lens),
            $via === null => $this->indexRecords($request, $resource),
            default => $this->panelRecords($request, $resource, $via),
        };

        /** @var Collection<int, Model> $result */
        $result = $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids)->get();

        if ($result->isEmpty()) {
            return JsonErrorResponse::notFound('One or more selected resources could not be found.')->toResponse();
        }

        return $result;
    }

    /**
     * The records the resource's index lists: its declarative `scopes()`,
     * then `indexQuery()` (tenant / ownership filters), in the index's
     * order, with the trashed ones when it soft-deletes, since the index
     * lists them.
     *
     * @return Builder<Model>
     */
    protected function indexRecords(Request $request, Resource $resource): Builder
    {
        $modelClass = $resource::model();
        /** @var Model $modelInstance */
        $modelInstance = new $modelClass;

        // Grouped, so the ids bind to all of it: after an ungrouped `orWhere()`
        // they would bind to its last clause only.
        $query = IndexScope::apply($request, $resource::class, $modelInstance->newQuery());

        if ($resource::softDeletes()) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query;
    }

    /**
     * The records a lens lists, as Nova's `LensActionRequest` reads them:
     * the lens's `query()` run on a query of the resource's model, with the
     * lens's filters (the ones the user may see, from the request's
     * `?filters=`) and search. The query starts from what the resource's
     * index lists (its `scopes()` and `indexQuery()`), as the lens's page
     * does, unless the lens opts out (`Lens::$withoutIndexScope`), so an
     * action never runs on a record the index hides. The trashed records
     * the lens lists are included, as on the index. The rows are read by key
     * from the model's own table, so an action receives whole records even
     * from a lens that selects aggregates or joins another table.
     *
     * @return Builder<Model>
     */
    protected function lensRecords(Request $request, Resource $resource, Lens $lens): Builder
    {
        $modelClass = $resource::model();
        /** @var Model $modelInstance */
        $modelInstance = new $modelClass;

        $base = $modelInstance->newQuery();
        $query = $modelInstance->newQuery();

        if ($resource::softDeletes()) {
            $base->withoutGlobalScope(SoftDeletingScope::class);
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $lensRequest = LensRequest::fromRequest($request, $this->collectAuthorizedFilters($lens, $resource, $request));
        $listed = $this->runLensQuery($lens, $lensRequest, $this->lensBaseQuery($request, $resource::class, $lens, $base));

        if (! $listed instanceof Builder) {
            throw new \LogicException(sprintf(
                '%s::query() must return an Eloquent query, not a paginator, for its actions to run: the records an action runs on are the ones the query lists.',
                $lens::class,
            ));
        }

        $query->whereIn($modelInstance->getQualifiedKeyName(), RelationScope::keys($listed));

        return $query;
    }

    /**
     * The records a relationship panel lists: the relationship's own rows,
     * the global scopes it removes (`->withoutGlobalScope(ArchivedScope::class)`)
     * staying removed, narrowed by the resource's `scopes()` and
     * `indexQuery()` by key, as on the panel (see `RelationScope`), with the
     * trashed ones when the panel offers its trashed filter (`softDeletes()`
     * and `canViewTrashed()`) or the relationship keeps them (`withTrashed()`).
     * They are read from the resource's own table: a through relationship's
     * join would lay the intermediate's columns over the record's.
     *
     * @param  Relation<Model, Model, mixed>  $relation
     * @return Builder<Model>
     */
    protected function panelRecords(Request $request, Resource $resource, Relation $relation): Builder
    {
        $modelClass = $resource::model();
        /** @var Model $modelInstance */
        $modelInstance = new $modelClass;
        $keys = clone $relation->getQuery();

        $query = $modelInstance->newQuery()->withoutGlobalScopes($keys->removedScopes());

        if ($resource::softDeletes() && $resource::canViewTrashed()) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
            $keys->withoutGlobalScope(SoftDeletingScope::class);
        }

        // A key subquery for `IN (...)`: a relation's own order, limit and
        // offset (`->latest()->limit(2)`) would cut it short, and MySQL
        // refuses a LIMIT there (SQLSTATE 1235).
        $base = $keys->toBase()->reorder();
        $base->limit = null;
        $base->offset = null;

        $query->whereIn($modelInstance->getQualifiedKeyName(), $base->select($relation->getRelated()->getQualifiedKeyName()));

        RelationScope::constrainByKey($request, $query, $resource::class);

        return $query;
    }

    /**
     * The relationship named by `viaResource`, `viaResourceId` and
     * `viaRelationship`, or `null` when the request names none. The parent
     * is gated as its relationship panel is (its resource's `viewAny`, the
     * record's `view`), and the relationship must be a `HasMany`
     * (`HasManyThrough` included) or `MorphMany` field of the parent's
     * detail page listing `$resource`.
     *
     * @return Relation<Model, Model, mixed>|IlluminateJsonResponse|null
     */
    protected function viaRelation(Request $request, Resource $resource): Relation|IlluminateJsonResponse|null
    {
        $viaResource = $request->input('viaResource');
        $viaResourceId = $request->input('viaResourceId');
        $viaRelationship = $request->input('viaRelationship');

        if ($viaResource === null && $viaResourceId === null && $viaRelationship === null) {
            return null;
        }

        $notFound = JsonErrorResponse::notFound('Relationship not found.')->toResponse();

        if (! is_string($viaResource) || ! is_string($viaRelationship) || ! is_scalar($viaResourceId)) {
            return $notFound;
        }

        $parentResourceClass = $this->registry->has($viaResource) ? $this->registry->get($viaResource) : null;

        if ($parentResourceClass === null) {
            return $notFound;
        }

        if ($forbidden = $this->forbiddenUnlessAuthorizedToViewAny($request, $parentResourceClass)) {
            return $forbidden;
        }

        /** @var class-string<Model> $parentModelClass */
        $parentModelClass = $parentResourceClass::model();
        /** @phpstan-ignore staticMethod.notFound */
        $parentModel = $parentModelClass::find($viaResourceId);

        if (! $parentModel instanceof Model) {
            return $notFound;
        }

        $parent = new $parentResourceClass($parentModel);

        if (! $parent->authorizedToView($request)) {
            return $this->recordDenied($parent, 'view', 'Relationship not found.');
        }

        $fields = MartisField::filterForModel(
            MartisField::filterForContext($parent->resolveDetailFields($request), FieldContext::DETAIL),
            $request,
            $parentModel,
        );

        $declared = false;
        foreach ($fields as $field) {
            if (($field instanceof HasManyField || $field instanceof MorphManyField)
                && $field->getRelationship() === $viaRelationship
                && $field->getRelatedResourceKey() === $resource::uriKey()) {
                $declared = true;
                break;
            }
        }

        if (! $declared || ! method_exists($parentModel, $viaRelationship)) {
            return $notFound;
        }

        $relation = $parentModel->{$viaRelationship}();

        if (! $relation instanceof EloquentHasMany && ! $relation instanceof EloquentHasManyThrough && ! $relation instanceof EloquentMorphMany) {
            return $notFound;
        }

        return $relation;
    }

    /**
     * The related record a pivot action's fields request names with
     * `resourceId`, as the pivot run reads it: attached through the
     * relationship, within the related resource's scopes and the panel's
     * trashed filter (see `resolvePivotModels()`), loaded with its pivot, and
     * one the action may run on (see `pivotRunDenied()`). `null` when the
     * request names none, and for a standalone action. A record that does not
     * resolve or that the run refuses is a 404.
     *
     * @param  array{parentModel: Model, parentResource: resource, field: BelongsToManyField|MorphToManyField, relation: BelongsToMany<Model, Model, covariant \Illuminate\Database\Eloquent\Relations\Pivot, covariant string>, action: Action}  $resolved
     */
    protected function selectedPivotModelFor(Request $request, array $resolved): Model|IlluminateJsonResponse|null
    {
        $action = $resolved['action'];
        $id = $request->query('resourceId');

        if ($action->isStandalone() || $id === null || $id === '') {
            return null;
        }

        if (! is_scalar($id)) {
            return JsonErrorResponse::notFound('The selected resource could not be found.')->toResponse();
        }

        $models = $this->resolvePivotModels(
            $request,
            $resolved['field'],
            $resolved['relation'],
            $action,
            [(string) $id],
            $this->pivotColumnsOf($resolved['field']),
        );

        if ($models instanceof IlluminateJsonResponse) {
            return $models;
        }

        if ($denied = $this->pivotRunDenied($request, $action, $resolved['parentResource'], $models)) {
            return $denied;
        }

        return $models->first();
    }

    /**
     * The pivot columns a many-to-many field declares, which the run loads so
     * the action can read them even when the model's relation has no
     * `withPivot()`.
     *
     * @return list<string>
     */
    protected function pivotColumnsOf(BelongsToManyField|MorphToManyField $field): array
    {
        return array_values(array_map(
            fn (MartisField $f): string => $f->attribute(),
            array_filter($field->getPivotFields(), fn ($f): bool => $f instanceof MartisField),
        ));
    }

    /**
     * The related records a pivot run names, loaded through the parent's
     * relationship so each carries its pivot, or the 404 when none is found.
     *
     * The rows the panel lists: the relationship's own (the global scopes it
     * removes stay removed), narrowed by the related resource's scopes() and
     * indexQuery() by key, with the trashed ones when the panel offers its
     * trashed filter. An id the panel does not list does not resolve. A
     * standalone action resolves none and is not refused for it.
     *
     * @param  BelongsToMany<Model, Model, covariant \Illuminate\Database\Eloquent\Relations\Pivot, covariant string>  $relation
     * @param  list<string>  $relatedIds
     * @param  list<string>  $pivotColumns
     * @return Collection<int, Model>|IlluminateJsonResponse
     */
    protected function resolvePivotModels(
        Request $request,
        BelongsToManyField|MorphToManyField $field,
        BelongsToMany $relation,
        Action $action,
        array $relatedIds,
        array $pivotColumns,
    ): Collection|IlluminateJsonResponse {
        if ($pivotColumns !== []) {
            $relation->withPivot($pivotColumns);
        }

        $relatedResourceKey = $field->getRelatedResourceKey();
        if ($relatedResourceKey !== null && $this->registry->has($relatedResourceKey)) {
            /** @var class-string<resource> $relatedResourceClass */
            $relatedResourceClass = $this->registry->get($relatedResourceKey);

            if ($relatedResourceClass::softDeletes() && $relatedResourceClass::canViewTrashed()) {
                $relation->getQuery()->withoutGlobalScope(SoftDeletingScope::class);
            }

            RelationScope::constrainByKey($request, $relation->getQuery(), $relatedResourceClass);
        }

        // Qualified key: the relation joins the pivot table, and a pivot table
        // with its own `id` column would make a bare key ambiguous.
        /** @var Collection<int, Model> $models */
        $models = $relation->whereIn($relation->getRelated()->getQualifiedKeyName(), $relatedIds)->get();

        // Run on the ids attached, as execute() does; none attached is a 404
        // rather than a run on nothing.
        if (! $action->isStandalone() && $models->isEmpty()) {
            return JsonErrorResponse::notFound('One or more selected resources could not be found.')->toResponse();
        }

        return $models;
    }

    /**
     * The 404 that refuses a pivot run on a related record the action may not
     * run on, or `null` when it may run on all of `$models`.
     *
     * Per-model authorization mirrors the non-pivot execute() path: without
     * it, a visible pivot action ran on every selected related record
     * regardless of the action's own canRun rule. The policy execute()
     * checks for a resource action is asked against the record whose
     * relationship panel runs it: runDestructiveAction (falling back to
     * delete) for a destructive action, runAction (falling back to update)
     * otherwise.
     *
     * @param  Collection<int, Model>  $models
     */
    protected function pivotRunDenied(Request $request, Action $action, Resource $parentResource, Collection $models): ?IlluminateJsonResponse
    {
        if ($action->isStandalone()) {
            return null;
        }

        foreach ($models as $model) {
            if (! $action->authorizedToRun($request, $model)) {
                return JsonErrorResponse::notFound('You are not authorized to run this action on one or more selected resources.')->toResponse();
            }
        }

        $allowed = $action->isDestructive()
            ? $parentResource->authorizedToRunDestructiveAction($request)
            : $parentResource->authorizedToRunAction($request);

        if (! $allowed) {
            return JsonErrorResponse::notFound($action->isDestructive()
                ? 'You are not authorized to run this destructive action.'
                : 'You are not authorized to run this action.')->toResponse();
        }

        return null;
    }
}
