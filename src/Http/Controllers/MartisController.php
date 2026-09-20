<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
