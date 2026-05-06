<?php

declare(strict_types=1);

use Martis\Mcp\DocLookup;
use Martis\Mcp\Tools;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/martis-tools-'.uniqid();
    mkdir($this->dir, 0755, true);
    file_put_contents($this->dir.'/gates.md', "# Gates\n\nSoft gates explained.\n");
    putenv('MARTIS_MCP_ENABLED');
});

afterEach(function () {
    if (isset($this->dir) && is_dir($this->dir)) {
        rmtree($this->dir);
    }
    putenv('MARTIS_MCP_ENABLED');
});

it('lists docs through listDocs when enabled', function () {
    $tools = new Tools(new DocLookup($this->dir));
    $result = $tools->listDocs();
    expect($result['enabled'])->toBeTrue()->and($result['pages'])->not->toBeEmpty();
});

it('reads a doc through readDoc', function () {
    $tools = new Tools(new DocLookup($this->dir));
    $result = $tools->readDoc('gates');
    expect($result['enabled'])->toBeTrue()
        ->and($result['slug'])->toBe('gates')
        ->and($result['content'])->toContain('Soft gates');
});

it('returns an error payload when the slug is missing', function () {
    $tools = new Tools(new DocLookup($this->dir));
    $result = $tools->readDoc('not-found');
    expect($result['enabled'])->toBeTrue()->and($result['error'])->toContain('not-found');
});

it('returns disabled notices when MARTIS_MCP_ENABLED=false', function () {
    putenv('MARTIS_MCP_ENABLED=false');
    $tools = new Tools(new DocLookup($this->dir));

    expect($tools->listDocs()['enabled'])->toBeFalse()
        ->and($tools->readDoc('gates')['enabled'])->toBeFalse()
        ->and($tools->searchDocs('soft')['enabled'])->toBeFalse();
});

it('treats undefined env as enabled (default true)', function () {
    expect(Tools::enabled())->toBeTrue();
});
