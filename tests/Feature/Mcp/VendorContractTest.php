<?php

declare(strict_types=1);

use PhpMcp\Server\Transports\StreamableHttpServerTransport;

/**
 * Defensive guard for the assumption that we can subclass
 * `StreamableHttpServerTransport` and override `createRequestHandler`
 * to front a bearer-token check (see
 * `Martis\Mcp\Transport\AuthenticatedStreamableHttpTransport`).
 *
 * This test must run BEFORE any other Mcp/Transport tests so a vendor
 * major bump fails CI here with a clear message instead of cascading
 * through brittle integration tests.
 *
 * If this test fails after a `composer update php-mcp/server`, the
 * subclass strategy needs to be revisited (e.g. switch to documenting
 * "use a reverse proxy for auth" until upstream exposes a public
 * middleware hook).
 */
it('vendor exposes StreamableHttpServerTransport::createRequestHandler', function () {
    expect(method_exists(StreamableHttpServerTransport::class, 'createRequestHandler'))->toBeTrue(
        'php-mcp/server changed: createRequestHandler no longer exists. Revisit AuthenticatedStreamableHttpTransport.'
    );
});

it('createRequestHandler is overridable from a subclass', function () {
    $rm = new ReflectionMethod(StreamableHttpServerTransport::class, 'createRequestHandler');
    // private OR protected is fine — both let us override from a subclass.
    expect($rm->isFinal())->toBeFalse(
        'php-mcp/server changed: createRequestHandler is now final. Subclass strategy broken.'
    );
});

it('vendor constructor accepts the args our subclass passes through', function () {
    $rc = new ReflectionClass(StreamableHttpServerTransport::class);
    $ctor = $rc->getConstructor();
    expect($ctor)->not->toBeNull();

    $names = array_map(fn (ReflectionParameter $p) => $p->getName(), $ctor->getParameters());
    foreach (['host', 'port', 'mcpPath', 'sslContext', 'enableJsonResponse', 'stateless', 'eventStore'] as $expected) {
        expect($names)->toContain($expected);
    }
});

it('vendor has the private $socket property required by the listener swap', function () {
    $rc = new ReflectionClass(StreamableHttpServerTransport::class);

    expect($rc->hasProperty('socket'))->toBeTrue(
        'php-mcp/server changed: $socket property removed. Update AuthenticatedStreamableHttpTransport::listen().'
    );
    expect($rc->getProperty('socket')->isPrivate())->toBeTrue();
});

it('vendor has the private $http property required by the listener swap', function () {
    $rc = new ReflectionClass(StreamableHttpServerTransport::class);

    expect($rc->hasProperty('http'))->toBeTrue(
        'php-mcp/server changed: $http property removed. Update AuthenticatedStreamableHttpTransport::listen().'
    );
    expect($rc->getProperty('http')->isPrivate())->toBeTrue();
});
