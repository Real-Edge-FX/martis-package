<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Actions\Jobs\ExecuteAction;
use Martis\Contracts\ActionContract;
use Martis\Contracts\FieldContract;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse;
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

        /** @var string $context */
        $context = $request->query('context', 'index');
        $filtered = array_values(array_filter($actions, function (ActionContract $action) use ($context) {
            return match ($context) {
                'index' => $action->isShownOnIndex(),
                'detail' => $action->isShownOnDetail(),
                'inline' => $action->isShownInline(),
                default => true,
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
            return JsonErrorResponse::notFound('This action is unauthorized.')->toResponse();
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
            $validator = Validator::make($fieldData, $rules);

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

        try {
            if ($actionInstance instanceof Action && $actionInstance->isQueued()) {
                return $this->dispatchQueuedAction($actionInstance, $fields, $models, $request, $instance);
            }

            if ($actionInstance instanceof Action && $actionInstance->getClosureHandler() !== null) {
                $result = ($actionInstance->getClosureHandler())($fields, $models);
            } else {
                $result = $actionInstance->handle($fields, $models);
            }

            if ($actionInstance instanceof Action && $actionInstance->shouldLogEvents()) {
                $this->logActionEvent($actionInstance, $models, $request, 'completed');
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

            if ($actionInstance instanceof Action && $actionInstance->shouldLogEvents()) {
                $this->logActionEvent($actionInstance, $models, $request, 'failed', $e->getMessage());
            }

            return JsonErrorResponse::serverError('Action failed: '.$e->getMessage())->toResponse();
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

        /** @var Collection<int, Model> $result */
        $result = $modelInstance->newQuery()->whereIn(
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
     * Dispatch a queued action as a Laravel job.
     *
     * @param  Collection<int, Model>  $models
     */
    private function dispatchQueuedAction(Action $action, ActionFields $fields, Collection $models, Request $request, Resource $resource): IlluminateJsonResponse
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

        if ($action->shouldLogEvents()) {
            $this->logActionEvent($action, $models, $request, 'queued');
        }

        return JsonResponse::make([
            'type' => 'message',
            'data' => ['message' => 'Action has been queued for processing.'],
        ])->toResponse();
    }

    /**
     * Log an action event to the database.
     *
     * @param  Collection<int, Model>  $models
     */
    private function logActionEvent(Action $action, Collection $models, Request $request, string $status, ?string $exception = null): void
    {
        try {
            /** @var Model|null $firstModel */
            $firstModel = $models->first();

            DB::table('action_events')->insert([
                'batch_id' => (string) Str::uuid(),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'name' => $action->name(),
                'actionable_type' => $firstModel !== null ? get_class($firstModel) : null,
                'actionable_id' => $models->count() === 1 ? $firstModel?->getKey() : null,
                'target_type' => $firstModel !== null ? get_class($firstModel) : null,
                'target_id' => $models->count() === 1 ? $firstModel?->getKey() : null,
                'model_type' => $firstModel !== null ? get_class($firstModel) : null,
                'model_id' => $models->count() === 1 ? $firstModel?->getKey() : null,
                'fields' => json_encode($request->input('fields', [])),
                'status' => $status,
                'exception' => $exception ?? '',
                'original' => '{}',
                'changes' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log action event', ['error' => $e->getMessage()]);
        }
    }
}
