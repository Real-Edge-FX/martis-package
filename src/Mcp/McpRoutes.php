<?php

declare(strict_types=1);

namespace Martis\Mcp;

use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Facades\Mcp;
use Martis\Mcp\Http\AuthenticateMcpToken;

/**
 * Registers the docs MCP HTTP route on the host app when
 * MARTIS_MCP_TRANSPORT=http. The route lives outside the `web` group (no
 * session, no CSRF), as laravel/mcp's own servers do; laravel/mcp also
 * answers GET and DELETE on the same URI with 405. A published config that
 * still has the removed daemon's keys disables the route (with a warning).
 */
final class McpRoutes
{
    public static function register(): void
    {
        if (McpConfig::transport() !== 'http') {
            return;
        }

        $legacy = McpConfig::legacyKeysInConfig();

        if ($legacy !== []) {
            // A stale upgrade leftover must not take the site down: skip the
            // route and say why, once, instead of throwing at boot.
            Log::warning(sprintf(
                'Martis did not register the docs MCP HTTP route: config/martis.php still has the pre-v2.5.0 `mcp` block (%s). Replace the block with the one in vendor/martis/martis/config/martis.php, or delete it. See "Upgrading to v2.5.0" in docs/upgrading.md.',
                implode(', ', array_map(static fn (string $key): string => 'martis.mcp.'.$key, $legacy)),
            ));

            return;
        }

        Mcp::web(McpConfig::path(), MartisDocsServer::class)
            ->middleware(AuthenticateMcpToken::class);
    }
}
