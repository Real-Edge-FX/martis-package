<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Martis\Fields\Field;
use Martis\Fields\Slug;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Support\IndexScope;

/**
 * Backs the live collision-check for Slug fields.
 *
 * Route: GET /martis/api/resources/{resource}/slug-check/{field}
 * Query: value (required), id (optional: the record being edited; it is
 *   excluded from the uniqueness probe and its update form answers, when
 *   the user may update it)
 *
 * Gate: viewAny, then the ability to write the slug: update on the record
 * `id` names, otherwise create. The uniqueness probe reads the whole table
 * unless the Slug opts into the resource's index scope (`withinIndexScope()`).
 *
 * Response envelope: JsonResponse
 *   data.available  — bool; true if the value is free to use
 *   data.suggestion — string|null; when `available=false`, a non-colliding alternative
 *   data.reserved   — bool; true if the value is in the field's reserved list
 */
class SlugController extends MartisController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    public function check(Request $request, string $resource, string $field): IlluminateJsonResponse
    {
        [$resourceClass, $error] = $this->resolveResourceClass($this->registry, $resource);
        if ($error !== null) {
            return $error;
        }

        /** @var class-string<\Martis\Resource> $resourceClass */
        $resourceInstance = new $resourceClass;
        if (! $resourceInstance->authorizedToViewAny($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        // The Slug of the form the check comes from: the update form when
        // `id` names a record the user may update, the create forms
        // otherwise, then fields(), so a slug declared on one form only (the
        // inline-create modal included) resolves, and that form's separator
        // and reserved list apply.
        $rawId = $request->query('id');
        [$formInstance, $formContext] = $this->resolveFormFromRecordId($request, $resourceClass, is_string($rawId) ? $rawId : null);

        // The probe answers whether a value is taken, so it needs the ability
        // to write one: the update form is only resolved for a record the
        // user may update, and the create form needs the create ability. A
        // user who may only list the resource cannot use it to test which
        // slugs exist. An `id` the user may not update falls to the create
        // form, so it answers like a missing one.
        if ($formContext === 'create' && ! $formInstance->authorizedToCreate($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $slugField = $this->findFormField($formInstance, $request, $formContext, $field, [Slug::class], orFields: true);
        if (! $slugField instanceof Slug) {
            return JsonErrorResponse::notFound("Slug field '{$field}' not found.")->toResponse();
        }

        $rawValue = $request->query('value', '');
        $value = is_string($rawValue) ? trim($rawValue) : '';
        if ($value === '') {
            return (new JsonResponse([
                'available' => false,
                'suggestion' => null,
                'reserved' => false,
            ]))->toResponse();
        }

        $normalised = $slugField->generate($value);

        // Only the record being edited is left out of the uniqueness probe:
        // excluding a record the user may not update would tell its slug
        // apart (taken without the id, free with it).
        $excludeId = $formContext === 'update' ? $formInstance->getModel()?->getKey() : null;
        $excludeId = is_string($excludeId) || is_int($excludeId) ? $excludeId : null;

        if (in_array($normalised, $slugField->getReserved(), true)) {
            return (new JsonResponse([
                'available' => false,
                'suggestion' => $this->suggest($request, $resourceClass, $field, $normalised, $slugField, $excludeId),
                'reserved' => true,
            ]))->toResponse();
        }

        $isTaken = $this->isTaken($request, $resourceClass, $field, $normalised, $slugField, $excludeId);

        return (new JsonResponse([
            'available' => ! $isTaken,
            'suggestion' => $isTaken ? $this->suggest($request, $resourceClass, $field, $normalised, $slugField, $excludeId) : null,
            'reserved' => false,
        ]))->toResponse();
    }

    /**
     * Whether `$value` is taken. The probe reads the whole table, or, for a
     * Slug declared `withinIndexScope()`, the records the resource's
     * `scopes()` and `indexQuery()` let the user list (see IndexScope).
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     */
    private function isTaken(Request $request, string $resourceClass, string $field, string $value, Slug $slugField, int|string|null $excludeId): bool
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();
        $base = $modelClass::query();
        $query = ($slugField->isWithinIndexScope()
            ? IndexScope::apply($request, $resourceClass, $base)
            : $base)->where($field, $value);
        if ($excludeId !== null) {
            $keyName = (new $modelClass)->getKeyName();
            $query->where($keyName, '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Find a non-colliding suggestion by appending `-2`, `-3`, … (up to 50 attempts).
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     */
    private function suggest(Request $request, string $resourceClass, string $field, string $base, Slug $slugField, int|string|null $excludeId): ?string
    {
        for ($suffix = 2; $suffix <= 50; $suffix++) {
            $candidate = $base.$slugField->getSeparator().$suffix;
            if (in_array($candidate, $slugField->getReserved(), true)) {
                continue;
            }
            if (! $this->isTaken($request, $resourceClass, $field, $candidate, $slugField, $excludeId)) {
                return $candidate;
            }
        }

        return null;
    }
}
