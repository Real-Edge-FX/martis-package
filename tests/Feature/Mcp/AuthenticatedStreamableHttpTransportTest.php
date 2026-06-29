<?php

declare(strict_types=1);

use Martis\Mcp\Transport\AuthenticatedStreamableHttpTransport;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\ServerRequest;

/**
 * Unit-level coverage for the bearer-token check. We do not start a
 * real socket; instead we reach `createRequestHandler()` via reflection
 * (it's protected in our subclass, private in vendor's parent) and
 * call the returned closure with a fake ServerRequest. This avoids
 * the ReactPHP event loop cost while still exercising the same code
 * path the transport runs at request time.
 */
function invokeHandler(AuthenticatedStreamableHttpTransport $t, ServerRequestInterface $request): mixed
{
    $rm = new ReflectionMethod(AuthenticatedStreamableHttpTransport::class, 'createRequestHandler');
    $rm->setAccessible(true);
    $handler = $rm->invoke($t);

    return $handler($request);
}

it('returns 401 when token is set and Authorization header is missing', function () {
    $t = new AuthenticatedStreamableHttpTransport(
        host: '127.0.0.1', port: 1, mcpPath: '/mcp', stateless: true, token: 'secret-token-abc',
    );

    $request = new ServerRequest('POST', 'http://localhost/mcp');
    $response = invokeHandler($t, $request);

    expect($response->getStatusCode())->toBe(401);
    expect((string) $response->getBody())->toContain('unauthorized');
});

it('returns 401 when token is set and Authorization header is wrong', function () {
    $t = new AuthenticatedStreamableHttpTransport(
        host: '127.0.0.1', port: 1, mcpPath: '/mcp', stateless: true, token: 'secret-token-abc',
    );

    $request = (new ServerRequest('POST', 'http://localhost/mcp'))
        ->withHeader('Authorization', 'Bearer wrong-token');
    $response = invokeHandler($t, $request);

    expect($response->getStatusCode())->toBe(401);
});

it('delegates to parent when token is null (no auth required)', function () {
    $t = new AuthenticatedStreamableHttpTransport(
        host: '127.0.0.1', port: 1, mcpPath: '/mcp', stateless: true, token: null,
    );

    // No Authorization header. Parent handler should run; we expect a
    // non-401 response (vendor returns 404 for the wrong path, but the
    // key signal is "parent ran, our middleware did not 401").
    $request = new ServerRequest('GET', 'http://localhost/some-other-path');
    $response = invokeHandler($t, $request);

    expect($response->getStatusCode())->not->toBe(401);
});

it('delegates to parent when token is empty string (no auth required)', function () {
    $t = new AuthenticatedStreamableHttpTransport(
        host: '127.0.0.1', port: 1, mcpPath: '/mcp', stateless: true, token: '',
    );

    $request = new ServerRequest('GET', 'http://localhost/some-other-path');
    $response = invokeHandler($t, $request);

    expect($response->getStatusCode())->not->toBe(401);
});

it('passes through when token is set and Authorization matches exactly', function () {
    $t = new AuthenticatedStreamableHttpTransport(
        host: '127.0.0.1', port: 1, mcpPath: '/mcp', stateless: true, token: 'secret-token-abc',
    );

    $request = (new ServerRequest('GET', 'http://localhost/some-other-path'))
        ->withHeader('Authorization', 'Bearer secret-token-abc');
    $response = invokeHandler($t, $request);

    expect($response->getStatusCode())->not->toBe(401);
});

it('returns 401 for a correct prefix followed by extra characters (timing-safe boundary)', function () {
    // hash_equals must reject strings that share a prefix with the real token.
    // A naive short-circuit comparison would also 401 here, but this test
    // documents the expected behaviour and guards against any future regression
    // that might use str_starts_with() or substring checks instead.
    $t = new AuthenticatedStreamableHttpTransport(
        host: '127.0.0.1', port: 1, mcpPath: '/mcp', stateless: true, token: 'secret',
    );

    $request = (new ServerRequest('POST', 'http://localhost/mcp'))
        ->withHeader('Authorization', 'Bearer secret-EXTRA');
    $response = invokeHandler($t, $request);

    expect($response->getStatusCode())->toBe(401);
});

it('returns 401 for a token that is a truncated prefix of the real token', function () {
    $t = new AuthenticatedStreamableHttpTransport(
        host: '127.0.0.1', port: 1, mcpPath: '/mcp', stateless: true, token: 'secret-token-abc',
    );

    // Partial match — only a prefix of the real token is supplied.
    $request = (new ServerRequest('POST', 'http://localhost/mcp'))
        ->withHeader('Authorization', 'Bearer secret');
    $response = invokeHandler($t, $request);

    expect($response->getStatusCode())->toBe(401);
});

it('returns 401 when Authorization is one character short of the token (timing-safe guard)', function () {
    $t = new AuthenticatedStreamableHttpTransport(
        host: '127.0.0.1', port: 1, mcpPath: '/mcp', stateless: true, token: 'secret-token-abc',
    );

    $request = (new ServerRequest('POST', 'http://localhost/mcp'))
        ->withHeader('Authorization', 'Bearer secret-token-ab'); // one char short — differing lengths
    $response = invokeHandler($t, $request);

    expect($response->getStatusCode())->toBe(401);
});

it('returns 401 when Authorization has a trailing null byte appended (timing-safe guard)', function () {
    $t = new AuthenticatedStreamableHttpTransport(
        host: '127.0.0.1', port: 1, mcpPath: '/mcp', stateless: true, token: 'secret-token-abc',
    );

    $request = (new ServerRequest('POST', 'http://localhost/mcp'))
        ->withHeader('Authorization', "Bearer secret-token-abc\x00");
    $response = invokeHandler($t, $request);

    expect($response->getStatusCode())->toBe(401);
});
