<?php

namespace Martis\Http\Resources;

use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Martis\Contracts\PaginationContract;

/**
 * Standard JSON response envelope for paginated resource collections.
 *
 * Implements PaginationContract so pagination metadata is always consistent.
 */
final class JsonPaginatedResponse implements PaginationContract
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
     * Total.
     */
    public function total(): int
    {
        return $this->paginationMeta['total'];
    }

    /**
     * Per page.
     */
    public function perPage(): int
    {
        return $this->paginationMeta['per_page'];
    }

    /**
     * Current page.
     */
    public function currentPage(): int
    {
        return $this->paginationMeta['current_page'];
    }

    /**
     * Last page.
     */
    public function lastPage(): int
    {
        return $this->paginationMeta['last_page'];
    }

    /**
     * From.
     */
    public function from(): ?int
    {
        return $this->paginationMeta['from'];
    }

    /**
     * To.
     */
    public function to(): ?int
    {
        return $this->paginationMeta['to'];
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
