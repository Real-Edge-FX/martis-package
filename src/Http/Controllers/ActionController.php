<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Actions\Jobs\ExecuteAction;
use Martis\Actions\Jobs\ExecutePivotAction;
use Martis\Actions\PivotActionEventLog;
use Martis\Contracts\ActionContract;
use Martis\Contracts\FieldContract;
use Martis\Enums\ActionVisibility;
use Martis\Exceptions\MartisException;
use Martis\Fields\BelongsToMany as BelongsToManyField;
use Martis\Fields\Field as MartisField;
use Martis\Fields\MorphToMany as MorphToManyField;
use Martis\Http\Controllers\Concerns\ResolvesPivotActions;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;

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
        $resourceClass = $this->resolveResource($resource);

        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        $instance = new $resourceClass;
        $actions = $this->resolveActions($instance, $request);

        $context = ActionVisibility::tryFrom((string) $request->query('context', 'index'));
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
        $resourceClass = $this->resolveResource($resource);

        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        $instance = new $resourceClass;
        $actionInstance = $this->findAction($instance, $action, $request);

        if ($actionInstance === null) {
            return JsonErrorResponse::notFound("Action [{$action}] not found.")->toResponse();
        }

        // Gate the field-schema endpoint with the same check execute() uses —
        // a user who cannot see/run the action must not enumerate its input
        // field definitions.
        if (! $actionInstance->authorizedToSee($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $fields = $actionInstance->fields($request);

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
        $resourceClass = $this->resolveResource($resource);

        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        if ($forbidden = $this->forbiddenUnlessAuthorizedToViewAny($request, $resourceClass)) {
            return $forbidden;
        }

        $instance = new $resourceClass;
        $actionInstance = $this->findAction($instance, $action, $request);

        if ($actionInstance === null) {
            return JsonErrorResponse::notFound("Action [{$action}] not found.")->toResponse();
        }

        if (! $actionInstance->authorizedToSee($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $models = $this->resolveModels($instance, $request);

        if ($actionInstance->isSole() && $models->count() !== 1) {
            return JsonErrorResponse::validation(
                ['resources' => ['This action requires exactly one selected resource.']],
            )->toResponse();
        }

        if (! $actionInstance->isStandalone()) {
            foreach ($models as $model) {
                if (! $actionInstance->authorizedToRun($request, $model)) {
                    return JsonErrorResponse::notFound('You are not authorized to run this action on one or more selected resources.')->toResponse();
                }

                $resourceForModel = new $resourceClass($model);
                if ($actionInstance->isDestructive()) {
                    if (! $resourceForModel->authorizedToRunDestructiveAction($request)) {
                        return JsonErrorResponse::notFound('You are not authorized to run this destructive action.')->toResponse();
                    }
                } else {
                    if (! $resourceForModel->authorizedToRunAction($request)) {
                        return JsonErrorResponse::notFound('You are not authorized to run this action.')->toResponse();
                    }
                }
            }
        }

        $actionFields = $actionInstance->fields($request);
        if (! empty($actionFields)) {
            $rules = $this->buildFieldValidationRules($actionFields);
            /** @var array<string, mixed> $fieldData */
            $fieldData = $request->input('fields', []);
            $validator = Validator::make($fieldData, $rules, [], $this->buildFieldAttributeMap($actionFields));

            if ($validator->fails()) {
                return JsonErrorResponse::validation($validator->errors()->toArray())->toResponse();
            }
        }

        /** @var array<string, mixed> $rawFields */
        $rawFields = $request->input('fields', []);
        $fields = ActionFields::fromRequest($rawFields);

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
    private function resolveActions(Resource $resource, Request $request): array
    {
        $actions = $resource->actions($request);

        return array_values(array_filter(
            $actions,
            fn (ActionContract $action) => $action->authorizedToSee($request),
        ));
    }

    /**
     * Resolve Eloquent models from the request.
     *
     * @return Collection<int, Model>
     */
    private function resolveModels(Resource $resource, Request $request): Collection
    {
        /** @var list<int|string> $ids */
        $ids = $request->input('resources', []);

        if (empty($ids)) {
            /** @var Collection<int, Model> $empty */
            $empty = new Collection;

            return $empty;
        }

        $modelClass = $resource::model();
        /** @var Model $modelInstance */
        $modelInstance = new $modelClass;

        // Apply the resource's index scoping (tenant / ownership filters)
        // before selecting by id. Without this, an action could resolve and
        // act on records outside the user's visible scope just by passing
        // their ids (IDOR) — the same guard the index listing applies.
        $query = $resource::indexQuery($request, $modelInstance->newQuery());

        /** @var Collection<int, Model> $result */
        $result = $query->whereIn(
            $modelInstance->getKeyName(),
            $ids,
        )->get();

        return $result;
    }

    /**
     * Build validation rules from action fields.
     *
     * @param  list<FieldContract>  $fields
     * @return array<string, mixed>
     */
    private function buildFieldValidationRules(array $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $fieldRules = $field->buildRules();
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

        dispatch($job);

        if ($action->shouldLogEvents() && config('martis.action_events.enabled', true)) {
            $this->logActionEvent($action, $models, $request, 'queued', null, $snapshots);
        }

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

        $visibility = ActionVisibility::tryFrom((string) $request->query('context', 'detail'))
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

        $fields = $resolved['action']->fields($request);

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

        /** @var list<int|string> $relatedIds */
        $relatedIds = $request->input('resources', []);

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

        // Qualified key: the relation joins the pivot table, and a pivot table
        // with its own `id` column would make a bare key ambiguous.
        /** @var Collection<int, Model> $models */
        $models = $relation->whereIn($relation->getRelated()->getQualifiedKeyName(), $relatedIds)->get();

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

        $actionFields = $actionInstance->fields($request);
        if (! empty($actionFields)) {
            $rules = $this->buildFieldValidationRules($actionFields);
            /** @var array<string, mixed> $fieldData */
            $fieldData = $request->input('fields', []);
            $validator = Validator::make($fieldData, $rules, [], $this->buildFieldAttributeMap($actionFields));
            if ($validator->fails()) {
                return JsonErrorResponse::validation($validator->errors()->toArray())->toResponse();
            }
        }

        /** @var array<string, mixed> $rawFields */
        $rawFields = $request->input('fields', []);
        $fields = ActionFields::fromRequest($rawFields);

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
