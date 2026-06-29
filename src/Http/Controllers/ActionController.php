<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
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
use Martis\Contracts\ActionContract;
use Martis\Contracts\FieldContract;
use Martis\Enums\ActionVisibility;
use Martis\Exceptions\MartisException;
use Martis\Fields\BelongsToMany as BelongsToManyField;
use Martis\Fields\Field as MartisField;
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

    /** Find a specific action by URI key. */
    private function findAction(Resource $resource, string $uriKey, Request $request): ?ActionContract
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
    // Pivot action routes (BelongsToMany context)
    // -------------------------------------------------------------------------

    /**
     * List pivot actions for a BelongsToMany relationship.
     *
     * GET /api/resources/{resource}/{id}/belongs-to-many/{relationship}/actions
     */
    public function pivotIndex(Request $request, string $resource, string|int $id, string $relationship): IlluminateJsonResponse
    {
        $resourceClass = $this->resolveResource($resource);
        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        $instance = new $resourceClass;
        $allActions = $this->resolveActions($instance, $request);

        $context = ActionVisibility::tryFrom((string) $request->query('context', 'detail'))
            ?? ActionVisibility::Detail;

        $pivotActions = array_values(array_filter($allActions, function (ActionContract $action) use ($context) {
            if (! ($action instanceof Action) || ! $action->isPivotAction()) {
                return false;
            }

            return match ($context) {
                ActionVisibility::Index => $action->isShownOnIndex(),
                ActionVisibility::Detail => $action->isShownOnDetail(),
                ActionVisibility::Inline => $action->isShownInline(),
            };
        }));

        /** @var array<string, mixed> $data */
        $data = ['actions' => array_map(fn (ActionContract $a) => $a->jsonSerialize(), $pivotActions)];

        return JsonResponse::make($data)->toResponse();
    }

    /**
     * Execute a pivot action on selected related records.
     *
     * POST /api/resources/{resource}/{id}/belongs-to-many/{relationship}/actions/{action}
     * Body: { "resources": [1, 2, 3], "fields": { "priority": "high" } }
     *
     * Related models are loaded through the parent relationship so each model
     * carries its pivot (e.g. $tag->pivot->priority).
     */
    public function executePivot(
        Request $request,
        string $resource,
        string|int $id,
        string $relationship,
        string $action,
    ): IlluminateJsonResponse {
        $resourceClass = $this->resolveResource($resource);
        if ($resourceClass === null) {
            return JsonErrorResponse::notFound("Resource [{$resource}] not found.")->toResponse();
        }

        $modelClass = $resourceClass::model();
        // Resolve the parent through the resource's indexQuery scope (tenant /
        // ownership filters) + a key match — never a bare find(). This keeps a
        // scoped-out id indistinguishable from a missing one (uniform 404, no
        // existence oracle across ownership boundaries) and enforces the scope
        // even for resources with no policy, matching resolveModels().
        $parentModel = $resourceClass::indexQuery($request, $modelClass::query())
            ->whereKey($id)
            ->first();
        if ($parentModel === null) {
            return JsonErrorResponse::notFound("Parent record [{$id}] not found.")->toResponse();
        }

        if (! method_exists($parentModel, $relationship)) {
            return JsonErrorResponse::notFound("Relationship [{$relationship}] not found on resource.")->toResponse();
        }

        // Authorize access to the PARENT record before anything else.
        // Without this a caller could run a pivot action against any
        // parent row by guessing its id (IDOR) — the relationship index
        // controllers gate the parent the same way.
        $parentInstance = new $resourceClass($parentModel);
        if (! $parentInstance->authorizedToView($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $instance = new $resourceClass;
        $actionInstance = $this->findAction($instance, $action, $request);
        if ($actionInstance === null) {
            return JsonErrorResponse::notFound("Action [{$action}] not found.")->toResponse();
        }

        if (! ($actionInstance instanceof Action) || ! $actionInstance->isPivotAction()) {
            return JsonErrorResponse::notFound("Action [{$action}] is not a pivot action.")->toResponse();
        }

        if (! $actionInstance->authorizedToSee($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        // Resolve pivot columns from the BelongsToMany field definition
        $pivotColumns = $this->resolvePivotColumns($parentInstance, $request, $relationship);

        /** @var list<int|string> $relatedIds */
        $relatedIds = $request->input('resources', []);

        if (empty($relatedIds) && ! $actionInstance->isStandalone()) {
            return JsonErrorResponse::validation(
                ['resources' => ['At least one related record must be selected.']],
            )->toResponse();
        }

        // Load related models via the relationship so pivot data is included
        $relation = $parentModel->{$relationship}();
        if (! empty($pivotColumns)) {
            $relation->withPivot($pivotColumns);
        }

        /** @var Collection<int, Model> $models */
        $models = $relation->whereIn($relation->getRelated()->getKeyName(), $relatedIds)->get();

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

        try {
            if ($actionInstance->getClosureHandler() !== null) {
                $result = ($actionInstance->getClosureHandler())($fields, $models);
            } else {
                $result = $actionInstance->handle($fields, $models);
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

            if ($e instanceof MartisException) {
                throw $e;
            }

            return JsonErrorResponse::serverError('Pivot action failed: '.$e->getMessage())->toResponse();
        }
    }

    /**
     * Resolve pivot column names from the BelongsToMany field definition on a resource.
     *
     * @return list<string>
     */
    private function resolvePivotColumns(Resource $parentInstance, Request $request, string $relationship): array
    {
        $fields = $parentInstance->fieldsForDetail($request);
        foreach ($fields as $field) {
            if ($field instanceof BelongsToManyField && $field->getRelationship() === $relationship) {
                return array_values(array_map(
                    fn (MartisField $f): string => $f->attribute(),
                    array_filter($field->getPivotFields(), fn ($f): bool => $f instanceof MartisField),
                ));
            }
        }

        return [];
    }
}
