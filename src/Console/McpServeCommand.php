<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Martis\Mcp\DocLookup;
use Martis\Mcp\Tools;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * `martis:mcp-serve` — stdio MCP server that exposes the Martis
 * documentation as three tools (`martis_doc_list`, `martis_doc_read`,
 * `martis_doc_search`).
 *
 * Designed to be invoked by an MCP-aware coding agent through the
 * project's `.mcp.json` (or equivalent) entry written by
 * `martis:agents`. JSON-RPC traffic flows over stdin/stdout; logs go
 * to stderr.
 */
class McpServeCommand extends Command
{
    protected $signature = 'martis:mcp-serve';

    protected $description = 'Serve the Martis docs as an MCP server (stdio transport).';

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
                    $context === [] ? '' : json_encode($context),
                ));
            }
        };

        $tools = new Tools(DocLookup::package());

        $container = new BasicContainer;
        $container->set(LoggerInterface::class, $logger);
        $container->set(Tools::class, $tools);

        try {
            $server = Server::make()
                ->withServerInfo('Martis Docs', '1.0.0')
                ->withLogger($logger)
                ->withContainer($container)
                ->withTool([Tools::class, 'listDocs'], 'martis_doc_list')
                ->withTool([Tools::class, 'readDoc'], 'martis_doc_read')
                ->withTool([Tools::class, 'searchDocs'], 'martis_doc_search')
                ->build();

            $server->listen(new StdioServerTransport);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            fwrite(STDERR, '[martis:mcp-serve] critical: '.$e->getMessage()."\n");

            return self::FAILURE;
        }
    }
}
