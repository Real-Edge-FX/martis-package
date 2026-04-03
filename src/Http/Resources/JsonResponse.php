<?php

namespace Martis\Http\Resources;

use Illuminate\Http\JsonResponse as IlluminateJsonResponse;

/**
 * Standard JSON response envelope for a single resource.
 *
 * Shape:
 * ```json
 * {
 *   "data": { ... },
 *   "meta": {},
 *   "links": {}
 * }
 * ```
 *
 * All Martis API endpoints that return a single resource MUST use this class
 * (or a subclass) so that the React frontend can rely on a consistent shape.
 */
final class JsonResponse
{
    /**
     * @param  array<string, mixed>  $data  Serialized resource data.
     * @param  array<string, mixed>  $meta  Optional metadata (e.g. timestamps, version).
     * @param  array<string, mixed>  $links  Optional HATEOAS links.
     */
    public function __construct(
        private readonly array $data,
        private readonly array $meta = [],
        private readonly array $links = [],
    ) {}

    /**
     * Static factory for ergonomic usage.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $links
     */
    public static function make(array $data, array $meta = [], array $links = []): self
    {
        return new self($data, $meta, $links);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'meta' => $this->meta,
            'links' => $this->links,
        ];
    }

    /**
     * Convert to an Illuminate JSON response with the given HTTP status code.
     */
    public function toResponse(int $status = 200): IlluminateJsonResponse
    {
        return new IlluminateJsonResponse($this->toArray(), $status);
    }
}
