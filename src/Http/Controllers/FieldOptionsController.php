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
 *   GET /martis/api/resources/{resource}/fields/{field}/options?search=&context=create|update&id=
 *   GET /martis/api/tools/{uriKey}/fields/{field}/options?search=
 *
 * A select in a Repeater row adds `repeater` (the Repeater's attribute) and
 * `repeatable` (the row type) to either route, and is found in that row.
 *
 * Response envelope: JsonResponse
 *   data.options — list<{label, value}> exactly as `Select::searchOptions()` returns it
 *
 * The field is located in the same field set the form was rendered from
 * (`fieldsForUpdate()`, or `fieldsForCreate()` and the inline-create modal's
 * `fieldsForInlineCreate()`, for a Resource; `fields()` for a Tool
 * implementing ProvidesFields), so a select that is not on the form
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

        $context = $request->query('context');
        $context = in_array($context, ['create', 'update'], true) ? $context : 'create';
        $id = $request->query('id');

        // Same gate as sync-field: the ability that matches the form the
        // select was rendered on (with the record bound in the update
        // context), so a view-only user cannot pull option lists meant for
        // editors.
        /** @var class-string<\Martis\Resource> $resourceClass */
        [$instance, $forbidden] = $this->resolveFormScopedResource(
            $request,
            $resourceClass,
            $context,
            is_string($id) ? $id : null,
        );
        if ($instance === null) {
            return $forbidden ?? JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        $select = $this->findFormField($instance, $request, $context, $field, [Select::class], repeaterRow: $this->repeaterRowOf($request));

        return $this->respond($request, $select instanceof Select ? $select : null, $field);
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

        $select = $this->findField(
            $this->inRepeaterRow(
                [fn (): array => $tool instanceof ProvidesFields ? $tool->fields($request) : []],
                $this->repeaterRowOf($request),
                $request,
            ),
            $field,
            [Select::class],
        );

        return $this->respond($request, $select instanceof Select ? $select : null, $field);
    }

    private function respond(Request $request, ?Select $select, string $attribute): IlluminateJsonResponse
    {
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
}
