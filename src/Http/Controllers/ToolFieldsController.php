<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
use Martis\Contracts\ProvidesFields;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Http\Resources\JsonResponse;
use Martis\MartisManager;

/**
 * Serves a Tool's field definitions, mirroring
 * `ActionController::fields()` for Tools that opt into
 * `Martis\Contracts\ProvidesFields`.
 *
 * Routes:
 *   GET /api/tools/{uriKey}/fields -> serialized Tool field definitions
 */
class ToolFieldsController extends MartisController
{
    public function __construct(
        private readonly MartisManager $martis,
    ) {}

    /**
     * Get fields for a specific Tool.
     *
     * GET /api/tools/{uriKey}/fields
     *
     * Returns 404 when the key is unknown OR when the tool's
     * `canSee()` callback denies the user — `MartisManager::findTool()`
     * already applies `authorizedToSee()`, so these cases are
     * intentionally indistinguishable and the field definitions never
     * leak to an unauthorised caller.
     */
    public function fields(Request $request, string $uriKey): IlluminateJsonResponse
    {
        $tool = $this->martis->findTool($request, $uriKey);

        if ($tool === null) {
            return JsonErrorResponse::notFound("Tool [{$uriKey}] not found.")->toResponse();
        }

        $fields = $tool instanceof ProvidesFields ? $tool->fields($request) : [];

        /** @var array<string, mixed> $data */
        $data = ['fields' => array_map(fn (FieldContract $f) => $f->toArray(), $fields)];

        return JsonResponse::make($data)->toResponse();
    }
}
