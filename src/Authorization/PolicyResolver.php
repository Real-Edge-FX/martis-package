<?php

declare(strict_types=1);

namespace Martis\Authorization;

use Closure;
use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Lifecycle-scoped policy resolution for Resources and `HasPolicy`
 * entities (Tools, Dashboards).
 *
 * Resolution, meaning which policy class serves a given entity, walks a
 * few `class_exists()` checks and, for Resources, the Gate's policy map.
 * That outcome is stable for the lifetime of an application instance,
 * so it is memoised here per entity class. The policy *instance* is
 * not: every call asks the container for one, exactly as Laravel's Gate
 * does on each `$user->can()`, so container bindings (`singleton`,
 * `scoped`, a rebinding, `instance()`) are honoured on the next check
 * and a policy may safely receive request-scoped dependencies through
 * its constructor.
 *
 * The service provider binds this class with `app->scoped()`: the memo
 * lives for one request under Octane and one job under `queue:work`
 * (both call `forgetScopedInstances()`), and for one application
 * instance in a test suite that boots an application per test. Nothing
 * is static, so nothing survives the application that created it.
 * Before v1.36.0 the instances themselves were cached in static arrays,
 * which leaked the first application's state into every later one in
 * the same PHP process.
 */
class PolicyResolver
{
    /**
     * Memoised discovery outcome per entity class. `null` means
     * "resolution ran, no policy".
     *
     * @var array<class-string, class-string|null>
     */
    private array $resolved = [];

    private Container $container;

    /**
     * The container is optional so the class also auto-wires inside a
     * bare `Illuminate\Container\Container` (unit tests of a Resource
     * without a Laravel application); it then falls back to the global
     * instance.
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? ContainerInstance::getInstance();
    }

    /**
     * Resolve the policy instance for `$entity`, running `$discover`
     * only the first time the entity is seen by this resolver.
     *
     * @param  class-string  $entity  Concrete Resource / Tool / Dashboard class
     * @param  Closure(): (class-string|null)  $discover  Returns the policy class, or null for "no policy"
     * @return object|null The policy instance, or null when the entity has no policy
     */
    public function resolve(string $entity, Closure $discover): ?object
    {
        if (! array_key_exists($entity, $this->resolved)) {
            $this->resolved[$entity] = $discover();
        }

        $class = $this->resolved[$entity];

        if ($class === null) {
            return null;
        }

        $policy = $this->container->make($class);

        if (! is_object($policy)) {
            throw new LogicException(sprintf(
                'Policy [%s] for [%s] must resolve to an object from the container.',
                $class,
                $entity,
            ));
        }

        return $policy;
    }

    /**
     * Forget every memoised outcome. Only tests that swap Gate
     * registrations or `$policy` properties mid-test need it.
     */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
