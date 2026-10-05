<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Martis\Mcp\MartisDocsServer;
use Martis\Mcp\McpRoutes;

beforeEach(function () {
    config()->set('martis.mcp.transport', 'http');
    config()->set('martis.mcp.path', null);
    config()->set('martis.mcp.token', null);
    config()->set('martis.path', 'martis');
});

function mcpPost(string $uri, array $payload, array $headers = []): TestResponse
{
    return test()->postJson($uri, $payload, array_merge([
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2025-06-18',
    ], $headers));
}

function mcpInitialize(): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-06-18',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
    ]];
}

it('does not register the route under the stdio transport', function () {
    config()->set('martis.mcp.transport', 'stdio');
    McpRoutes::register();

    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->all();

    expect($uris)->not->toContain('martis/mcp');
});

it('serves the handshake and the three tools at the default path', function () {
    McpRoutes::register();

    mcpPost('/martis/mcp', mcpInitialize())
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Martis Docs')
        ->assertJsonPath('result.serverInfo.version', MartisDocsServer::packageVersion());

    $names = collect(mcpPost('/martis/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
        ->assertOk()
        ->json('result.tools'))->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['martis_doc_list', 'martis_doc_read', 'martis_doc_search']);
});

it('calls a tool over HTTP', function () {
    McpRoutes::register();

    $text = mcpPost('/martis/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
        'name' => 'martis_doc_read', 'arguments' => ['slug' => 'gates'],
    ]])->assertOk()->json('result.content.0.text');

    expect(json_decode((string) $text, true))->toMatchArray(['enabled' => true, 'slug' => 'gates']);
});

it('serves a custom MARTIS_MCP_PATH and follows MARTIS_PATH by default', function () {
    config()->set('martis.mcp.path', '/agents/docs');
    McpRoutes::register();
    mcpPost('/agents/docs', mcpInitialize())->assertOk();

    config()->set('martis.mcp.path', null);
    config()->set('martis.path', 'admin');
    McpRoutes::register();
    mcpPost('/admin/mcp', mcpInitialize())->assertOk();
});

it('answers GET and DELETE with 405 at the default path, ahead of the SPA catch-all', function () {
    // Booted with the http transport: the route must be registered before the
    // Martis routes, whose GET /{martis.path}/{path} catch-all would otherwise
    // answer a Streamable HTTP client's GET with the SPA (a login redirect).
    putenv('MARTIS_MCP_TRANSPORT=http');

    try {
        $this->refreshApplication();

        $this->get('/martis/mcp')->assertStatus(405);
        $this->delete('/martis/mcp')->assertStatus(405);
    } finally {
        putenv('MARTIS_MCP_TRANSPORT');
        $this->refreshApplication();
    }
});

it('answers GET with 405 at a custom path outside the Martis prefix', function () {
    config()->set('martis.mcp.path', '/agents/docs');
    McpRoutes::register();

    $this->get('/agents/docs')->assertStatus(405);
});

it('requires the exact bearer token when one is set', function (?string $header) {
    config()->set('martis.mcp.token', 's3cret-token');
    McpRoutes::register();

    mcpPost('/martis/mcp', mcpInitialize(), $header === null ? [] : ['Authorization' => $header])
        ->assertStatus(401)
        ->assertExactJson(['error' => 'unauthorized']);
})->with([
    'missing' => [null],
    'wrong' => ['Bearer nope'],
    'extra character' => ['Bearer s3cret-tokenX'],
    'truncated' => ['Bearer s3cret-toke'],
    'trailing NUL' => ["Bearer s3cret-token\0"],
    'no scheme' => ['s3cret-token'],
    'other scheme' => ['Basic s3cret-token'],
    'scheme only' => ['Bearer '],
]);

it('adds the WWW-Authenticate challenge to a 401', function () {
    config()->set('martis.mcp.token', 's3cret-token');
    McpRoutes::register();

    expect(mcpPost('/martis/mcp', mcpInitialize())->headers->get('WWW-Authenticate'))->toContain('Bearer');
});

it('accepts the exact bearer token under any case of the scheme (RFC 7235)', function (string $header) {
    config()->set('martis.mcp.token', 's3cret-token');
    McpRoutes::register();

    mcpPost('/martis/mcp', mcpInitialize(), ['Authorization' => $header])->assertOk();
})->with([
    'Bearer s3cret-token',
    'bearer s3cret-token',
    'BEARER s3cret-token',
]);

it('serves without a token in the local and testing environments', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);
    McpRoutes::register();

    mcpPost('/martis/mcp', mcpInitialize())->assertOk();
})->with(['local', 'testing']);

it('refuses to serve without a token outside the local environment', function () {
    app()->detectEnvironment(fn () => 'production');
    McpRoutes::register();

    mcpPost('/martis/mcp', mcpInitialize())
        ->assertStatus(401)
        ->assertExactJson([
            'error' => 'unauthorized',
            'message' => 'Set MARTIS_MCP_HTTP_TOKEN to serve the Martis MCP over HTTP outside the local environment.',
        ]);
});

it('refuses an unknown transport, naming the variable', function () {
    config()->set('martis.mcp.transport', 'websocket');

    McpRoutes::register();
})->throws(InvalidArgumentException::class, 'MARTIS_MCP_TRANSPORT');

it('skips the route and logs a warning when the published config still has the legacy mcp keys', function () {
    config()->set('martis.mcp.port', 8091);
    config()->set('martis.mcp.health_port', 8092);
    Log::spy();

    McpRoutes::register();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'config/martis.php')
            && str_contains($message, 'martis.mcp.port')
            && str_contains($message, 'martis.mcp.health_port')
            && ! str_contains($message, 'martis.mcp.host')
            && str_contains($message, 'Upgrading to v2.5.0'));

    $postUris = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('POST', $route->methods(), true))
        ->map(fn ($route) => $route->uri())
        ->all();

    expect($postUris)->not->toContain('martis/mcp');
    $response = $this->postJson('/martis/mcp', mcpInitialize());
    expect($response->getStatusCode())->not->toBe(200);
    $response->assertJsonMissingPath('result');
});

it('still validates the transport before looking at the legacy keys', function () {
    config()->set('martis.mcp.transport', 'websocket');
    config()->set('martis.mcp.port', 8091);

    McpRoutes::register();
})->throws(InvalidArgumentException::class, 'MARTIS_MCP_TRANSPORT');

it('registers the route at boot when MARTIS_MCP_TRANSPORT=http', function () {
    putenv('MARTIS_MCP_TRANSPORT=http');

    try {
        $this->refreshApplication();
        mcpPost('/martis/mcp', mcpInitialize())->assertOk();
    } finally {
        putenv('MARTIS_MCP_TRANSPORT');
        $this->refreshApplication();
    }
});

it('does not register the route at boot by default', function () {
    $postUris = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('POST', $route->methods(), true))
        ->map(fn ($route) => $route->uri())
        ->all();

    expect($postUris)->not->toContain('martis/mcp');

    // Not handled by the MCP server: no JSON-RPC result. The status is not pinned
    // (the SPA catch-all, GET only, makes it 405 today, which is not MCP behaviour).
    $response = $this->postJson('/martis/mcp', mcpInitialize());

    expect($response->getStatusCode())->not->toBe(200);
    $response->assertJsonMissingPath('result');
});
