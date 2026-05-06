<?php

declare(strict_types=1);

use Martis\Support\AgentDetector;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/martis-agents-'.uniqid();
    mkdir($this->base, 0755, true);
});

afterEach(function () {
    if (isset($this->base) && is_dir($this->base)) {
        rmtree($this->base);
    }
});

it('detects Claude Code via CLAUDE.md', function () {
    file_put_contents($this->base.'/CLAUDE.md', '# Claude');
    $detector = new AgentDetector($this->base);
    $hits = array_map(fn ($p) => $p->key, $detector->detect());

    expect($hits)->toContain('claude');
});

it('detects Cursor via .cursor/', function () {
    mkdir($this->base.'/.cursor');
    $detector = new AgentDetector($this->base);
    $hits = array_map(fn ($p) => $p->key, $detector->detect());

    expect($hits)->toContain('cursor');
});

it('returns multiple agents when more than one signal is present', function () {
    file_put_contents($this->base.'/CLAUDE.md', '# Claude');
    mkdir($this->base.'/.gemini');
    $detector = new AgentDetector($this->base);
    $hits = array_map(fn ($p) => $p->key, $detector->detect());

    expect($hits)->toContain('claude')->toContain('gemini');
});

it('returns empty when no signals exist', function () {
    expect((new AgentDetector($this->base))->detect())->toBe([]);
});

it('exposes profileByKey for explicit selection', function () {
    $detector = new AgentDetector($this->base);
    expect($detector->profileByKey('codex'))->not->toBeNull()
        ->and($detector->profileByKey('codex')->mcpFormat)->toBe('toml')
        ->and($detector->profileByKey('nope'))->toBeNull();
});
