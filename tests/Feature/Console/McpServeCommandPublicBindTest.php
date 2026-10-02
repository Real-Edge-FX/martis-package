<?php

declare(strict_types=1);

use Martis\Console\McpServeCommand;

/**
 * Which bind hosts of `martis:mcp-serve` count as public (F118).
 *
 * The warning about an unauthenticated bind used to compare the host with the
 * single string '0.0.0.0', so '::', '[::]', a LAN or a public address started
 * silently. It now fires for every host that is not a loopback address
 * (127.0.0.0/8, ::1, localhost). These tests pin the classification and the
 * warnings it drives; the subprocess runs live in McpServeCommandTransportTest.
 */
it('treats only loopback hosts as not public', function (string $host) {
    expect(McpServeCommand::isLoopbackHost($host))->toBeTrue("{$host} is a loopback host");
})->with([
    'the default' => ['127.0.0.1'],
    'another 127.0.0.0/8 address' => ['127.0.0.2'],
    'the top of 127.0.0.0/8' => ['127.255.255.254'],
    'localhost' => ['localhost'],
    'localhost, upper case' => ['LOCALHOST'],
    'localhost, fully qualified' => ['localhost.'],
    'IPv6 loopback' => ['::1'],
    'IPv6 loopback in brackets' => ['[::1]'],
    'IPv6 loopback, written out' => ['0:0:0:0:0:0:0:1'],
    'IPv4-mapped IPv6 loopback' => ['::ffff:127.0.0.1'],
    'with surrounding whitespace' => [' 127.0.0.1 '],
]);

it('treats every other host as public', function (string $host) {
    expect(McpServeCommand::isLoopbackHost($host))->toBeFalse("{$host} is not a loopback host");
})->with([
    'every IPv4 interface' => ['0.0.0.0'],
    'every IPv6 interface' => ['::'],
    'every IPv6 interface in brackets' => ['[::]'],
    'every IPv6 interface, written out' => ['0:0:0:0:0:0:0:0'],
    'a LAN address' => ['192.168.1.20'],
    'a private 10/8 address' => ['10.0.0.5'],
    'a public address' => ['203.0.113.7'],
    'a public IPv6 address' => ['2001:db8::1'],
    'a link-local IPv6 address' => ['fe80::1'],
    'IPv4-mapped IPv6 of a LAN address' => ['::ffff:192.168.1.20'],
    'a host name' => ['mcp.example.com'],
    'a name that only starts with localhost' => ['localhost.example.com'],
    'a name that only ends with 127.0.0.1' => ['not-127.0.0.1'],
    'the first address outside 127.0.0.0/8' => ['128.0.0.1'],
    'the last address before 127.0.0.0/8' => ['126.255.255.255'],
    'a short form no parser should be trusted with' => ['127.1'],
    'a leading zero' => ['0127.0.0.1'],
    'an address with a port' => ['127.0.0.1:8091'],
    'an empty host' => [''],
]);

it('warns about a public bind without a token, whatever the host', function (string $host) {
    $warnings = McpServeCommand::publicBindWarnings($host, hasToken: false, healthPort: 0);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain("[martis:mcp-serve] WARNING: bound to {$host} without MARTIS_MCP_HTTP_TOKEN. Anyone reaching this port can call the docs API.");
})->with(['0.0.0.0', '::', '[::]', '192.168.1.20', '203.0.113.7', 'mcp.example.com']);

it('says nothing about a loopback bind', function (string $host) {
    expect(McpServeCommand::publicBindWarnings($host, hasToken: false, healthPort: 0))->toBe([])
        // Not even for the health port: it is as loopback as the MCP port.
        ->and(McpServeCommand::publicBindWarnings($host, hasToken: false, healthPort: 8092))->toBe([]);
})->with(['127.0.0.1', 'localhost', '::1', '[::1]']);

it('stays quiet about the token on a public bind when one is set, and keeps warning about /health', function () {
    // The token guards the MCP endpoint only: /health has no authentication.
    expect(McpServeCommand::publicBindWarnings('192.168.1.20', hasToken: true, healthPort: 0))->toBe([]);

    $warnings = McpServeCommand::publicBindWarnings('192.168.1.20', hasToken: true, healthPort: 8092);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('[martis:mcp-serve] WARNING: /health endpoint is bound to 192.168.1.20 without authentication.');
});

it('warns about both the endpoint and /health on a public bind with no token and a health port', function () {
    $warnings = McpServeCommand::publicBindWarnings('::', hasToken: false, healthPort: 8092);

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('bound to :: without MARTIS_MCP_HTTP_TOKEN')
        ->and($warnings[1])->toContain('/health endpoint is bound to :: without authentication');
});
