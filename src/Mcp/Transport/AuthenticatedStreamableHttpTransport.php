<?php

declare(strict_types=1);

namespace Martis\Mcp\Transport;

use PhpMcp\Server\Transports\StreamableHttpServerTransport;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;

/**
 * Subclass of `StreamableHttpServerTransport` that fronts the request
 * pipeline with an optional bearer-token check.
 *
 * When `$token` is `null` or empty, the check is a transparent
 * pass-through and behaviour matches the vendor class exactly. When
 * `$token` is set, requests without `Authorization: Bearer <token>`
 * receive a 401 JSON response and never reach the parent handler.
 *
 * Subclasses vendor's `createRequestHandler()`. The method is
 * private upstream as of `php-mcp/server` ^3.3; the
 * `VendorContractTest` guards this assumption and fails CI on
 * upstream bumps that break it.
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

    protected function createRequestHandler(): callable
    {
        // The vendor method is private; we use reflection to retrieve it
        // without triggering PHP's private-access enforcement on parent::.
        // VendorContractTest guards this call site: if the upstream ever
        // makes the method final or removes it, CI fails there first.
        $rm = new \ReflectionMethod(StreamableHttpServerTransport::class, 'createRequestHandler');
        $rm->setAccessible(true);
        /** @var callable $next */
        $next = $rm->invoke($this);
        $token = $this->token;

        // No token configured → transparent pass-through.
        if ($token === null || $token === '') {
            return $next;
        }

        $expected = 'Bearer '.$token;

        return function (ServerRequestInterface $request) use ($next, $expected) {
            if ($request->getHeaderLine('Authorization') !== $expected) {
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
