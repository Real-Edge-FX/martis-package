<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Martis\Contracts\ProvidesFields;
use Martis\Fields\Select;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\MartisManager;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * Backs the server-side option search of `Select::searchOptionsUsing()`.
 *
 * Routes:
 *   GET /martis/api/resources/{resource}/fields/{field}/options?search=&context=create|update
 *   GET /martis/api/tools/{uriKey}/fields/{field}/options?search=
 *
 * Response envelope: JsonResponse
 *   data.options — list<{label, value}> exactly as `Select::searchOptions()` returns it
 *
 * The field is located in the same field set the form was rendered from
 * (`fieldsForCreate` / `fieldsForUpdate` for a Resource, `fields()` for a
 * Tool implementing ProvidesFields), so a select that is not on the form
 * cannot be probed, and the gates mirror the schema / sync-field
 * endpoints: the ability that matches the context for Resources, and
 * `MartisManager::findTool()` (404 when `canSee()` denies) for Tools.
 */
class FieldOptionsController extends MartisController
{
    /** Longest search term forwarded to a resolver. */
    public const MAX_TERM_LENGTH = 255;

    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly MartisManager $martis,
    ) {}

    /**
     * GET /api/resources/{resource}/fields/{field}/options
     */
    public function resource(Request $request, string $resource, string $field): IlluminateJsonResponse
    {
        [$resourceClass, $error] = $this->resolveResourceClass($this->registry, $resource);
        if ($error !== null) {
            return $error;
        }

        /** @var class-string<\Martis\Resource> $resourceClass */
        $instance = new $resourceClass;

        $context = $request->query('context');
        $context = in_array($context, ['create', 'update'], true) ? $context : 'create';

        // Same gate as sync-field: the ability that matches the form the
        // select was rendered on, so a view-only user cannot pull option
        // lists meant for editors.
        $authorized = $context === 'update'
            ? $instance->authorizedToUpdate($request)
            : $instance->authorizedToCreate($request);
        if (! $authorized) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $fields = $context === 'update'
            ? $instance->fieldsForUpdate($request)
            : $instance->fieldsForCreate($request);

        return $this->respond($request, $fields, $field);
    }

    /**
     * GET /api/tools/{uriKey}/fields/{field}/options
     */
    public function tool(Request $request, string $uriKey, string $field): IlluminateJsonResponse
    {
        $tool = $this->martis->findTool($request, $uriKey);
        if ($tool === null) {
            return JsonErrorResponse::notFound("Tool [{$uriKey}] not found.")->toResponse();
        }

        $fields = $tool instanceof ProvidesFields ? $tool->fields($request) : [];

        return $this->respond($request, $fields, $field);
    }

    /**
     * @param  iterable<mixed>  $fields
     */
    private function respond(Request $request, iterable $fields, string $attribute): IlluminateJsonResponse
    {
        $select = $this->locateSelect($fields, $attribute);
        if ($select === null) {
            return JsonErrorResponse::validation(['field' => ["Unknown select field [{$attribute}]."]])->toResponse();
        }
        if (! $select->hasRemoteOptionsSearch()) {
            return JsonErrorResponse::validation(['field' => ["Field [{$attribute}] does not search its options on the server."]])->toResponse();
        }

        $raw = $request->query('search', '');
        $term = is_string($raw) ? mb_substr(trim($raw), 0, self::MAX_TERM_LENGTH) : '';

        return JsonResponse::make(['options' => $select->searchOptions($term, $request)])->toResponse();
    }

    /**
     * @param  iterable<mixed>  $fields
     */
    private function locateSelect(iterable $fields, string $attribute): ?Select
    {
        foreach ($this->flatten($fields) as $candidate) {
            if ($candidate instanceof Select && $candidate->attribute() === $attribute) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Recursively flatten layout containers (Panel, Section, TabGroup) into a
     * single list of field instances. Mirrors SlugController::flatten().
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
