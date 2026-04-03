<?php

namespace Martis\Http\Resources;

use Illuminate\Http\JsonResponse as IlluminateJsonResponse;

/**
 * Standard JSON response envelope for paginated resource collections.
 *
 * Shape:
 * ```json
 * {
 *   "data": [ ... ],
 *   "meta": {
 *     "current_page": 1,
 *     "from": 1,
 *     "last_page": 5,
 *     "per_page": 15,
 *     "to": 15,
 *     "total": 75
 *   },
 *   "links": {
 *     "first": "...",
 *     "last":  "...",
 *     "prev":  null,
 *     "next":  "..."
 *   }
 * }
 * ```
 *
 * The `meta` and `links` blocks follow the JSON:API Pagination spec so that
 * future adoption of the spec requires no breaking changes.
 */
final class JsonPaginatedResponse
{
    /**
     * @param  list<array<string, mixed>>  $data
     * @param  array{
     *   current_page: int,
     *   from: int|null,
     *   last_page: int,
     *   per_page: int,
     *   to: int|null,
     *   total: int,
     * }  $paginationMeta
     * @param  array{first: string|null, last: string|null, prev: string|null, next: string|null}  $links
     * @param  array<string, mixed>  $extraMeta  Any additional meta fields to merge.
     */
    public function __construct(
        private readonly array $data,
        private readonly array $paginationMeta,
        private readonly array $links = ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
        private readonly array $extraMeta = [],
    ) {}

    /**
     * Static factory.
     *
     * @param  list<array<string, mixed>>  $data
     * @param  array{
     *   current_page: int,
     *   from: int|null,
     *   last_page: int,
     *   per_page: int,
     *   to: int|null,
     *   total: int,
     * }  $paginationMeta
     * @param  array{first: string|null, last: string|null, prev: string|null, next: string|null}  $links
     * @param  array<string, mixed>  $extraMeta
     */
    public static function make(
        array $data,
        array $paginationMeta,
        array $links = ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
        array $extraMeta = [],
    ): self {
        return new self($data, $paginationMeta, $links, $extraMeta);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'meta' => array_merge($this->paginationMeta, $this->extraMeta),
            'links' => $this->links,
        ];
    }

    /**
     * Convert to an Illuminate JSON response (always 200).
     */
    public function toResponse(): IlluminateJsonResponse
    {
        return new IlluminateJsonResponse($this->toArray(), 200);
    }
}
