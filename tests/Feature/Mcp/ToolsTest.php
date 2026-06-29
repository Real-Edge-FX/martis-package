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
    config()->set('martis.mcp.enabled', null);
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

it('strips non-alphanumeric characters from the slug reflected in the error message', function () {
    $tools = new Tools(new DocLookup($this->dir));

    // Backticks, null bytes, and terminal escape sequences must not appear in the error.
    $result = $tools->readDoc('bad`slug\x00\x1b[31m');
    expect($result)->toHaveKey('error');
    expect($result['error'])->not->toContain('`bad`');
    expect($result['error'])->not->toContain("\x00");
    expect($result['error'])->not->toContain("\x1b");
    // Only the safe subset survives: letters, digits, dots, underscores, hyphens.
    expect($result['error'])->toContain('badslug');
});

it('error message is empty-slug-safe when every character is stripped', function () {
    $tools = new Tools(new DocLookup($this->dir));

    // A slug of only disallowed characters collapses to an empty string.
    $result = $tools->readDoc('`\x00\x1b');
    expect($result)->toHaveKey('error');
    // The error string must still be a valid non-null string.
    expect($result['error'])->toBeString();
});

it('returns disabled notices when MARTIS_MCP_ENABLED=false', function () {
    config()->set('martis.mcp.enabled', false);
    putenv('MARTIS_MCP_ENABLED=false');
    $tools = new Tools(new DocLookup($this->dir));

    expect($tools->listDocs()['enabled'])->toBeFalse()
        ->and($tools->readDoc('gates')['enabled'])->toBeFalse()
        ->and($tools->searchDocs('soft')['enabled'])->toBeFalse();
});

it('treats undefined env as enabled (default true)', function () {
    expect(Tools::enabled())->toBeTrue();
});

it('Tools::enabled() returns the config(\'martis.mcp.enabled\') value when set', function () {
    config()->set('martis.mcp.enabled', false);
    putenv('MARTIS_MCP_ENABLED'); // unset
    expect(Tools::enabled())->toBeFalse();

    config()->set('martis.mcp.enabled', true);
    expect(Tools::enabled())->toBeTrue();
});

it('Tools::enabled() falls back to getenv when config is missing (cold bootstrap)', function () {
    config()->offsetUnset('martis.mcp.enabled');
    putenv('MARTIS_MCP_ENABLED=false');
    expect(Tools::enabled())->toBeFalse();

    putenv('MARTIS_MCP_ENABLED=true');
    expect(Tools::enabled())->toBeTrue();

    putenv('MARTIS_MCP_ENABLED'); // unset → default true
    expect(Tools::enabled())->toBeTrue();
});
