<?php

namespace Martis\Http\Controllers;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Martis\Actions\Action;
use Martis\Actions\ActionEventRedactor;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Actions\Jobs\ExecuteAction;
use Martis\Actions\Jobs\ExecutePivotAction;
use Martis\Actions\PivotActionEventLog;
use Martis\Contracts\ActionContract;
use Martis\Contracts\FieldContract;
use Martis\Enums\ActionVisibility;
use Martis\Exceptions\MartisException;
use Martis\FieldContext;
use Martis\Fields\BelongsToMany as BelongsToManyField;
use Martis\Fields\Field as MartisField;
use Martis\Fields\HasMany as HasManyField;
use Martis\Fields\MorphMany as MorphManyField;
use Martis\Fields\MorphToMany as MorphToManyField;
use Martis\Fields\Repeater;
use Martis\Http\Controllers\Concerns\BuildsFieldRules;
use Martis\Http\Controllers\Concerns\ResolvesPivotActions;
use Martis\Http\Requests\LensRequest;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\Lenses\Lens;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Rules\RelatableWrite;
use Martis\Support\IndexScope;
use Martis\Support\RelationScope;

/**
 * Controller for action execution on Martis resources.
 *
 * Routes:
 *   GET    /api/resources/{resource}/actions                    -> list available actions
 *   GET    /api/resources/{resource}/actions/{action}/fields    -> get action fields
 *   POST   /api/resources/{resource}/actions/{action}           -> execute action (bulk)
 *   POST   /api/resources/{resource}/{id}/actions/{action}      -> execute action (single)
 */
class ActionController extends MartisController
{
    use BuildsFieldRules;
    use ResolvesPivotActions;

    /** Create the controller and inject the resource registry. */
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * Resolve a resource class by URI key, or return null.
     *
     * @return class-string<resource>|null
     */
    private function resolveResource(string $uriKey): ?string
    {
        if (! $this->registry->has($uriKey)) {
            return null;
        }

        /** @var class-string<resource> $class */
        $class = $this->registry->get($uriKey);

        return $class;
    }

    /**
     * List available actions for a resource.
     *
     * GET /api/resources/{resource}/actions
     */
    public function index(Request $request, string $resource): IlluminateJsonResponse
    {
        return $this->listActions($request, $resource, null);
    }

    /**
     * List the actions a lens runs: its own `actions()`, or the resource's
     * when it declares none.
     *
     * GET /api/resources/{resource}/lenses/{lens}/actions
     */
    public function lensIndex(Request $request, string $resource, string $lens): IlluminateJsonResponse
    {
        return $this->listActions($request, $resource, $lens);
    }

    private function listActions(Request $request, string $resource, ?string $lensKey): IlluminateJsonResponse
    {
        $resourceClass = $this->resolveResource($resource);

        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        $instance = new $resourceClass;
        $lens = $this->lensOf($instance, $lensKey, $request);

        if ($lens instanceof IlluminateJsonResponse) {
            return $lens;
        }

        $actions = $this->resolveActions($instance, $request, $lens);

        $rawContext = $request->query('context', 'index');
        $context = ActionVisibility::tryFrom(is_string($rawContext) ? $rawContext : 'index');
        $filtered = array_values(array_filter($actions, function (ActionContract $action) use ($context) {
            return match ($context) {
                ActionVisibility::Index => $action->isShownOnIndex(),
                ActionVisibility::Detail => $action->isShownOnDetail(),
                ActionVisibility::Inline => $action->isShownInline(),
                null => true,
            };
        }));

        /** @var array<string, mixed> $data */
        $data = ['actions' => array_map(fn (ActionContract $a) => $a->jsonSerialize(), $filtered)];

        return JsonResponse::make($data)->toResponse();
    }

    /**
     * Get fields for a specific action.
     *
     * GET /api/resources/{resource}/actions/{action}/fields
     */
    public function fields(Request $request, string $resource, string $action): IlluminateJsonResponse
    {
        return $this->actionFields($request, $resource, $action, null);
    }

    /**
     * Get the fields of an action a lens runs.
     *
     * GET /api/resources/{resource}/lenses/{lens}/actions/{action}/fields
     */
    public function lensFields(Request $request, string $resource, string $lens, string $action): IlluminateJsonResponse
    {
        return $this->actionFields($request, $resource, $action, $lens);
    }

    private function actionFields(Request $request, string $resource, string $action, ?string $lensKey): IlluminateJsonResponse
    {
        $resourceClass = $this->resolveResource($resource);

        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        $instance = new $resourceClass;
        $lens = $this->lensOf($instance, $lensKey, $request);

        if ($lens instanceof IlluminateJsonResponse) {
            return $lens;
        }

        $actionInstance = $this->findAction($instance, $action, $request, $lens);

        if ($actionInstance === null) {
            return JsonErrorResponse::notFound("Action [{$action}] not found.")->toResponse();
        }

        // Gate the field-schema endpoint with the same check execute() uses —
        // a user who cannot see/run the action must not enumerate its input
        // field definitions.
        if (! $actionInstance->authorizedToSee($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $fields = $this->visibleActionFields($actionInstance->fields($request), $request);

        /** @var array<string, mixed> $data */
        $data = ['fields' => array_map(fn (FieldContract $f) => $f->toArray(), $fields)];

        return JsonResponse::make($data)->toResponse();
    }

    /**
     * Execute an action on selected resources (bulk).
     *
     * POST /api/resources/{resource}/actions/{action}
     * Body: { "resources": [1, 2, 3], "fields": { ... }, "dryRun": false }
     */
    public function execute(Request $request, string $resource, string $action): IlluminateJsonResponse
    {
        return $this->run($request, $resource, $action, null);
    }

    /**
     * Execute an action a lens runs (its own `actions()`, or the resource's
     * when it declares none), as Nova's `LensActionController` does. The
     * records are resolved as on the resource's run: the resource's index
     * scopes bound them.
     *
     * POST /api/resources/{resource}/lenses/{lens}/actions/{action}
     * Body: { "resources": [1, 2, 3], "fields": { ... }, "dryRun": false }
     */
    public function lensExecute(Request $request, string $resource, string $lens, string $action): IlluminateJsonResponse
    {
        return $this->run($request, $resource, $action, $lens);
    }

    private function run(Request $request, string $resource, string $action, ?string $lensKey): IlluminateJsonResponse
    {
        $resourceClass = $this->resolveResource($resource);

        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        if ($forbidden = $this->forbiddenUnlessAuthorizedToViewAny($request, $resourceClass)) {
            return $forbidden;
        }

        $instance = new $resourceClass;
        $lens = $this->lensOf($instance, $lensKey, $request);

        if ($lens instanceof IlluminateJsonResponse) {
            return $lens;
        }

        $actionInstance = $this->findAction($instance, $action, $request, $lens);

        if ($actionInstance === null) {
            return JsonErrorResponse::notFound("Action [{$action}] not found.")->toResponse();
        }

        if (! $actionInstance->authorizedToSee($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $models = $this->resolveModels($instance, $actionInstance, $request, $lens);

        if ($models instanceof IlluminateJsonResponse) {
            return $models;
        }

        if ($actionInstance->isSole() && $models->count() !== 1) {
            return JsonErrorResponse::validation(
                ['resources' => ['This action requires exactly one selected resource.']],
            )->toResponse();
        }

        // The predicate the rows' `_actionAuthorization` map shows. A
        // standalone action resolves no model, so nothing is checked here.
        foreach ($models as $model) {
            if (($denial = $this->actionRunDenial($request, $actionInstance, $model, $this->actionPolicy($request, new $resourceClass($model)))) !== null) {
                return JsonErrorResponse::notFound($denial)->toResponse();
            }
        }

        $fields = $this->resolveActionFields($actionInstance->fields($request), $request, $resourceClass);

        if ($fields instanceof IlluminateJsonResponse) {
            return $fields;
        }

        if ($request->boolean('dryRun') && $actionInstance instanceof Action && $actionInstance->hasDryRun()) {
            $preview = $actionInstance->dryRun($fields, $models);

            return JsonResponse::make(['preview' => $preview])->toResponse();
        }

        // Capture model snapshots before action execution
        $snapshots = $models->mapWithKeys(fn (Model $m) => [$m->getKey() => $m->getAttributes()]);

        try {
            if ($actionInstance instanceof Action && $actionInstance->isQueued()) {
                return $this->dispatchQueuedAction($actionInstance, $fields, $models, $request, $instance, $snapshots);
            }

            if ($actionInstance instanceof Action && $actionInstance->getClosureHandler() !== null) {
                $result = ($actionInstance->getClosureHandler())($fields, $models);
            } else {
                $result = $actionInstance->handle($fields, $models);
            }

            // Refresh models to capture post-execution state
            $models->each(fn (Model $m) => $m->exists && $m->refresh());

            if ($actionInstance instanceof Action && $actionInstance->shouldLogEvents() && config('martis.action_events.enabled', true)) {
                $this->logActionEvent($actionInstance, $models, $request, 'completed', null, $snapshots);
            }

            if ($actionInstance instanceof Action) {
                $thenCallback = $actionInstance->getThenCallback();
                if ($thenCallback !== null) {
                    $thenCallback(collect([$result]));
                }
            }

            if ($result instanceof ActionResponse) {
                return JsonResponse::make($result->jsonSerialize())->toResponse();
            }

            return JsonResponse::make([
                'type' => 'message',
                'data' => ['message' => 'Action executed successfully.'],
            ])->toResponse();
        } catch (\Throwable $e) {
            Log::error('Action execution failed', [
                'action' => $action,
                'resource' => $resource,
                'error' => $e->getMessage(),
            ]);

            if ($actionInstance instanceof Action && $actionInstance->shouldLogEvents() && config('martis.action_events.enabled', true)) {
                $this->logActionEvent($actionInstance, $models, $request, 'failed', $e->getMessage(), $snapshots);
            }

            if ($e instanceof MartisException) {
                throw $e;
            }

            // Do not leak the raw exception message to the HTTP client — the
            // full detail is in the Log::error above (and the action event).
            return JsonErrorResponse::serverError('The action could not be completed due to an internal error.')->toResponse();
        }
    }

    /**
     * Execute an action on a single resource.
     *
     * POST /api/resources/{resource}/{id}/actions/{action}
     */
    public function executeSingle(Request $request, string $resource, string|int $id, string $action): IlluminateJsonResponse
    {
        $request->merge(['resources' => [$id]]);

        return $this->execute($request, $resource, $action);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve actions from the resource instance.
     *
     * @return list<ActionContract>
     */
    private function resolveActions(Resource $resource, Request $request, ?Lens $lens = null): array
    {
        $actions = $this->availableActions($resource, $request, $lens);

        return array_values(array_filter(
            $actions,
            fn (ActionContract $action) => $action->authorizedToSee($request),
        ));
    }

    /**
     * The lens a lens route names, or `null` on a resource route; a 404 or
     * 403 response when the resource has no such lens or the user may not
     * see it.
     */
    private function lensOf(Resource $resource, ?string $lensKey, Request $request): Lens|IlluminateJsonResponse|null
    {
        return $lensKey === null ? null : $this->resolveLens($resource, $lensKey, $request);
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
    private function resolveModels(Resource $resource, ActionContract $action, Request $request, ?Lens $lens = null): Collection|IlluminateJsonResponse
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
    private function indexRecords(Request $request, Resource $resource): Builder
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
     * `?filters=`) and search. The resource's `scopes()` and `indexQuery()`
     * do not apply, as they do not on the lens's page: the lens owns its
     * query. The trashed records the lens lists are included, as on the
     * index. The rows are read by key from the model's own table, so an
     * action receives whole records even from a lens that selects
     * aggregates or joins another table.
     *
     * @return Builder<Model>
     */
    private function lensRecords(Request $request, Resource $resource, Lens $lens): Builder
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
        $listed = $lens->query($lensRequest, $base);

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
    private function panelRecords(Request $request, Resource $resource, Relation $relation): Builder
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
    private function viaRelation(Request $request, Resource $resource): Relation|IlluminateJsonResponse|null
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

        $parentResourceClass = $this->resolveResource($viaResource);

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
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
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
     * The field values a run of an Action hands `handle()` (and `dryRun()`,
     * and the job of a queued Action), or the 422 of a failed validation.
     *
     * As Nova resolves an Action's fields, only the fields that take their
     * value from the request (see `takesValueFromRequest()`) are validated
     * and give `handle()` the value the request sends; see
     * `actionFieldValues()` for the others.
     *
     * @param  list<FieldContract>  $fields  The Action's fields.
     * @param  class-string<\Martis\Resource>  $sourceResourceClass  The resource the Action runs on (the parent resource for a pivot action), the source of the relatable hooks of its pickers
     */
    private function resolveActionFields(array $fields, Request $request, string $sourceResourceClass): ActionFields|IlluminateJsonResponse
    {
        $raw = $request->input('fields', []);
        /** @var array<string, mixed> $values */
        $values = is_array($raw) ? $raw : [];

        if ($fields !== []) {
            $writable = array_values(array_filter(
                $fields,
                fn (FieldContract $field): bool => $this->takesValueFromRequest($field, $request),
            ));

            $validator = $this->actionFieldsValidator($writable, $values, new RelatableWrite($request, $sourceResourceClass));

            if ($validator->fails()) {
                return JsonErrorResponse::validation($validator->errors()->toArray())->toResponse();
            }
        }

        return ActionFields::fromRequest($this->actionFieldValues($fields, $values, $request));
    }

    /**
     * The fields of an Action the modal renders: the ones the user may see
     * (`canSee()`), as Nova serialises an Action's fields.
     *
     * @param  list<FieldContract>  $fields
     * @return list<FieldContract>
     */
    private function visibleActionFields(array $fields, Request $request): array
    {
        return array_values(array_filter(
            $fields,
            fn (FieldContract $field): bool => $field->isAuthorizedToSee($request),
        ));
    }

    /**
     * Whether an Action field takes its value from the request: not when
     * the user cannot see it (`canSee()`), nor when it is readonly or
     * computed, as a field of a record never takes its value from the
     * request then.
     */
    private function takesValueFromRequest(FieldContract $field, Request $request): bool
    {
        if (! $field->isAuthorizedToSee($request)) {
            return false;
        }

        return ! $field instanceof MartisField || (! $field->isReadonly() && ! $field->isComputed());
    }

    /**
     * The values `handle()` receives: the value the request sends for each
     * field that takes its value from it (see `takesValueFromRequest()`),
     * with the rows of a Repeater written as new rows (an Action stores
     * nothing, see `Repeater::protectNewRows()`), and for every other field
     * its `default()`, or nothing when it has none (a computed field has
     * none). A key that names no field of the Action is kept as sent: a
     * custom component posts its own values that way.
     *
     * @param  list<FieldContract>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function actionFieldValues(array $fields, array $values, Request $request): array
    {
        foreach ($fields as $field) {
            $attribute = $field->attribute();

            if ($this->takesValueFromRequest($field, $request)) {
                if ($field instanceof Repeater && array_key_exists($attribute, $values)) {
                    $values[$attribute] = $field->protectNewRows($values[$attribute], $request);
                }

                continue;
            }

            unset($values[$attribute]);

            $default = $field instanceof MartisField && ! $field->isComputed() ? $field->getDefaultValue() : null;
            if ($default !== null) {
                $values[$attribute] = $default;
            }
        }

        return $values;
    }

    /**
     * The validator of an Action's fields: each field's rules under its
     * attribute, named by its label, and the fields inside every row a
     * Repeater among them receives (see
     * `BuildsFieldRules::buildNestedFieldValidation()`). A `BelongsTo`,
     * `MorphTo` or `Tag` also checks the record it names against the query
     * its picker lists (see `Martis\Rules\Relatable`), as Nova validates an
     * Action's fields with the fields' own rules.
     *
     * @param  list<FieldContract>  $fields
     * @param  array<string, mixed>  $fieldData
     */
    private function actionFieldsValidator(array $fields, array $fieldData, RelatableWrite $relatable): ValidatorContract
    {
        $nested = $this->buildNestedFieldValidation($fields, $fieldData, null);

        return Validator::make(
            $fieldData,
            $this->buildFieldValidationRules($fields, $relatable) + $nested['rules'],
            $nested['messages'],
            $this->buildFieldAttributeMap($fields) + $nested['attributes'],
        );
    }

    /**
     * Build validation rules from action fields.
     *
     * @param  list<FieldContract>  $fields
     * @return array<string, mixed>
     */
    private function buildFieldValidationRules(array $fields, RelatableWrite $relatable): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $fieldRules = $field->buildRules();
            $relatableRule = $relatable->ruleFor($field);
            if ($relatableRule !== null) {
                $fieldRules[] = $relatableRule;
            }
            if (! empty($fieldRules)) {
                $rules[$field->attribute()] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * Build attribute-name map so validation `:attribute` uses the field
     * label instead of the technical name.
     *
     * @param  list<FieldContract>  $fields
     * @return array<string, string>
     */
    private function buildFieldAttributeMap(array $fields): array
    {
        $attributes = [];
        foreach ($fields as $field) {
            if ($field instanceof MartisField) {
                $attributes[$field->attribute()] = $field->label();
            }
        }

        return $attributes;
    }

    /**
     * Dispatch a queued action as a Laravel job.
     *
     * @param  Collection<int, Model>  $models
     * @param  Collection<int|string, array<string, mixed>>  $snapshots
     */
    private function dispatchQueuedAction(Action $action, ActionFields $fields, Collection $models, Request $request, Resource $resource, Collection $snapshots): IlluminateJsonResponse
    {
        /** @var Model|null $firstModel */
        $firstModel = $models->first();
        $keyName = $firstModel?->getKeyName() ?? 'id';

        /** @var list<int|string> $modelIds */
        $modelIds = array_values($models->pluck($keyName)->all());

        /** @var class-string $modelClass */
        $modelClass = $resource::model();

        $job = new ExecuteAction(
            actionClass: get_class($action),
            fields: $fields->all(),
            modelIds: $modelIds,
            modelClass: $modelClass,
            userId: $request->user()?->getAuthIdentifier(),
        );

        if (property_exists($action, 'connection')) {
            $job->onConnection($action->connection);
        }
        if (property_exists($action, 'queue')) {
            $job->onQueue($action->queue);
        }

        // The `queued` events are written before the job is dispatched, as
        // a pivot action's are: the job settles them when it runs, and a job
        // that runs at once (the `sync` connection, a fast worker) would
        // otherwise find none, leaving the log at `queued` with no diff.
        if ($action->shouldLogEvents() && config('martis.action_events.enabled', true)) {
            $this->logActionEvent($action, $models, $request, 'queued', null, $snapshots);
        }

        dispatch($job);

        return JsonResponse::make([
            'type' => 'message',
            'data' => ['message' => 'Action has been queued for processing.'],
        ])->toResponse();
    }

    /**
     * Log action events to the database.
     *
     * Creates one event per model in the collection, capturing the
     * before/after attribute diff in the original/changes columns.
     *
     * @param  Collection<int, Model>  $models
     * @param  Collection<int|string, array<string, mixed>>|null  $snapshots  Model attributes captured before action execution
     */
    private function logActionEvent(Action $action, Collection $models, Request $request, string $status, ?string $exception = null, ?Collection $snapshots = null): void
    {
        try {
            $batchId = (string) Str::uuid();
            $userId = $request->user()?->getAuthIdentifier();
            $fieldData = $request->input('fields', []);

            if ($models->isEmpty()) {
                // Standalone action — no models to diff
                ActionEvent::create([
                    'batch_id' => $batchId,
                    'user_id' => $userId,
                    'name' => $action->name(),
                    'actionable_type' => null,
                    'actionable_id' => null,
                    'target_type' => null,
                    'target_id' => null,
                    'model_type' => null,
                    'model_id' => null,
                    'fields' => $fieldData,
                    'status' => $status,
                    'exception' => $exception ?? '',
                    'original' => [],
                    'changes' => [],
                ]);

                return;
            }

            foreach ($models as $model) {
                $key = $model->getKey();
                $originalAttrs = $snapshots?->get($key) ?? [];
                $currentAttrs = $model->getAttributes();

                // Compute diff: only attributes that actually changed
                $originalDiff = [];
                $changesDiff = [];

                foreach ($currentAttrs as $attr => $value) {
                    if (array_key_exists($attr, $originalAttrs) && $originalAttrs[$attr] != $value) {
                        $originalDiff[$attr] = $originalAttrs[$attr];
                        $changesDiff[$attr] = $value;
                    }
                }

                // The model's $hidden attributes are stored masked, as Nova
                // stores them (see ActionEventRedactor::maskHiddenAttributes()).
                $originalDiff = ActionEventRedactor::maskHiddenAttributes($originalDiff, $model);
                $changesDiff = ActionEventRedactor::maskHiddenAttributes($changesDiff, $model);

                ActionEvent::create([
                    'batch_id' => $batchId,
                    'user_id' => $userId,
                    'name' => $action->name(),
                    'actionable_type' => get_class($model),
                    'actionable_id' => $key,
                    'target_type' => get_class($model),
                    'target_id' => $key,
                    'model_type' => get_class($model),
                    'model_id' => $key,
                    'fields' => $fieldData,
                    'status' => $status,
                    'exception' => $exception ?? '',
                    'original' => $originalDiff,
                    'changes' => $changesDiff,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to log action event', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Pivot action routes (BelongsToMany and MorphToMany context)
    // -------------------------------------------------------------------------

    /**
     * List the pivot actions of a BelongsToMany relationship panel.
     *
     * GET /api/resources/{resource}/{id}/belongs-to-many/{relationship}/actions
     */
    public function pivotIndex(Request $request, string $resource, string|int $id, string $relationship): IlluminateJsonResponse
    {
        return $this->listPivotActions($request, $resource, $id, $relationship, BelongsToManyField::class);
    }

    /**
     * List the pivot actions of a MorphToMany relationship panel.
     *
     * GET /api/resources/{resource}/{id}/morph-to-many/{relationship}/actions
     */
    public function morphToManyPivotIndex(Request $request, string $resource, string|int $id, string $relationship): IlluminateJsonResponse
    {
        return $this->listPivotActions($request, $resource, $id, $relationship, MorphToManyField::class);
    }

    /**
     * Get the fields of a pivot action on a BelongsToMany relationship.
     *
     * GET /api/resources/{resource}/{id}/belongs-to-many/{relationship}/actions/{action}/fields
     */
    public function pivotFields(
        Request $request,
        string $resource,
        string|int $id,
        string $relationship,
        string $action,
    ): IlluminateJsonResponse {
        return $this->describePivotAction($request, $resource, $id, $relationship, $action, BelongsToManyField::class);
    }

    /**
     * Get the fields of a pivot action on a MorphToMany relationship.
     *
     * GET /api/resources/{resource}/{id}/morph-to-many/{relationship}/actions/{action}/fields
     */
    public function morphToManyPivotFields(
        Request $request,
        string $resource,
        string|int $id,
        string $relationship,
        string $action,
    ): IlluminateJsonResponse {
        return $this->describePivotAction($request, $resource, $id, $relationship, $action, MorphToManyField::class);
    }

    /**
     * Execute a pivot action on records attached through a BelongsToMany relationship.
     *
     * POST /api/resources/{resource}/{id}/belongs-to-many/{relationship}/actions/{action}
     * Body: { "resources": [1, 2, 3], "fields": { "priority": "high" } }
     */
    public function executePivot(
        Request $request,
        string $resource,
        string|int $id,
        string $relationship,
        string $action,
    ): IlluminateJsonResponse {
        return $this->runPivotAction($request, $resource, $id, $relationship, $action, BelongsToManyField::class);
    }

    /**
     * Execute a pivot action on records attached through a MorphToMany relationship.
     *
     * POST /api/resources/{resource}/{id}/morph-to-many/{relationship}/actions/{action}
     * Body: { "resources": [1, 2, 3], "fields": { "priority": "high" } }
     */
    public function executeMorphToManyPivot(
        Request $request,
        string $resource,
        string|int $id,
        string $relationship,
        string $action,
    ): IlluminateJsonResponse {
        return $this->runPivotAction($request, $resource, $id, $relationship, $action, MorphToManyField::class);
    }

    /**
     * List the pivot actions of one relationship panel the user may see in
     * the requested context (detail by default).
     *
     * @param  class-string<BelongsToManyField|MorphToManyField>  $fieldClass
     */
    private function listPivotActions(
        Request $request,
        string $resource,
        string|int $id,
        string $relationship,
        string $fieldClass,
    ): IlluminateJsonResponse {
        $resourceClass = $this->resolveResource($resource);
        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        $context = $this->resolvePivotRelationship($request, $resourceClass, $id, $relationship, $fieldClass);
        if ($context instanceof IlluminateJsonResponse) {
            return $context;
        }

        $rawContext = $request->query('context', 'detail');
        $visibility = ActionVisibility::tryFrom(is_string($rawContext) ? $rawContext : 'detail')
            ?? ActionVisibility::Detail;

        $pivotActions = array_values(array_filter(
            $this->pivotActionsFor($context['parentResource'], $context['field'], $request),
            fn (Action $action): bool => $action->authorizedToSee($request) && match ($visibility) {
                ActionVisibility::Index => $action->isShownOnIndex(),
                ActionVisibility::Detail => $action->isShownOnDetail(),
                ActionVisibility::Inline => $action->isShownInline(),
            },
        ));

        /** @var array<string, mixed> $data */
        $data = ['actions' => array_map(fn (Action $a) => $a->jsonSerialize(), $pivotActions)];

        return JsonResponse::make($data)->toResponse();
    }

    /**
     * Serve the input fields of one pivot action, gated like its execution.
     *
     * @param  class-string<BelongsToManyField|MorphToManyField>  $fieldClass
     */
    private function describePivotAction(
        Request $request,
        string $resource,
        string|int $id,
        string $relationship,
        string $action,
        string $fieldClass,
    ): IlluminateJsonResponse {
        $resourceClass = $this->resolveResource($resource);
        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        $resolved = $this->resolvePivotAction($request, $resourceClass, $id, $relationship, $action, $fieldClass);
        if ($resolved instanceof IlluminateJsonResponse) {
            return $resolved;
        }

        $fields = $this->visibleActionFields($resolved['action']->fields($request), $request);

        /** @var array<string, mixed> $data */
        $data = ['fields' => array_map(fn (FieldContract $f) => $f->toArray(), $fields)];

        return JsonResponse::make($data)->toResponse();
    }

    /**
     * Run a pivot action on the selected records attached through the
     * relationship. Related models are loaded through the parent relationship
     * so each one carries its pivot (e.g. $tag->pivot->priority).
     *
     * @param  class-string<BelongsToManyField|MorphToManyField>  $fieldClass
     */
    private function runPivotAction(
        Request $request,
        string $resource,
        string|int $id,
        string $relationship,
        string $action,
        string $fieldClass,
    ): IlluminateJsonResponse {
        $resourceClass = $this->resolveResource($resource);
        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        $resolved = $this->resolvePivotAction($request, $resourceClass, $id, $relationship, $action, $fieldClass);
        if ($resolved instanceof IlluminateJsonResponse) {
            return $resolved;
        }

        ['parentModel' => $parentModel, 'parentResource' => $parentResource, 'field' => $field, 'relation' => $relation, 'action' => $actionInstance] = $resolved;

        // A standalone action runs on no record, as on the resource's own
        // endpoint; the others run on the ids sent that are attached.
        $rawIds = $actionInstance->isStandalone() ? [] : $request->input('resources', []);
        /** @var list<string> $relatedIds */
        $relatedIds = array_values(array_unique(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            is_array($rawIds) ? $rawIds : [],
        )));

        if (empty($relatedIds) && ! $actionInstance->isStandalone()) {
            return JsonErrorResponse::validation(
                ['resources' => ['At least one related record must be selected.']],
            )->toResponse();
        }

        // Load the pivot columns the field declares, so the action can read
        // them even when the model's relation has no withPivot().
        $pivotColumns = array_values(array_map(
            fn (MartisField $f): string => $f->attribute(),
            array_filter($field->getPivotFields(), fn ($f): bool => $f instanceof MartisField),
        ));
        if ($pivotColumns !== []) {
            $relation->withPivot($pivotColumns);
        }

        // The rows the panel lists: the relationship's own (the global scopes
        // it removes stay removed), narrowed by the related resource's
        // scopes() and indexQuery() by key, with the trashed ones when the
        // panel offers its trashed filter. An id the panel does not list does
        // not resolve.
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
        if (! $actionInstance->isStandalone() && $models->isEmpty()) {
            return JsonErrorResponse::notFound('One or more selected resources could not be found.')->toResponse();
        }

        if ($actionInstance->isSole() && $models->count() !== 1) {
            return JsonErrorResponse::validation(
                ['resources' => ['This action requires exactly one selected resource.']],
            )->toResponse();
        }

        // Per-model authorization — mirrors the non-pivot execute() path.
        // Without this, a visible pivot action ran on every selected
        // related record regardless of the action's own canRun rule.
        if (! $actionInstance->isStandalone()) {
            foreach ($models as $model) {
                if (! $actionInstance->authorizedToRun($request, $model)) {
                    return JsonErrorResponse::notFound('You are not authorized to run this action on one or more selected resources.')->toResponse();
                }
            }

            // The policy execute() checks for a resource action, against the
            // record whose relationship panel runs it: runDestructiveAction
            // (falling back to delete) for a destructive action, runAction
            // (falling back to update) otherwise.
            $allowed = $actionInstance->isDestructive()
                ? $parentResource->authorizedToRunDestructiveAction($request)
                : $parentResource->authorizedToRunAction($request);

            if (! $allowed) {
                return JsonErrorResponse::notFound($actionInstance->isDestructive()
                    ? 'You are not authorized to run this destructive action.'
                    : 'You are not authorized to run this action.')->toResponse();
            }
        }

        $fields = $this->resolveActionFields($actionInstance->fields($request), $request, $resourceClass);

        if ($fields instanceof IlluminateJsonResponse) {
            return $fields;
        }

        /** @var array<string, mixed> $rawFields */
        $rawFields = $request->input('fields', []);

        if ($request->boolean('dryRun') && $actionInstance->hasDryRun()) {
            return JsonResponse::make(['preview' => $actionInstance->dryRun($fields, $models)])->toResponse();
        }

        $logEvents = $actionInstance->shouldLogEvents() && (bool) config('martis.action_events.enabled', true);

        if ($actionInstance->isQueued()) {
            return $this->dispatchQueuedPivotAction($actionInstance, $fields, $parentModel, $relation, $relationship, $models, $pivotColumns, $request, $logEvents);
        }

        $before = $logEvents ? PivotActionEventLog::pivotRows($relation, $models) : [];
        $userId = $request->user()?->getAuthIdentifier();

        try {
            if ($actionInstance->getClosureHandler() !== null) {
                $result = ($actionInstance->getClosureHandler())($fields, $models);
            } else {
                $result = $actionInstance->handle($fields, $models);
            }

            if ($logEvents) {
                PivotActionEventLog::record($actionInstance, $parentModel, $relation, $models, $userId, $rawFields, 'completed', null, $before, PivotActionEventLog::pivotRows($relation, $models));
            }

            $thenCallback = $actionInstance->getThenCallback();
            if ($thenCallback !== null) {
                $thenCallback(collect([$result]));
            }

            if ($result instanceof ActionResponse) {
                return JsonResponse::make($result->jsonSerialize())->toResponse();
            }

            return JsonResponse::make([
                'type' => 'message',
                'data' => ['message' => 'Pivot action executed successfully.'],
            ])->toResponse();
        } catch (\Throwable $e) {
            Log::error('Pivot action execution failed', [
                'action' => $action,
                'resource' => $resource,
                'relationship' => $relationship,
                'error' => $e->getMessage(),
            ]);

            if ($logEvents) {
                PivotActionEventLog::record($actionInstance, $parentModel, $relation, $models, $userId, $rawFields, 'failed', $e->getMessage(), $before, PivotActionEventLog::pivotRows($relation, $models));
            }

            if ($e instanceof MartisException) {
                throw $e;
            }

            // Like the resource action path: the detail is in the log and the
            // action event, never in the HTTP response.
            return JsonErrorResponse::serverError('The action could not be completed due to an internal error.')->toResponse();
        }
    }

    /**
     * Dispatch a queued pivot action as a job that reloads the selected rows
     * through the parent's relationship, and log it as queued.
     *
     * @param  BelongsToMany<Model, Model, covariant \Illuminate\Database\Eloquent\Relations\Pivot, covariant string>  $relation
     * @param  Collection<int, Model>  $models
     * @param  list<string>  $pivotColumns
     */
    private function dispatchQueuedPivotAction(
        Action $action,
        ActionFields $fields,
        Model $parentModel,
        BelongsToMany $relation,
        string $relationship,
        Collection $models,
        array $pivotColumns,
        Request $request,
        bool $logEvents,
    ): IlluminateJsonResponse {
        /** @var list<int|string> $relatedIds */
        $relatedIds = array_values($models->map(fn (Model $model): int|string => $model->getKey())->all());
        $userId = $request->user()?->getAuthIdentifier();

        $job = new ExecutePivotAction(
            actionClass: get_class($action),
            fields: $fields->all(),
            parentModelClass: get_class($parentModel),
            parentId: $parentModel->getKey(),
            relationship: $relationship,
            relatedIds: $relatedIds,
            pivotColumns: $pivotColumns,
            userId: $userId,
            logEvents: $logEvents,
        );

        if (property_exists($action, 'connection')) {
            $job->onConnection($action->connection);
        }
        if (property_exists($action, 'queue')) {
            $job->onQueue($action->queue);
        }

        // Written before the dispatch: a sync queue runs the job at once and
        // settles these rows.
        if ($logEvents) {
            /** @var array<string, mixed> $rawFields */
            $rawFields = $request->input('fields', []);
            PivotActionEventLog::record($action, $parentModel, $relation, $models, $userId, $rawFields, 'queued');
        }

        dispatch($job);

        return JsonResponse::make([
            'type' => 'message',
            'data' => ['message' => 'Action has been queued for processing.'],
        ])->toResponse();
    }
}
