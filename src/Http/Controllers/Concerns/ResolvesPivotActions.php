<?php

namespace Martis\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Martis\Actions\Action;
use Martis\FieldContext;
use Martis\Fields\BelongsToMany as BelongsToManyField;
use Martis\Fields\Field;
use Martis\Fields\MorphToMany as MorphToManyField;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Resource;
use Martis\Support\IndexScope;

/**
 * Resolve the relationship and the actions behind a pivot action route.
 *
 * The BelongsToMany and MorphToMany pivot action routes name the relationship
 * in the URL. It resolves only to a field the resource declares with the
 * route's type (a `BelongsToMany` field on `belongs-to-many`, a `MorphToMany`
 * field on `morph-to-many`), nested layouts included, and the parent model's
 * method of that name is called only once that field is found, so the URL
 * segment can never reach any other model method.
 *
 * The pivot actions of a panel are the ones its field declares with
 * `actions()`, then the resource actions flagged `pivotAction()`. A field
 * action wins over a resource action with the same URI key.
 *
 * For controllers that extend `MartisController`.
 */
trait ResolvesPivotActions
{
    /**
     * Resolve the parent record and the many-to-many relationship a pivot
     * action route names, or the 403/404 response that ends the request.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     * @param  class-string<BelongsToManyField|MorphToManyField>  $fieldClass
     * @return array{parentModel: Model, parentResource: \Martis\Resource, field: BelongsToManyField|MorphToManyField, relation: EloquentBelongsToMany<Model, Model>}|IlluminateJsonResponse
     */
    protected function resolvePivotRelationship(
        Request $request,
        string $resourceClass,
        int|string $id,
        string $relationship,
        string $fieldClass,
    ): array|IlluminateJsonResponse {
        if ($forbidden = $this->forbiddenUnlessAuthorizedToViewAny($request, $resourceClass)) {
            return $forbidden;
        }

        $modelClass = $resourceClass::model();
        // Resolve the parent through the resource's declarative scopes(), then
        // its indexQuery() (tenant / ownership filters, in the index's order,
        // grouped so the key binds to all of them) + a key match, never a
        // bare find(): a scoped-out id stays indistinguishable from a missing
        // one (uniform 404, no existence oracle), even for resources with no
        // policy.
        $parentModel = IndexScope::apply($request, $resourceClass, $modelClass::query())
            ->whereKey($id)
            ->first();
        if ($parentModel === null) {
            return JsonErrorResponse::notFound("Parent record [{$id}] not found.")->toResponse();
        }

        // Authorize the PARENT record before anything else, so a guessed
        // parent id never reaches its relations (IDOR).
        $parentResource = new $resourceClass($parentModel);
        if (! $parentResource->authorizedToView($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        // filterForContext flattens layout containers (Section/Panel/TabGroup),
        // so a relationship nested in one is still found. A relationship field
        // hidden for the parent record (canSeeForModel()) is not on its detail
        // page, so it answers like an undeclared one.
        $field = null;
        $detailFields = Field::filterForModel(
            Field::filterForContext($parentResource->resolveDetailFields($request), FieldContext::DETAIL),
            $request,
            $parentModel,
        );
        foreach ($detailFields as $candidate) {
            if ($candidate instanceof $fieldClass && $candidate->getRelationship() === $relationship) {
                $field = $candidate;
                break;
            }
        }

        if ($field === null && ($forbidden = $this->forbiddenWhenRelatedResourceClosed($request, $parentResource, $parentModel, $fieldClass, $relationship))) {
            return $forbidden;
        }

        if ($field === null || ! method_exists($parentModel, $relationship)) {
            return JsonErrorResponse::notFound("Relationship [{$relationship}] not found on resource.")->toResponse();
        }

        $relation = $parentModel->{$relationship}();
        if (! $relation instanceof EloquentBelongsToMany) {
            return JsonErrorResponse::notFound("Relationship [{$relationship}] not found on resource.")->toResponse();
        }

        return [
            'parentModel' => $parentModel,
            'parentResource' => $parentResource,
            'field' => $field,
            'relation' => $relation,
        ];
    }

    /**
     * The pivot actions of one many-to-many panel: the actions its field
     * declares, then the resource actions flagged `pivotAction()`, one per
     * URI key.
     *
     * @return list<Action>
     */
    protected function pivotActionsFor(
        Resource $parentResource,
        BelongsToManyField|MorphToManyField $field,
        Request $request,
    ): array {
        $actions = [];

        foreach ($field->pivotActions($request) as $action) {
            $actions[$action->uriKey()] ??= $action;
        }

        foreach ($parentResource->actions($request) as $action) {
            if ($action instanceof Action && $action->isPivotAction()) {
                $actions[$action->uriKey()] ??= $action;
            }
        }

        return array_values($actions);
    }

    /**
     * Resolve the relationship and the pivot action a pivot action route
     * names, or the 403/404 response that ends the request. The action's
     * fields, its run and anything else a route serves for one pivot action
     * share this gate: a user who cannot see the action does not reach it.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     * @param  class-string<BelongsToManyField|MorphToManyField>  $fieldClass
     * @return array{parentModel: Model, parentResource: \Martis\Resource, field: BelongsToManyField|MorphToManyField, relation: EloquentBelongsToMany<Model, Model>, action: Action}|IlluminateJsonResponse
     */
    protected function resolvePivotAction(
        Request $request,
        string $resourceClass,
        int|string $id,
        string $relationship,
        string $uriKey,
        string $fieldClass,
    ): array|IlluminateJsonResponse {
        $context = $this->resolvePivotRelationship($request, $resourceClass, $id, $relationship, $fieldClass);
        if ($context instanceof IlluminateJsonResponse) {
            return $context;
        }

        $action = $this->findPivotAction($context['parentResource'], $context['field'], $uriKey, $request);
        if ($action === null) {
            return JsonErrorResponse::notFound("Pivot action [{$uriKey}] not found.")->toResponse();
        }

        if (! $action->authorizedToSee($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        return [...$context, 'action' => $action];
    }

    /** Find a pivot action of one many-to-many panel by its URI key. */
    protected function findPivotAction(
        Resource $parentResource,
        BelongsToManyField|MorphToManyField $field,
        string $uriKey,
        Request $request,
    ): ?Action {
        foreach ($this->pivotActionsFor($parentResource, $field, $request) as $action) {
            if ($action->uriKey() === $uriKey) {
                return $action;
            }
        }

        return null;
    }
}
