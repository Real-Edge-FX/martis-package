<?php

declare(strict_types=1);

namespace Martis\Mcp\Transport;

use PhpMcp\Server\Transports\StreamableHttpServerTransport;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response as HttpResponse;
use React\Socket\SocketServer;

/**
 * Subclass of `StreamableHttpServerTransport` that fronts the request
 * pipeline with an optional bearer-token check.
 *
 * When `$token` is `null` or empty, the check is a transparent
 * pass-through and behaviour matches the vendor class exactly. When
 * `$token` is set, requests without `Authorization: Bearer <token>`
 * receive a 401 JSON response and never reach the parent handler.
 *
 * Implementation strategy:
 *   `StreamableHttpServerTransport::createRequestHandler()` is `private`,
 *   so PHP does NOT dispatch it via virtual method calls from `parent::listen()`.
 *   To inject the token check, `listen()` is overridden to:
 *     1. Call `parent::listen()` which creates the socket and starts the parent
 *        HttpServer with the vendor's unprotected handler.
 *     2. Use reflection to retrieve the private `$socket` property.
 *     3. Remove the vendor's `connection` event listener from the socket.
 *     4. Create a new HttpServer with our wrapped handler and re-listen on
 *        the same socket (the port binding is already done).
 *
 *   `VendorContractTest` guards the assumptions that `createRequestHandler`
 *   still exists and is not `final`, and that the constructor signature is
 *   stable.
 */
final class AuthenticatedStreamableHttpTransport extends StreamableHttpServerTransport
{
    public function __construct(
        string $host,
        int $port,
        string $mcpPath,
        bool $stateless,
        private readonly ?string $token,
    ) {
        parent::__construct(
            host: $host,
            port: $port,
            mcpPath: $mcpPath,
            sslContext: null,
            enableJsonResponse: true,
            stateless: $stateless,
            eventStore: null,
        );
    }

    /**
     * Boots the parent transport, then swaps the socket's connection listener
     * so all incoming requests pass through our bearer-token check first.
     */
    public function listen(): void
    {
        parent::listen();

        if ($this->token === null || $this->token === '') {
            // No token — parent handler is already correct, nothing to wrap.
            return;
        }

        // Retrieve the private $socket from the parent class via reflection.
        $rc = new \ReflectionClass(StreamableHttpServerTransport::class);
        $propSocket = $rc->getProperty('socket');
        $propSocket->setAccessible(true);
        /** @var SocketServer $socket */
        $socket = $propSocket->getValue($this);

        // Remove the vendor's connection listener and install our wrapped one.
        $socket->removeAllListeners('connection');

        // Retrieve the vendor's handler to delegate to after auth passes.
        $rm = new \ReflectionMethod(StreamableHttpServerTransport::class, 'createRequestHandler');
        $rm->setAccessible(true);
        /** @var callable $vendorHandler */
        $vendorHandler = $rm->invoke($this);

        $expected = 'Bearer '.$this->token;

        $wrappedHandler = function (ServerRequestInterface $request) use ($vendorHandler, $expected) {
            if (! hash_equals($expected, $request->getHeaderLine('Authorization'))) {
                return new HttpResponse(
                    401,
                    ['Content-Type' => 'application/json'],
                    (string) json_encode(['error' => 'unauthorized']),
                );
            }

            return $vendorHandler($request);
        };

        $newHttp = new HttpServer($this->loop, $wrappedHandler);
        $newHttp->listen($socket);

        // Update the private $http property so close() can reach the new server.
        $propHttp = $rc->getProperty('http');
        $propHttp->setAccessible(true);
        $propHttp->setValue($this, $newHttp);
    }

    /**
     * Kept protected for unit-level coverage in `AuthenticatedStreamableHttpTransportTest`.
     *
     * During real `listen()` calls this method is NOT invoked by the vendor's
     * `parent::listen()` (it's dispatched as private there). This method is
     * only reachable via reflection from the unit test, and provides a thin
     * wrapper around the vendor handler to let the tests verify the token logic
     * without spinning up a real socket.
     *
     * See class docblock for the full implementation strategy.
     */
    protected function createRequestHandler(): callable
    {
        $rm = new \ReflectionMethod(StreamableHttpServerTransport::class, 'createRequestHandler');
        $rm->setAccessible(true);
        /** @var callable $next */
        $next = $rm->invoke($this);
        $token = $this->token;

        if ($token === null || $token === '') {
            return $next;
        }

        $expected = 'Bearer '.$token;

        return function (ServerRequestInterface $request) use ($next, $expected) {
            if (! hash_equals($expected, $request->getHeaderLine('Authorization'))) {
                return new HttpResponse(
                    401,
                    ['Content-Type' => 'application/json'],
                    (string) json_encode(['error' => 'unauthorized']),
                );
            }

            return $next($request);
        };
    }
}
