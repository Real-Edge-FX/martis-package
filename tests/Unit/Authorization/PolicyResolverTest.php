<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Martis\Authorization\PolicyResolver;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class PolicyResolverTestPolicy {}

class PolicyResolverTestContext
{
    public function __construct(public string $level = 'none') {}
}

class PolicyResolverTestStatefulPolicy
{
    public function __construct(public PolicyResolverTestContext $context) {}
}

// ---------------------------------------------------------------------------
// Memoisation of the discovery outcome
// ---------------------------------------------------------------------------

it('runs discovery once per entity and memoises a null outcome', function () {
    $resolver = new PolicyResolver(new Container);
    $calls = 0;
    $discover = function () use (&$calls): ?string {
        $calls++;

        return null;
    };

    expect($resolver->resolve('App\\Martis\\Foo', $discover))->toBeNull()
        ->and($resolver->resolve('App\\Martis\\Foo', $discover))->toBeNull()
        ->and($calls)->toBe(1);
});

it('keys the memo by entity', function () {
    $resolver = new PolicyResolver(new Container);
    $calls = 0;
    $discover = function () use (&$calls): string {
        $calls++;

        return PolicyResolverTestPolicy::class;
    };

    $resolver->resolve('Entity\\A', $discover);
    $resolver->resolve('Entity\\B', $discover);
    $resolver->resolve('Entity\\A', $discover);

    expect($calls)->toBe(2);
});

it('flush() forgets every memoised outcome', function () {
    $resolver = new PolicyResolver(new Container);
    $calls = 0;
    $discover = function () use (&$calls): string {
        $calls++;

        return PolicyResolverTestPolicy::class;
    };

    $resolver->resolve('Entity\\A', $discover);
    $resolver->flush();
    $resolver->resolve('Entity\\A', $discover);

    expect($calls)->toBe(2);
});

// ---------------------------------------------------------------------------
// Instances come from the container on every call
// ---------------------------------------------------------------------------

it('resolves a fresh instance from the container on every call', function () {
    $resolver = new PolicyResolver(new Container);
    $discover = fn (): string => PolicyResolverTestPolicy::class;

    $first = $resolver->resolve('Entity', $discover);
    $second = $resolver->resolve('Entity', $discover);

    expect($first)->toBeInstanceOf(PolicyResolverTestPolicy::class)
        ->and($second)->toBeInstanceOf(PolicyResolverTestPolicy::class)
        ->and($second)->not->toBe($first);
});

it('honours a singleton binding for the policy class', function () {
    $container = new Container;
    $container->singleton(PolicyResolverTestPolicy::class);
    $resolver = new PolicyResolver($container);
    $discover = fn (): string => PolicyResolverTestPolicy::class;

    expect($resolver->resolve('Entity', $discover))->toBe($resolver->resolve('Entity', $discover));
});

it('sees a dependency rebound between two calls', function () {
    $container = new Container;
    $container->instance(PolicyResolverTestContext::class, new PolicyResolverTestContext('none'));
    $resolver = new PolicyResolver($container);
    $discover = fn (): string => PolicyResolverTestStatefulPolicy::class;

    /** @var PolicyResolverTestStatefulPolicy $first */
    $first = $resolver->resolve('Entity', $discover);

    $container->instance(PolicyResolverTestContext::class, new PolicyResolverTestContext('verified'));

    /** @var PolicyResolverTestStatefulPolicy $second */
    $second = $resolver->resolve('Entity', $discover);

    expect($first->context->level)->toBe('none')
        ->and($second->context->level)->toBe('verified');
});

it('throws when the container hands back something that is not an object', function () {
    $container = new Container;
    $container->bind('policy-that-is-a-string', fn (): string => 'nope');
    $resolver = new PolicyResolver($container);

    expect(fn () => $resolver->resolve('Entity', fn (): string => 'policy-that-is-a-string'))
        ->toThrow(LogicException::class, 'must resolve to an object');
});

it('falls back to the global container when none is injected', function () {
    $previous = Container::getInstance();
    $container = new Container;
    $container->singleton(PolicyResolverTestPolicy::class);
    Container::setInstance($container);

    try {
        $resolver = new PolicyResolver;
        $discover = fn (): string => PolicyResolverTestPolicy::class;

        expect($resolver->resolve('Entity', $discover))->toBe($container->make(PolicyResolverTestPolicy::class));
    } finally {
        Container::setInstance($previous);
    }
});
