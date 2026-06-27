<?php

declare(strict_types=1);

namespace Martis\Mcp\Transport;

use Martis\Mcp\Tools;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

/**
 * Standalone ReactPHP HTTP server that exposes `GET /health` on a
 * dedicated port. Designed to share an event loop with the main MCP
 * transport so the whole `martis:mcp-serve --transport=http` flow
 * runs in one PHP process.
 *
 * Off by default. Activated only when the operator sets
 * `MARTIS_MCP_HEALTH_PORT` (or passes `--health-port` to
 * `martis:mcp-serve`).
 *
 * Payload:
 *
 *   {
 *     "status": "ok",            // "ok" when Tools::enabled() else "disabled"
 *     "version": "1.13.0",
 *     "transport": "http",
 *     "uptime_s": 1234,
 *     "tool_count": 3            // 3 when enabled, 0 when disabled
 *   }
 */
final class HealthServer
{
    private float $bootedAt;

    private ?SocketServer $socket = null;

    private ?HttpServer $http = null;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly string $host,
        private readonly int $port,
        private readonly string $version,
        private readonly string $transport,
    ) {
        $this->bootedAt = microtime(true);
    }

    public function start(): void
    {
        $this->socket = new SocketServer("{$this->host}:{$this->port}", [], $this->loop);
        $this->http = new HttpServer($this->loop, fn (ServerRequestInterface $r): Response => $this->handle($r));
        $this->http->listen($this->socket);
    }

    public function stop(): void
    {
        $this->socket?->close();
        $this->socket = null;
        $this->http = null;
    }

    private function handle(ServerRequestInterface $request): Response
    {
        $isHealthRoute = $request->getMethod() === 'GET'
            && $request->getUri()->getPath() === '/health';

        if (! $isHealthRoute) {
            return new Response(
                404,
                ['Content-Type' => 'application/json'],
                (string) json_encode(['error' => 'not found']),
            );
        }

        $enabled = Tools::enabled();

        $payload = [
            'status' => $enabled ? 'ok' : 'disabled',
            'version' => $this->version,
            'transport' => $this->transport,
            'uptime_s' => (int) (microtime(true) - $this->bootedAt),
            'tool_count' => $enabled ? 3 : 0,
        ];

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload),
        );
    }
}
