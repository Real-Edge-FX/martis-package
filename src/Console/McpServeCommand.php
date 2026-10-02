<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Martis\Mcp\DocLookup;
use Martis\Mcp\Tools;
use Martis\Mcp\Transport\AuthenticatedStreamableHttpTransport;
use Martis\Mcp\Transport\FlushingStdioServerTransport;
use Martis\Mcp\Transport\HealthServer;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
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
        {--no-warn-on-public : Skip the "exposed without token" warnings when the host is not a loopback address}';

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
                // The stdio transport closes itself on SIGTERM / SIGINT, but
                // a signal handled before the loop runs only stops a loop
                // that `run()` then restarts, kept alive by the session
                // timer: these handlers stop it again on its first tick.
                $this->registerSignalHandlers(null);
                $server->listen(new FlushingStdioServerTransport);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // ReactPHP runs a loop that never ran when PHP shuts down, and the
            // signal listeners registered above would keep it waiting
            // forever (a port already in use left the process hanging).
            Loop::stop();
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

        // The server-side fallback is intentionally 'stdio' (not the
        // scaffolding default 'http') so existing consumers whose
        // .mcp.json still carries a stdio spawn entry — written by an
        // older martis:agents — keep working after upgrade. The
        // package config keeps `mcp.transport` null when no env was
        // set; the `?:` here is what turns that into stdio for the
        // server while AgentsCommand turns the same null into http for
        // its scaffolding. See config/martis.php for the rationale.
        return strtolower((string) (config('martis.mcp.transport') ?: 'stdio'));
    }

    private function buildHttpTransport(): AuthenticatedStreamableHttpTransport
    {
        $host = $this->bindHost();
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

        $host = $this->bindHost();
        $server = new HealthServer(Loop::get(), $host, $port, $this->packageVersion(), 'http');
        $server->start();

        return $server;
    }

    private function maybeWarnOnPublic(): void
    {
        if ((bool) $this->option('no-warn-on-public')) {
            return;
        }

        $healthPort = (int) ($this->option('health-port') ?: config('martis.mcp.health_port', 0));

        foreach (self::publicBindWarnings($this->bindHost(), (string) config('martis.mcp.token', '') !== '', $healthPort) as $warning) {
            fwrite(STDERR, $warning."\n");
        }
    }

    /**
     * The warnings a bind to `$host` earns: none on a loopback address, and
     * on any other (0.0.0.0, '::', '[::]', a LAN or a public address, a host
     * name) one about the MCP endpoint when no token guards it and one about
     * /health, which never has authentication, when it is enabled.
     *
     * @return list<string>
     */
    public static function publicBindWarnings(string $host, bool $hasToken, int $healthPort): array
    {
        if (self::isLoopbackHost($host)) {
            return [];
        }

        $warnings = [];

        if (! $hasToken) {
            $warnings[] = "[martis:mcp-serve] WARNING: bound to {$host} without MARTIS_MCP_HTTP_TOKEN. "
                .'Anyone reaching this port can call the docs API. Set the token or front '
                .'the server with an authenticated reverse proxy.';
        }

        if ($healthPort > 0) {
            $warnings[] = "[martis:mcp-serve] WARNING: /health endpoint is bound to {$host} without authentication. "
                .'Operational metadata (version, uptime, tool count) is visible to any network peer. '
                .'Front this port with an authenticated reverse proxy or restrict access with a firewall rule.';
        }

        return $warnings;
    }

    /**
     * Whether a bind host is a loopback address: 127.0.0.0/8, ::1 (also
     * written in brackets or in full, or IPv4-mapped) and `localhost`. It is
     * the one definition of "not public", so every other host counts as
     * public, one this check cannot read (an empty host, a short form such as
     * `127.1`, an address with a port) included: a warning that is not needed
     * costs a line on stderr, a loopback verdict that is wrong hides a
     * network bind.
     */
    public static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === 'localhost' || $host === 'localhost.') {
            return true;
        }

        $address = filter_var($host, FILTER_VALIDATE_IP) === false ? false : inet_pton($host);

        if ($address === false) {
            return false;
        }

        if (strlen($address) === 4) {
            return ord($address[0]) === 127;
        }

        // ::1, and an IPv4-mapped address (::ffff:a.b.c.d) of 127.0.0.0/8.
        if ($address === str_repeat("\0", 15)."\1") {
            return true;
        }

        return str_starts_with($address, str_repeat("\0", 10)."\xff\xff") && ord($address[12]) === 127;
    }

    /** The HTTP bind host: `--host`, else `martis.mcp.host`, else 127.0.0.1. */
    private function bindHost(): string
    {
        return (string) ($this->option('host') ?: config('martis.mcp.host', '127.0.0.1'));
    }

    private function registerSignalHandlers(?HealthServer $health): void
    {
        $shutdown = function () use ($health): void {
            $health?->stop();
            Loop::get()->stop();
            // A signal handled before `run()` starts (both transports log "up
            // and listening" before the loop runs) is undone by `run()`,
            // which resets the stop: stop again on the loop's first tick.
            Loop::get()->futureTick(static fn () => Loop::get()->stop());
        };

        $loop = Loop::get();
        if (defined('SIGTERM')) {
            $loop->addSignal(SIGTERM, $shutdown);
        }
        if (defined('SIGINT')) {
            $loop->addSignal(SIGINT, $shutdown);
        }

        // PHP runs a signal's handler between two opcodes, and the loop
        // sleeps in stream_select() for a timeout it worked out before: a
        // signal that lands in between waits for the next event, which
        // after a request is the session timer, five minutes away. Wake the
        // loop every second to run what is pending and see the stop.
        if (function_exists('pcntl_signal_dispatch')) {
            $loop->addPeriodicTimer(1.0, static function (): void {
                pcntl_signal_dispatch();
            });
        }
    }

    private function packageVersion(): string
    {
        // src/Console -> src -> martis/martis -> martis -> vendor -> composer/installed.json
        $installed = __DIR__.'/../../../../composer/installed.json';
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
