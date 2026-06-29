<?php

declare(strict_types=1);

use Martis\Support\AgentProfile;
use Martis\Support\McpConfigPatcher;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/martis-mcp-'.uniqid();
    mkdir($this->base, 0755, true);
});

afterEach(function () {
    if (isset($this->base) && is_dir($this->base)) {
        rmtree($this->base);
    }
});

if (! function_exists('claudeProfile')) {
    function claudeProfile(): AgentProfile
    {
        return new AgentProfile(
            key: 'claude',
            label: 'Claude Code',
            guidelineFile: 'CLAUDE.md',
            detectionPaths: [],
            mcpConfigFile: '.mcp.json',
            mcpFormat: AgentProfile::FORMAT_JSON,
        );
    }

}
if (! function_exists('codexProfile')) {
    function codexProfile(): AgentProfile
    {
        return new AgentProfile(
            key: 'codex',
            label: 'Codex',
            guidelineFile: 'AGENTS.md',
            detectionPaths: [],
            mcpConfigFile: '.codex/config.toml',
            mcpFormat: AgentProfile::FORMAT_TOML,
            mcpRootKey: 'mcp_servers',
        );
    }
}

it('creates a JSON MCP config when none exists', function () {
    $patcher = new McpConfigPatcher($this->base);
    $entry = ['command' => 'php', 'args' => ['artisan', 'martis:mcp-serve'], 'cwd' => $this->base];

    $written = $patcher->patch(claudeProfile(), $entry);

    expect($written)->toEndWith('.mcp.json')->and(file_exists($written))->toBeTrue();
    $payload = json_decode((string) file_get_contents($written), true);
    expect($payload['mcpServers']['martis'])->toBe($entry);
});

it('preserves existing MCP servers when patching', function () {
    file_put_contents($this->base.'/.mcp.json', json_encode([
        'mcpServers' => [
            'other' => ['command' => 'node', 'args' => ['serve.js']],
        ],
    ]));
    $patcher = new McpConfigPatcher($this->base);
    $patcher->patch(claudeProfile(), ['command' => 'php', 'args' => ['artisan', 'martis:mcp-serve']]);

    $payload = json_decode((string) file_get_contents($this->base.'/.mcp.json'), true);
    expect($payload['mcpServers'])->toHaveKeys(['martis', 'other']);
});

it('is idempotent across multiple runs', function () {
    $patcher = new McpConfigPatcher($this->base);
    $entry = ['command' => 'php', 'args' => ['artisan', 'martis:mcp-serve']];

    $patcher->patch(claudeProfile(), $entry);
    $first = (string) file_get_contents($this->base.'/.mcp.json');
    $patcher->patch(claudeProfile(), $entry);
    $second = (string) file_get_contents($this->base.'/.mcp.json');

    expect($first)->toBe($second);
});

it('writes a TOML config for codex with the mcp_servers root', function () {
    $patcher = new McpConfigPatcher($this->base);
    $entry = ['command' => 'php', 'args' => ['artisan', 'martis:mcp-serve'], 'cwd' => '/abs'];

    $patcher->patch(codexProfile(), $entry);

    $body = (string) file_get_contents($this->base.'/.codex/config.toml');
    expect($body)->toContain('[mcp_servers.martis]')
        ->toContain('command = "php"')
        ->toContain('cwd = "/abs"');
});

it('removes only the martis entry on unwire', function () {
    file_put_contents($this->base.'/.mcp.json', json_encode([
        'mcpServers' => [
            'other' => ['command' => 'node'],
            'martis' => ['command' => 'php'],
        ],
    ]));
    $patcher = new McpConfigPatcher($this->base);
    $removed = $patcher->unwire(claudeProfile());

    expect($removed)->toBeTrue();
    $payload = json_decode((string) file_get_contents($this->base.'/.mcp.json'), true);
    expect($payload['mcpServers'])->toHaveKey('other')->not->toHaveKey('martis');
});

it('writes a backup file before mutating an existing config', function () {
    file_put_contents($this->base.'/.mcp.json', json_encode(['mcpServers' => []]));
    (new McpConfigPatcher($this->base))->patch(claudeProfile(), ['command' => 'php']);

    expect(file_exists($this->base.'/.mcp.json.bak'))->toBeTrue();
});

it('round-trips a TOML inline array whose string values contain commas', function () {
    // Manually craft a TOML file with an args array where one element
    // contains a comma inside a quoted string. The naive explode(',', …)
    // implementation would split that element into two, corrupting the
    // round-trip. The fixed splitTomlInlineArray() must keep quoted commas
    // as part of their enclosing token.
    $toml = <<<'TOML'
[mcp_servers.other]
args = ["artisan", "foo,bar", "baz"]
TOML;

    mkdir($this->base.'/.codex', 0755, true);
    file_put_contents($this->base.'/.codex/config.toml', $toml);

    $patcher = new McpConfigPatcher($this->base);
    $entry = ['command' => 'php', 'args' => ['artisan', 'martis:mcp-serve'], 'cwd' => $this->base];
    $patcher->patch(codexProfile(), $entry);

    // The "other" section args must still be intact after patching.
    $written = (string) file_get_contents($this->base.'/.codex/config.toml');

    // The comma-containing token must appear unmodified in the output.
    expect($written)->toContain('"foo,bar"');
});
