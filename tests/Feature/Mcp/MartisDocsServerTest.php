<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Testing\TestResponse;
use Martis\Mcp\MartisDocsServer;
use Martis\Mcp\Tools\DocListTool;
use Martis\Mcp\Tools\DocReadTool;
use Martis\Mcp\Tools\DocSearchTool;

afterEach(function () {
    config()->set('martis.mcp.enabled', true);
});

it('registers the three docs tools under their public names', function () {
    expect((new DocListTool)->name())->toBe('martis_doc_list')
        ->and((new DocReadTool)->name())->toBe('martis_doc_read')
        ->and((new DocSearchTool)->name())->toBe('martis_doc_search');

    MartisDocsServer::tools()->assertRegistered([DocListTool::class, DocReadTool::class, DocSearchTool::class]);
});

it('marks every tool read-only', function (string $tool) {
    expect((new $tool)->toArray()['annotations'])->toMatchArray(['readOnlyHint' => true]);
})->with([DocListTool::class, DocReadTool::class, DocSearchTool::class]);

it('lists the docs pages', function () {
    MartisDocsServer::tool(DocListTool::class)
        ->assertOk()
        ->assertSee(['"enabled":true', '"slug":"fields"']);
});

it('reads one page in full', function () {
    $response = MartisDocsServer::tool(DocReadTool::class, ['slug' => 'gates'])->assertOk();

    $response->assertSee('"slug":"gates"');
    $payload = json_decode(martisToolText($response), true);
    expect($payload['content'])->toBe(file_get_contents(__DIR__.'/../../../docs/gates.md'));
});

it('answers an unknown slug with the error payload, not a tool error', function () {
    MartisDocsServer::tool(DocReadTool::class, ['slug' => 'no-such-page'])
        ->assertOk()
        ->assertSee('No documentation page found for slug `no-such-page`');
});

it('searches the docs with the default and an explicit limit', function () {
    $default = json_decode(martisToolText(MartisDocsServer::tool(DocSearchTool::class, ['query' => 'gate'])->assertOk()), true);
    $one = json_decode(martisToolText(MartisDocsServer::tool(DocSearchTool::class, ['query' => 'gate', 'limit' => 1])->assertOk()), true);

    expect($default['enabled'])->toBeTrue()
        ->and($default['matches'])->toHaveCount(5)
        ->and($one['matches'])->toHaveCount(1);
});

it('rejects a call without its required argument', function () {
    MartisDocsServer::tool(DocReadTool::class, [])->assertHasErrors(['The slug field is required.']);
    MartisDocsServer::tool(DocSearchTool::class, [])->assertHasErrors(['The query field is required.']);
});

it('returns the disabled payload from every tool when MARTIS_MCP_ENABLED is false', function (string $tool, array $args) {
    config()->set('martis.mcp.enabled', false);

    MartisDocsServer::tool($tool, $args)->assertOk()->assertSee('"enabled":false');
})->with([
    [DocListTool::class, []],
    [DocReadTool::class, ['slug' => 'gates']],
    [DocSearchTool::class, ['query' => 'gate']],
]);

it('exposes the package version and the stdio handle', function () {
    expect(MartisDocsServer::packageVersion())->not->toBe('')
        ->and(MartisDocsServer::HANDLE)->toBe('martis-docs');
});

/**
 * The JSON text the tool returned (first text content item).
 */
function martisToolText(TestResponse $response): string
{
    $result = (fn () => $this->response->toArray())->call($response);

    return (string) ($result['result']['content'][0]['text'] ?? '');
}
