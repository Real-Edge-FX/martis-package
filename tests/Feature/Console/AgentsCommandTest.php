<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/martis-agents-cmd-'.uniqid();
    mkdir($this->base, 0755, true);
    file_put_contents($this->base.'/composer.json', json_encode([
        'name' => 'acme/demo',
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));
    file_put_contents($this->base.'/.env', "APP_NAME=Demo\n");
    file_put_contents($this->base.'/.env.example', "APP_NAME=Demo\n");
    $this->originalBase = base_path();
    app()->setBasePath($this->base);
});

afterEach(function () {
    if (isset($this->originalBase)) {
        app()->setBasePath($this->originalBase);
    }
    if (isset($this->base) && is_dir($this->base)) {
        rmtree($this->base);
    }
});

it('writes AGENTS.md and the per-agent guideline file when --agent is set', function () {
    $exit = Artisan::call('martis:agents', [
        '--agent' => ['claude'],
        '--without-mcp' => true,
        '--force' => true,
        '--no-interaction' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(file_exists($this->base.'/AGENTS.md'))->toBeTrue()
        ->and(file_exists($this->base.'/CLAUDE.md'))->toBeTrue();

    $body = (string) file_get_contents($this->base.'/AGENTS.md');
    expect($body)->toContain('Working with Martis')
        ->and($body)->not->toContain('{{project_name}}')
        ->and($body)->not->toContain('{{MCP_SECTION}}')
        ->and($body)->not->toContain('martis_doc_search');
});

it('includes the MCP section when --with-mcp is set', function () {
    Artisan::call('martis:agents', [
        '--agent' => ['claude'],
        '--with-mcp' => true,
        '--force' => true,
        '--no-interaction' => true,
    ]);

    $body = (string) file_get_contents($this->base.'/AGENTS.md');
    expect($body)->toContain('martis_doc_search')
        ->and($body)->toContain('MARTIS_MCP_ENABLED');

    // Since v1.15.0 the scaffold default is HTTP, so the .mcp.json
    // entry is the URL connection ({"type":"http","url":"…/mcp"})
    // rather than the legacy stdio spawn entry. Transport-specific
    // assertions live in AgentsCommandHttpTransportTest; this test
    // just confirms the MCP block was wired at all.
    $mcp = (string) file_get_contents($this->base.'/.mcp.json');
    expect($mcp)->toContain('"martis"');

    expect((string) file_get_contents($this->base.'/.env'))->toContain('MARTIS_MCP_ENABLED=true');
});

it('runs --mcp-only without touching guideline files', function () {
    Artisan::call('martis:agents', [
        '--agent' => ['claude'],
        '--mcp-only' => true,
        '--force' => true,
        '--no-interaction' => true,
    ]);

    expect(file_exists($this->base.'/AGENTS.md'))->toBeFalse()
        ->and(file_exists($this->base.'/CLAUDE.md'))->toBeFalse()
        ->and(file_exists($this->base.'/.mcp.json'))->toBeTrue();
});

it('removes the entry on --mcp-unwire', function () {
    Artisan::call('martis:agents', [
        '--agent' => ['claude'],
        '--with-mcp' => true,
        '--force' => true,
        '--no-interaction' => true,
    ]);
    expect((string) file_get_contents($this->base.'/.env'))->toContain('MARTIS_MCP_ENABLED=true');

    Artisan::call('martis:agents', [
        '--agent' => ['claude'],
        '--mcp-unwire' => true,
        '--no-interaction' => true,
    ]);

    $payload = json_decode((string) file_get_contents($this->base.'/.mcp.json'), true);
    expect($payload['mcpServers'] ?? [])->not->toHaveKey('martis');
    expect((string) file_get_contents($this->base.'/.env'))->not->toContain('MARTIS_MCP_ENABLED');
});

it('is idempotent across consecutive runs', function () {
    Artisan::call('martis:agents', [
        '--agent' => ['claude'],
        '--with-mcp' => true,
        '--force' => true,
        '--no-interaction' => true,
    ]);
    $firstAgents = (string) file_get_contents($this->base.'/AGENTS.md');
    $firstMcp = (string) file_get_contents($this->base.'/.mcp.json');

    Artisan::call('martis:agents', [
        '--agent' => ['claude'],
        '--with-mcp' => true,
        '--force' => true,
        '--no-interaction' => true,
    ]);
    expect((string) file_get_contents($this->base.'/AGENTS.md'))->toBe($firstAgents);
    expect((string) file_get_contents($this->base.'/.mcp.json'))->toBe($firstMcp);
});
