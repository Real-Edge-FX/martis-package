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
     * The registered resources that expose a model class, in registration
     * order. Empty when none does; more than one when several resources are
     * defined over the same model.
     *
     * @return list<class-string<\Martis\Resource>>
     */
    public function forModel(string $modelClass): array
    {
        $modelClass = ltrim($modelClass, '\\');

        return array_values(array_filter(
            $this->resources,
            static fn (string $resourceClass): bool => ltrim($resourceClass::model(), '\\') === $modelClass,
        ));
    }

    /**
     * The registered resources that stand for a model class: the routable
     * ones when at least one resource over the model is routable, otherwise
     * every resource over it, in registration order.
     *
     * A headless resource (`routable(): false`) over a model that also has a
     * page of its own is a narrower view of it, such as a relation target
     * for users who may not list the model's own resource. It never takes
     * the model's place in the lookups that read this list: the audit log's
     * target label and link, the resource a `BelongsTo` without
     * `relatedResource()` checks its value against, and the Actions panel's
     * resource. A model with headless resources only keeps them.
     *
     * @return list<class-string<\Martis\Resource>>
     */
    public function preferredForModel(string $modelClass): array
    {
        return $this->routableFirst($this->forModel($modelClass));
    }

    /**
     * Like {@see preferredForModel()}, but for the resources over a model
     * class or any subclass of it: how the audit log's own resource is found,
     * since a host may point its resource at a subclass of `ActionEvent`.
     *
     * @return list<class-string<\Martis\Resource>>
     */
    public function preferredForModelOrSubclass(string $modelClass): array
    {
        return $this->routableFirst(array_values(array_filter(
            $this->resources,
            static fn (string $resourceClass): bool => is_a($resourceClass::model(), $modelClass, true),
        )));
    }

    /**
     * The routable resources of the list when at least one is routable,
     * otherwise the whole list, in the order given.
     *
     * @param  list<class-string<\Martis\Resource>>  $resources
     * @return list<class-string<\Martis\Resource>>
     */
    private function routableFirst(array $resources): array
    {
        $routable = array_values(array_filter(
            $resources,
            static fn (string $resourceClass): bool => $resourceClass::routable(),
        ));

        return $routable !== [] ? $routable : $resources;
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
