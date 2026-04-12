<?php

namespace Martis\Exceptions;

/**
 * Thrown when a requested Martis resource (or its underlying model) is not found.
 *
 * Results in a 404 Not Found API response.
 */
class ResourceNotFoundException extends MartisException
{
    /** Create a resource-not-found exception. */
    public function __construct(
        string $message = 'Resource not found.',
        private readonly string $resourceKey = '',
        private readonly int|string $recordId = '',
    ) {
        parent::__construct($message, 'resource_not_found', [
            'resource' => $resourceKey,
            'id' => $recordId,
        ], 404);
    }

    /**
     * Build for a specific resource type and record ID.
     */
    public static function forRecord(string $resourceKey, int|string $id): self
    {
        return new self(
            "Record [{$id}] not found in resource [{$resourceKey}].",
            $resourceKey,
            $id,
        );
    }

    /**
     * Build for a missing resource definition (not a missing record).
     */
    public static function forResourceDefinition(string $resourceKey): self
    {
        return new self(
            "Resource [{$resourceKey}] is not registered.",
            $resourceKey,
        );
    }

    /**
     * Resource key.
     */
    public function resourceKey(): string
    {
        return $this->resourceKey;
    }

    /**
     * Record id.
     */
    public function recordId(): int|string
    {
        return $this->recordId;
    }
}
