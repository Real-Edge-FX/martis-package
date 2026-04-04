<?php

namespace Martis\Contracts;

/**
 * Contract for pagination metadata attached to collection responses.
 *
 * All paginated API responses produced by Martis carry a `meta` block that
 * conforms to this contract. The React frontend relies on the shape defined
 * here to render pagination controls without knowledge of the backend
 * pagination driver.
 */
interface PaginationContract
{
    /** Total number of items across all pages. */
    public function total(): int;

    /** Number of items per page. */
    public function perPage(): int;

    /** Current page number (1-based). */
    public function currentPage(): int;

    /** Total number of pages. */
    public function lastPage(): int;

    /** Index of the first item on the current page (1-based). */
    public function from(): ?int;

    /** Index of the last item on the current page (1-based). */
    public function to(): ?int;

    /**
     * Serialize pagination state for the JSON `meta` block.
     *
     * @return array{
     *   total: int,
     *   per_page: int,
     *   current_page: int,
     *   last_page: int,
     *   from: int|null,
     *   to: int|null,
     * }
     */
    public function toArray(): array;
}
