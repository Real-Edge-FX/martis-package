<?php

namespace Martis\Contracts;

use Martis\FieldContext;

/**
 * Contract for layout containers (Panel, Tab, TabGroup).
 *
 * Layout containers group FieldContract instances into visual structures
 * without themselves being fields. They know how to serialize themselves
 * and how to filter their nested fields for a given context.
 */
interface LayoutContract
{
    /**
     * Serialize the layout container for the JSON API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Return a new layout instance containing only fields visible in the given context.
     * Returns null when the container has no visible fields in that context.
     */
    public function filterForContext(FieldContext $context): ?static;

    /**
     * Return all nested FieldContract instances (flattened, all depths).
     *
     * Used to extract fields for validation and model filling.
     *
     * @return list<FieldContract>
     */
    public function flattenFields(): array;
}
