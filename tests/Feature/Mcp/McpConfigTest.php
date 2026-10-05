<?php

declare(strict_types=1);

use Martis\Mcp\McpConfig;

beforeEach(function () {
    config()->set('martis.path', 'martis');
    config()->set('martis.mcp.transport', 'stdio');
    config()->set('martis.mcp.path', null);
    config()->set('martis.mcp.url', null);
    config()->set('martis.mcp.token', null);
    config()->set('app.url', 'http://localhost');
});

afterEach(function () {
    foreach (McpConfig::REMOVED_VARIABLES as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
});

it('defaults the transport to stdio and accepts http in any case', function () {
    config()->set('martis.mcp.transport', null);
    expect(McpConfig::transport())->toBe('stdio');

    config()->set('martis.mcp.transport', '');
    expect(McpConfig::transport())->toBe('stdio');

    config()->set('martis.mcp.transport', 'HTTP');
    expect(McpConfig::transport())->toBe('http');
});

it('refuses an unknown transport, naming the variable', function () {
    config()->set('martis.mcp.transport', 'sse');

    McpConfig::transport();
})->throws(InvalidArgumentException::class, 'MARTIS_MCP_TRANSPORT must be "stdio" or "http", got "sse".');

it('refuses a transport that is not a string', function (mixed $value, string $type) {
    config()->set('martis.mcp.transport', $value);

    expect(fn () => McpConfig::transport())
        ->toThrow(InvalidArgumentException::class, "MARTIS_MCP_TRANSPORT must be \"stdio\" or \"http\", got \"{$type}\".");
})->with([
    [true, 'bool'],
    [1, 'int'],
]);

it('derives the default path from the Martis path', function () {
    expect(McpConfig::path())->toBe('/martis/mcp');

    config()->set('martis.path', '/admin/');
    expect(McpConfig::path())->toBe('/admin/mcp');
});

it('normalises a configured path', function () {
    config()->set('martis.mcp.path', 'tools/mcp/');
    expect(McpConfig::path())->toBe('/tools/mcp');
});

it('builds the client url from APP_URL and the path, unless MARTIS_MCP_URL is set', function () {
    config()->set('app.url', 'https://app.test/');
    expect(McpConfig::url())->toBe('https://app.test/martis/mcp');

    config()->set('martis.mcp.url', 'http://localhost:8000/martis/mcp');
    expect(McpConfig::url())->toBe('http://localhost:8000/martis/mcp');
});

it('treats an empty token as no token', function () {
    expect(McpConfig::token())->toBeNull();

    config()->set('martis.mcp.token', '');
    expect(McpConfig::token())->toBeNull();

    config()->set('martis.mcp.token', 's3cret');
    expect(McpConfig::token())->toBe('s3cret');
});

it('finds removed variables in the environment and in an uncommented .env line', function () {
    $envFile = sys_get_temp_dir().'/martis-mcp-env-'.uniqid();
    file_put_contents($envFile, "APP_NAME=Demo\n# MARTIS_MCP_HOST=127.0.0.1\nMARTIS_MCP_HEALTH_PORT=8092\n");
    putenv('MARTIS_MCP_PORT=8091');

    try {
        expect(McpConfig::removedVariablesSet($envFile))->toBe(['MARTIS_MCP_PORT', 'MARTIS_MCP_HEALTH_PORT']);
    } finally {
        unlink($envFile);
    }
});

it('finds no removed variable on a clean environment or a missing .env', function () {
    expect(McpConfig::removedVariablesSet(sys_get_temp_dir().'/no-such-env-'.uniqid()))->toBe([])
        ->and(McpConfig::removedVariablesSet())->toBe([]);
});

it('lists the legacy mcp config keys present, even when null', function () {
    config()->set('martis.mcp', ['transport' => 'stdio', 'port' => null, 'host' => '127.0.0.1']);

    expect(McpConfig::legacyKeysInConfig())->toBe(['host', 'port']);
});

it('finds no legacy mcp key in a current or non-array block', function (mixed $block) {
    config()->set('martis.mcp', $block);

    expect(McpConfig::legacyKeysInConfig())->toBe([]);
})->with([
    'current block' => [['transport' => 'stdio', 'path' => null, 'token' => null]],
    'null' => [null],
    'string' => ['stdio'],
]);
