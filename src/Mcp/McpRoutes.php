<?php

declare(strict_types=1);

namespace Martis\Mcp;

use Laravel\Mcp\Facades\Mcp;
use Martis\Mcp\Http\AuthenticateMcpToken;

/**
 * Registers the docs MCP HTTP route on the host app when
 * MARTIS_MCP_TRANSPORT=http. The route lives outside the `web` group (no
 * session, no CSRF), as laravel/mcp's own servers do; laravel/mcp also
 * answers GET and DELETE on the same URI with 405.
 */
final class McpRoutes
{
    public static function register(): void
    {
        if (McpConfig::transport() !== 'http') {
            return;
        }

        Mcp::web(McpConfig::path(), MartisDocsServer::class)
            ->middleware(AuthenticateMcpToken::class);
    }
}
