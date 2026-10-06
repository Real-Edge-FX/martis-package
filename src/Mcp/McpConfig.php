<?php

declare(strict_types=1);

namespace Martis\Mcp;

use InvalidArgumentException;

/**
 * Reads the `martis.mcp.*` configuration of the docs MCP server. One
 * definition shared by the HTTP route registration and `martis:agents`.
 */
final class McpConfig
{
    /**
     * Variables of the ReactPHP daemon removed in v2.5.0. A host that still
     * sets one expects a behaviour that no longer exists, so
     * `martis:agents` refuses to run until they are removed.
     */
    public const REMOVED_VARIABLES = ['MARTIS_MCP_HOST', 'MARTIS_MCP_PORT', 'MARTIS_MCP_HEALTH_PORT'];

    /**
     * Keys of the removed daemon's `mcp` config block. A config/martis.php
     * published before v2.5.0 still carries them, and `mergeConfigFrom` does
     * not merge nested keys, so the stale block survives an upgrade.
     */
    public const LEGACY_KEYS = ['host', 'port', 'health_port'];

    /** `stdio` (the default) or `http`; anything else throws, naming the variable. */
    public static function transport(): string
    {
        $raw = config('martis.mcp.transport');
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return 'stdio';
        }

        $transport = is_string($raw) ? strtolower(trim($raw)) : null;

        if ($transport === null || ! in_array($transport, ['stdio', 'http'], true)) {
            throw new InvalidArgumentException(sprintf(
                'MARTIS_MCP_TRANSPORT must be "stdio" or "http", got "%s".',
                is_string($raw) ? $raw : get_debug_type($raw),
            ));
        }

        return $transport;
    }

    /**
     * The legacy keys present in `config('martis.mcp')`, in LEGACY_KEYS order.
     *
     * @return list<string>
     */
    public static function legacyKeysInConfig(): array
    {
        $block = config('martis.mcp');

        if (! is_array($block)) {
            return [];
        }

        return array_values(array_filter(
            self::LEGACY_KEYS,
            static fn (string $key): bool => array_key_exists($key, $block),
        ));
    }

    /** The HTTP route URI: `MARTIS_MCP_PATH`, else `/{martis.path}/mcp`. */
    public static function path(): string
    {
        $configured = config('martis.mcp.path');

        $path = is_string($configured) && trim($configured, ' /') !== ''
            ? trim($configured, ' /')
            : trim((string) config('martis.path', 'martis'), '/').'/mcp';

        return '/'.ltrim($path, '/');
    }

    /** The URL `martis:agents` writes for HTTP: `MARTIS_MCP_URL`, else `APP_URL` + path. */
    public static function url(): string
    {
        $configured = config('martis.mcp.url');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url', 'http://localhost'), '/').self::path();
    }

    /** The bearer token of the HTTP route, or null when none is set. */
    public static function token(): ?string
    {
        $token = config('martis.mcp.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * The removed variables that are still set, in REMOVED_VARIABLES order:
     * in the process environment, or on an uncommented line of `$envFile`.
     *
     * @return list<string>
     */
    public static function removedVariablesSet(?string $envFile = null): array
    {
        $contents = $envFile !== null && is_file($envFile) ? (string) file_get_contents($envFile) : '';

        return array_values(array_filter(self::REMOVED_VARIABLES, static function (string $name) use ($contents): bool {
            if (getenv($name) !== false || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
                return true;
            }

            return preg_match('/^\s*(?:export\s+)?'.$name.'\s*=/m', $contents) === 1;
        }));
    }
}
