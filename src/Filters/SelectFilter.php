<?php

namespace Martis\Filters;

/**
 * A dropdown filter that lets users pick a single value from a list.
 *
 * Override options() to provide the available choices:
 *
 *     public function options(Request $request): array
 *     {
 *         return [
 *             'Active'   => 'active',
 *             'Inactive' => 'inactive',
 *         ];
 *     }
 *
 * @phpstan-consistent-constructor
 */
abstract class SelectFilter extends Filter
{
    /** Whether the dropdown supports searching through options. */
    protected bool $searchable = false;

    public function filterType(): string
    {
        return 'select';
    }

    /**
     * Enable search within the filter dropdown.
     */
    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return array_merge(parent::meta(), [
            'searchable' => $this->searchable,
        ]);
    }
}
