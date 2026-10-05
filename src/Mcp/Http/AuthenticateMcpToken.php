<?php

declare(strict_types=1);

namespace Martis\Mcp\Http;

use Closure;
use Illuminate\Http\Request;
use Martis\Mcp\McpConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the docs MCP route. With MARTIS_MCP_HTTP_TOKEN set, a request
 * passes only with `Authorization: Bearer <token>`, compared in constant
 * time. Without a token the route serves only the local and testing
 * environments, so a deployed app never exposes it unauthenticated.
 * laravel/mcp adds the `WWW-Authenticate` challenge to the 401.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = McpConfig::token();

        if ($token === null) {
            if (app()->environment('local', 'testing')) {
                return $next($request);
            }

            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Set MARTIS_MCP_HTTP_TOKEN to serve the Martis MCP over HTTP outside the local environment.',
            ], 401);
        }

        // The scheme is case-insensitive (RFC 7235); the token is compared exactly.
        $header = (string) $request->headers->get('Authorization', '');

        if (preg_match('/^Bearer\s+(.+)\z/i', $header, $match) !== 1 || ! hash_equals($token, $match[1])) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
