<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/martis-docguard-'.uniqid();
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

/** Run the installed guard script with a tool-call payload; return its exit code. */
function runMartisDocGuard(string $scriptPath, array $payload): int
{
    $proc = proc_open(
        [PHP_BINARY, $scriptPath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    fwrite($pipes[0], (string) json_encode($payload));
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return proc_close($proc);
}

$guardArgs = [
    '--agent' => ['claude'],
    '--with-mcp' => true,
    '--with-doc-guard' => true,
    '--force' => true,
    '--no-interaction' => true,
];

it('installs the doc-guard script and a PreToolUse hook', function () use ($guardArgs) {
    Artisan::call('martis:agents', $guardArgs);

    $script = $this->base.'/.claude/martis-doc-guard.php';
    $settings = $this->base.'/.claude/settings.json';
    expect(file_exists($script))->toBeTrue()
        ->and(file_exists($settings))->toBeTrue();

    $cfg = json_decode((string) file_get_contents($settings), true);
    $pre = $cfg['hooks']['PreToolUse'] ?? [];
    expect($pre)->toHaveCount(1)
        ->and($pre[0]['matcher'])->toBe('Bash|Read|Grep|Glob')
        ->and($pre[0]['hooks'][0]['command'])->toContain('martis-doc-guard.php');
});

it('is idempotent — a second run does not duplicate the hook', function () use ($guardArgs) {
    Artisan::call('martis:agents', $guardArgs);
    Artisan::call('martis:agents', $guardArgs);

    $cfg = json_decode((string) file_get_contents($this->base.'/.claude/settings.json'), true);
    expect($cfg['hooks']['PreToolUse'])->toHaveCount(1);
});

it('preserves unrelated settings and existing hooks when patching', function () use ($guardArgs) {
    mkdir($this->base.'/.claude', 0755, true);
    file_put_contents($this->base.'/.claude/settings.json', json_encode([
        'model' => 'sonnet',
        'hooks' => ['PreToolUse' => [
            ['matcher' => 'Write', 'hooks' => [['type' => 'command', 'command' => 'echo keep']]],
        ]],
    ]));

    Artisan::call('martis:agents', $guardArgs);

    $cfg = json_decode((string) file_get_contents($this->base.'/.claude/settings.json'), true);
    expect($cfg['model'])->toBe('sonnet')
        ->and($cfg['hooks']['PreToolUse'])->toHaveCount(2);
});

it('blocks filesystem docs reads but allows a mere mention of the path', function () use ($guardArgs) {
    Artisan::call('martis:agents', $guardArgs);
    $script = $this->base.'/.claude/martis-doc-guard.php';
    $docs = 'vendor/martis/martis/docs';

    // Real reads → exit 2 (blocked).
    expect(runMartisDocGuard($script, ['tool_name' => 'Read', 'tool_input' => ['file_path' => $docs.'/fields.md']]))->toBe(2)
        ->and(runMartisDocGuard($script, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'cat '.$docs.'/fields.md']]))->toBe(2)
        ->and(runMartisDocGuard($script, ['tool_name' => 'Grep', 'tool_input' => ['pattern' => 'x', 'path' => $docs]]))->toBe(2)
        ->and(runMartisDocGuard($script, ['tool_name' => 'Glob', 'tool_input' => ['pattern' => $docs.'/*.md']]))->toBe(2);

    // Mentions / unrelated → exit 0 (allowed). The middle one is the exact
    // false positive the blunt guard produced: a search whose PATTERN is the
    // path string, not a docs read.
    expect(runMartisDocGuard($script, ['tool_name' => 'Bash', 'tool_input' => ['command' => "grep -n '".$docs."' AGENTS.md"]]))->toBe(0)
        ->and(runMartisDocGuard($script, ['tool_name' => 'Read', 'tool_input' => ['file_path' => 'app/Models/User.php']]))->toBe(0)
        ->and(runMartisDocGuard($script, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'php artisan test']]))->toBe(0);
});
