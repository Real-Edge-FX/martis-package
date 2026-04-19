<?php

namespace Martis\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Martis\Fields\Field;
use Martis\Fields\Slug;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\ResourceRegistry;

/**
 * Backs the live collision-check for Slug fields.
 *
 * Route: GET /martis/api/resources/{resource}/slug-check/{field}
 * Query: value (required), id (optional — excluded from the uniqueness probe)
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

        $slugField = $this->findSlugField($resourceInstance, $field, $request);
        if ($slugField === null) {
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

        if (in_array($normalised, $slugField->getReserved(), true)) {
            return (new JsonResponse([
                'available' => false,
                'suggestion' => $this->suggest($resourceClass, $field, $normalised, $slugField, $request),
                'reserved' => true,
            ]))->toResponse();
        }

        $excludeId = $request->query('id');
        $isTaken = $this->isTaken($resourceClass, $field, $normalised, is_string($excludeId) || is_int($excludeId) ? $excludeId : null);

        return (new JsonResponse([
            'available' => ! $isTaken,
            'suggestion' => $isTaken ? $this->suggest($resourceClass, $field, $normalised, $slugField, $request) : null,
            'reserved' => false,
        ]))->toResponse();
    }

    /**
     * @param  class-string<\Martis\Resource>  $resourceClass
     */
    private function isTaken(string $resourceClass, string $field, string $value, int|string|null $excludeId): bool
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::model();
        $query = $modelClass::query()->where($field, $value);
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
    private function suggest(string $resourceClass, string $field, string $base, Slug $slugField, Request $request): ?string
    {
        $excludeId = $request->query('id');
        $excludeId = is_string($excludeId) || is_int($excludeId) ? $excludeId : null;

        for ($suffix = 2; $suffix <= 50; $suffix++) {
            $candidate = $base.$slugField->getSeparator().$suffix;
            if (in_array($candidate, $slugField->getReserved(), true)) {
                continue;
            }
            if (! $this->isTaken($resourceClass, $field, $candidate, $excludeId)) {
                return $candidate;
            }
        }

        return null;
    }

    private function findSlugField(object $resourceInstance, string $attribute, Request $request): ?Slug
    {
        // Search across every context where Slug may legitimately appear — a
        // resource may only expose the Slug field on create/update forms.
        $candidateMethods = ['fields', 'fieldsForCreate', 'fieldsForUpdate'];
        foreach ($candidateMethods as $method) {
            if (! method_exists($resourceInstance, $method)) {
                continue;
            }
            /** @var list<mixed> $fields */
            $fields = $resourceInstance->{$method}($request);
            foreach ($this->flatten($fields) as $f) {
                if ($f instanceof Slug && $f->attribute() === $attribute) {
                    return $f;
                }
            }
        }

        return null;
    }

    /**
     * Recursively flatten layout containers (Panel, Section, TabGroup) into a
     * single list of Field instances.
     *
     * @param  iterable<mixed>  $items
     * @return iterable<object>
     */
    private function flatten(iterable $items): iterable
    {
        foreach ($items as $item) {
            if (is_object($item) && method_exists($item, 'flattenFields')) {
                yield from $item->flattenFields();
                continue;
            }
            if (is_object($item)) {
                yield $item;
            }
        }
    }
}
