<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Martis\Mcp\DocLookup;
use Martis\Mcp\Tools;
use Martis\Mcp\Transport\AuthenticatedStreamableHttpTransport;
use Martis\Mcp\Transport\HealthServer;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;

/**
 * `martis:mcp-serve` — MCP server that exposes the Martis
 * documentation as three tools (`martis_doc_list`, `martis_doc_read`,
 * `martis_doc_search`).
 *
 * Supports two transports:
 *   - stdio (default) — JSON-RPC over stdin/stdout; spawned per
 *     client session via `.mcp.json` `command/args/cwd`. Logs go to
 *     stderr.
 *   - http  — Streamable HTTP over `host:port/path`; long-lived
 *     process. Optional bearer token via `MARTIS_MCP_HTTP_TOKEN`.
 *     Optional `/health` endpoint on a dedicated port.
 */
class McpServeCommand extends Command
{
    protected $signature = 'martis:mcp-serve
        {--transport= : stdio (default) or http. Overrides MARTIS_MCP_TRANSPORT}
        {--host= : HTTP bind host. Overrides MARTIS_MCP_HOST (default 127.0.0.1)}
        {--port= : HTTP port. Overrides MARTIS_MCP_PORT (default 8091)}
        {--path= : HTTP MCP endpoint path. Overrides MARTIS_MCP_PATH (default /mcp)}
        {--health-port= : Enable /health on this port. Overrides MARTIS_MCP_HEALTH_PORT (default 0 = off)}
        {--no-warn-on-public : Skip the "exposed without token" warning when host=0.0.0.0}';

    protected $description = 'Serve the Martis docs as an MCP server (stdio or HTTP transport).';

    public function handle(): int
    {
        $logger = new class extends AbstractLogger
        {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                fwrite(STDERR, sprintf(
                    "[%s][%s] %s %s\n",
                    date('Y-m-d H:i:s'),
                    strtoupper((string) $level),
                    (string) $message,
                    $context === [] ? '' : (string) json_encode($context),
                ));
            }
        };

        $tools = new Tools(DocLookup::package());

        $container = new BasicContainer;
        $container->set(LoggerInterface::class, $logger);
        $container->set(Tools::class, $tools);

        try {
            $server = Server::make()
                ->withServerInfo('Martis Docs', $this->packageVersion())
                ->withLogger($logger)
                ->withContainer($container)
                ->withTool([Tools::class, 'listDocs'], 'martis_doc_list')
                ->withTool([Tools::class, 'readDoc'], 'martis_doc_read')
                ->withTool([Tools::class, 'searchDocs'], 'martis_doc_search')
                ->build();

            $transport = $this->resolveTransport();

            if ($transport === 'http') {
                $health = $this->maybeStartHealthServer();
                $this->maybeWarnOnPublic();
                $this->registerSignalHandlers($health);
                $server->listen($this->buildHttpTransport());
            } else {
                $server->listen(new StdioServerTransport);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            fwrite(STDERR, '[martis:mcp-serve] critical: '.$e->getMessage()."\n");

            return self::FAILURE;
        }
    }

    private function resolveTransport(): string
    {
        $cli = $this->option('transport');
        if (is_string($cli) && $cli !== '') {
            return strtolower($cli);
        }

        return strtolower((string) config('martis.mcp.transport', 'stdio'));
    }

    private function buildHttpTransport(): AuthenticatedStreamableHttpTransport
    {
        $host = (string) ($this->option('host') ?: config('martis.mcp.host', '127.0.0.1'));
        $port = (int) ($this->option('port') ?: config('martis.mcp.port', 8091));
        $path = (string) ($this->option('path') ?: config('martis.mcp.path', '/mcp'));
        $token = (string) config('martis.mcp.token', '');

        return new AuthenticatedStreamableHttpTransport(
            host: $host,
            port: $port,
            mcpPath: $path,
            stateless: true,
            token: $token === '' ? null : $token,
        );
    }

    private function maybeStartHealthServer(): ?HealthServer
    {
        $port = (int) ($this->option('health-port') ?: config('martis.mcp.health_port', 0));
        if ($port <= 0) {
            return null;
        }

        $host = (string) ($this->option('host') ?: config('martis.mcp.host', '127.0.0.1'));
        $server = new HealthServer(Loop::get(), $host, $port, $this->packageVersion(), 'http');
        $server->start();

        return $server;
    }

    private function maybeWarnOnPublic(): void
    {
        if ((bool) $this->option('no-warn-on-public')) {
            return;
        }
        $host = (string) ($this->option('host') ?: config('martis.mcp.host', '127.0.0.1'));
        $token = (string) config('martis.mcp.token', '');
        if ($host === '0.0.0.0' && $token === '') {
            fwrite(
                STDERR,
                '[martis:mcp-serve] WARNING: bound to 0.0.0.0 without MARTIS_MCP_HTTP_TOKEN. '
                .'Anyone reaching this port can call the docs API. Set the token or front '
                ."the server with an authenticated reverse proxy.\n"
            );
        }
    }

    private function registerSignalHandlers(?HealthServer $health): void
    {
        $shutdown = function () use ($health): void {
            $health?->stop();
            Loop::get()->stop();
        };

        $loop = Loop::get();
        if (defined('SIGTERM')) {
            $loop->addSignal(SIGTERM, $shutdown);
        }
        if (defined('SIGINT')) {
            $loop->addSignal(SIGINT, $shutdown);
        }
    }

    private function packageVersion(): string
    {
        // src/Console -> src -> package-root -> vendor/composer/installed.json
        $installed = __DIR__.'/../../vendor/composer/installed.json';
        if (! file_exists($installed)) {
            return '1.13.0';
        }
        $data = json_decode((string) file_get_contents($installed), true);
        $packages = $data['packages'] ?? $data ?? [];
        foreach ($packages as $package) {
            if (($package['name'] ?? null) === 'martis/martis') {
                return ltrim((string) ($package['version'] ?? '1.13.0'), 'v');
            }
        }

        return '1.13.0';
    }
}
