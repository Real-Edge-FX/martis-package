<?php

declare(strict_types=1);

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** Seconds a subprocess wait may take (KNOWN_ISSUES: conditional waits only). */
function mcpStdioTimeout(): float
{
    $raw = getenv('MARTIS_TEST_PROCESS_TIMEOUT');

    return is_string($raw) && is_numeric($raw) && (float) $raw > 0 ? (float) $raw : 30.0;
}

function mcpStdioProcess(InputStream $input): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process([PHP_BINARY, $root.'/tests/Support/mcp-artisan.php', 'mcp:start', 'martis-docs'], $root);
    $process->setInput($input);
    $process->setTimeout(mcpStdioTimeout() * 2);
    $process->start();

    return $process;
}

function mcpLine(array $message): string
{
    return json_encode($message, JSON_THROW_ON_ERROR)."\n";
}

/** @return array<int|string, array<string, mixed>> JSON-RPC responses keyed by id */
function mcpResponses(string $stdout): array
{
    $byId = [];
    foreach (explode("\n", trim($stdout)) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && array_key_exists('id', $decoded)) {
            $byId[$decoded['id']] = $decoded;
        }
    }

    return $byId;
}

$initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
    'protocolVersion' => '2025-06-18',
    'capabilities' => (object) [],
    'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
]];

it('answers over stdio in full and exits on end of input', function () use ($initialize) {
    $input = new InputStream;
    $process = mcpStdioProcess($input);

    $input->write(mcpLine($initialize));
    $input->write(mcpLine(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    $input->write(mcpLine(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']));
    // fields.md is the largest page (over 200 KB): far beyond a pipe buffer.
    $input->write(mcpLine(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
        'name' => 'martis_doc_read', 'arguments' => ['slug' => 'fields'],
    ]]));
    $input->close();

    $exit = $process->wait();
    $responses = mcpResponses($process->getOutput());

    expect($exit)->toBe(0, $process->getErrorOutput())
        ->and($responses[1]['result']['serverInfo']['name'])->toBe('Martis Docs')
        ->and(array_column($responses[2]['result']['tools'], 'name'))
        ->toEqualCanonicalizing(['martis_doc_list', 'martis_doc_read', 'martis_doc_search']);

    $payload = json_decode($responses[3]['result']['content'][0]['text'], true);
    expect($payload['content'])->toBe(file_get_contents(dirname(__DIR__, 3).'/docs/fields.md'));
});

it('stops promptly on SIGTERM', function () use ($initialize) {
    $input = new InputStream;
    $process = mcpStdioProcess($input);
    $input->write(mcpLine($initialize));

    $deadline = microtime(true) + mcpStdioTimeout();
    while (! str_contains($process->getOutput(), '"id":1') && $process->isRunning() && microtime(true) < $deadline) {
        usleep(20_000);
    }
    expect($process->getOutput())->toContain('"id":1');

    $process->signal(15);

    $deadline = microtime(true) + mcpStdioTimeout();
    while ($process->isRunning() && microtime(true) < $deadline) {
        usleep(20_000);
    }

    expect($process->isRunning())->toBeFalse('mcp:start was still running after SIGTERM');
})->skip(! function_exists('posix_kill') && PHP_OS_FAMILY === 'Windows', 'POSIX signals only');
