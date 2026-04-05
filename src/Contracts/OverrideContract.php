<?php

namespace Martis\Contracts;

/**
 * Contract for page/component overrides.
 *
 * An override links a named React component to a resource page context
 * (create, update, detail, index). The component lives in the consumer
 * application and is resolved via the frontend componentRegistry.
 *
 * The params array lets the resource pass arbitrary data to the React
 * component, enabling conditional logic without modifying the package.
 */
interface OverrideContract
{
    /** The component key registered in the frontend componentRegistry. */
    public function component(): string;

    /**
     * Arbitrary parameters forwarded to the React component as props.
     *
     * @return array<string, mixed>
     */
    public function params(): array;

    /**
     * Serialize to a plain array for the JSON API.
     *
     * @return array{component: string, params: array<string, mixed>}
     */
    public function toArray(): array;
}
