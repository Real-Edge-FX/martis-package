<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
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
        ->assertJsonPath('result.serverInfo.name', 'Martis Docs');

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

it('answers GET with 405', function () {
    // Outside the Martis prefix: under it, the SPA catch-all (GET /martis/{path})
    // is registered first and answers GET itself.
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
]);

it('adds the WWW-Authenticate challenge to a 401', function () {
    config()->set('martis.mcp.token', 's3cret-token');
    McpRoutes::register();

    expect(mcpPost('/martis/mcp', mcpInitialize())->headers->get('WWW-Authenticate'))->toContain('Bearer');
});

it('accepts the exact bearer token', function () {
    config()->set('martis.mcp.token', 's3cret-token');
    McpRoutes::register();

    mcpPost('/martis/mcp', mcpInitialize(), ['Authorization' => 'Bearer s3cret-token'])->assertOk();
});

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

    // The SPA catch-all (GET only) matches the URI, hence 405 and not 404.
    $this->postJson('/martis/mcp', mcpInitialize())->assertStatus(405);
});
