<?php

namespace Martis;

use InvalidArgumentException;
use RuntimeException;

/**
 * Registry for all Martis resources registered in the application.
 *
 * The registry is bound as a singleton in the Laravel service container.
 * Resources can be registered explicitly via `Martis::resources([...])` in
 * a service provider, or automatically via ResourceDiscovery.
 *
 * Resources are indexed by their URI key for O(1) look-up by route segment.
 */
class ResourceRegistry
{
    /** @var array<string, class-string<\Martis\Resource>> uriKey → class */
    private array $resources = [];

    /**
     * Register a single resource class.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     *
     * @throws InvalidArgumentException When the class does not extend Resource.
     */
    public function register(string $resourceClass): void
    {
        if (! is_subclass_of($resourceClass, Resource::class)) {
            throw new InvalidArgumentException(
                "{$resourceClass} must extend ".Resource::class.'.'
            );
        }

        $uriKey = $resourceClass::uriKey();

        $this->resources[$uriKey] = $resourceClass;
    }

    /**
     * Register multiple resource classes at once.
     *
     * @param  list<class-string<\Martis\Resource>>  $resourceClasses
     */
    public function registerMany(array $resourceClasses): void
    {
        foreach ($resourceClasses as $resourceClass) {
            $this->register($resourceClass);
        }
    }

    /**
     * Retrieve a resource class by its URI key.
     *
     * @return class-string<\Martis\Resource>
     *
     * @throws RuntimeException When the URI key is not registered.
     */
    public function get(string $uriKey): string
    {
        if (! $this->has($uriKey)) {
            throw new RuntimeException(
                "No resource registered for URI key '{$uriKey}'."
            );
        }

        return $this->resources[$uriKey];
    }

    /**
     * Determine whether a resource with the given URI key is registered.
     */
    public function has(string $uriKey): bool
    {
        return isset($this->resources[$uriKey]);
    }

    /**
     * Return all registered resource classes indexed by URI key.
     *
     * @return array<string, class-string<\Martis\Resource>>
     */
    public function all(): array
    {
        return $this->resources;
    }

    /**
     * Return a flat list of all registered resource class names.
     *
     * @return list<class-string<\Martis\Resource>>
     */
    public function list(): array
    {
        return array_values($this->resources);
    }

    /**
     * Return the number of registered resources.
     */
    public function count(): int
    {
        return count($this->resources);
    }

    /**
     * Remove all registered resources.
     *
     * Intended for use in tests to reset registry state between test cases.
     */
    public function flush(): void
    {
        $this->resources = [];
    }
}
