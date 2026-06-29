<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Integration smoke tests for transport selection in `martis:mcp-serve`.
 *
 * Spawns `vendor/bin/testbench martis:mcp-serve` as a real subprocess via
 * Symfony Process so the test exercises the actual command boot, including
 * the ReactPHP event loop in http mode.
 *
 * stdio tests pipe JSON-RPC through stdin and read from stdout.
 *
 * http tests pick a free ephemeral port, wait for the socket to appear,
 * then curl the endpoint and assert the response.
 *
 * Path resolution:
 *   artisanPath() returns the package root (dirname(__DIR__, 3)).
 *   The testbench CLI binary is at vendor/bin/testbench relative to that.
 *   TESTBENCH_WORKING_PATH must point at the same root so the testbench
 *   bootstrap can locate vendor/autoload.php and load the package providers.
 */
function pickPort(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($sock, false);
    fclose($sock);

    return (int) substr((string) $name, strrpos($name, ':') + 1);
}

function waitForPort(string $host, int $port, float $timeoutSec = 5.0): bool
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);

            return true;
        }
        usleep(100_000);
    }

    return false;
}

function artisanPath(): string
{
    // tests/Feature/Console -> tests/Feature -> tests -> package-root
    return dirname(__DIR__, 3);
}

/**
 * Tracks every Process spawned by spawnServe() inside this test file so
 * the afterEach hook can force-kill survivors even when an assertion
 * throws mid-test. Without this, a flaky SIGTERM test on slow CI runners
 * leaves the subprocess bound to its port, and downstream Pest tests
 * (DashboardBreadcrumbTest, DependsOnSyncTest, etc.) start failing
 * with 500s because their HTTP test client hits the stale process.
 */
$GLOBALS['__martis_serve_processes'] = [];

function spawnServe(array $extraArgs = [], array $extraEnv = []): Process
{
    $root = artisanPath();
    $cmd = ['php', 'vendor/bin/testbench', 'martis:mcp-serve', ...$extraArgs];
    $env = array_merge($_SERVER, $_ENV, $extraEnv, [
        'TESTBENCH_WORKING_PATH' => $root,
    ]);
    $process = new Process($cmd, $root, $env);
    $process->start();
    $GLOBALS['__martis_serve_processes'][] = $process;

    return $process;
}

/**
 * Block until `Server is up and listening` (or any "listening" marker)
 * appears on stderr, proving the ReactPHP loop has registered its
 * signal handlers. Without this gate the SIGTERM test races the boot
 * sequence on slow CI runners and fires the signal before the handler
 * is wired, leaving the subprocess running.
 */
function waitForServeReady(Process $process, float $timeoutSec = 8.0): bool
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        if (str_contains($process->getErrorOutput(), 'is up and listening')) {
            return true;
        }
        usleep(50_000);
    }

    return false;
}

afterEach(function () {
    foreach ($GLOBALS['__martis_serve_processes'] as $p) {
        if ($p instanceof Process && $p->isRunning()) {
            // SIGTERM first, then SIGKILL after the 1s grace. Symfony's
            // Process::stop() handles the escalation but we need to
            // also reap the pid so the next test file doesn't inherit
            // an orphaned listener on the bound port.
            $p->stop(1, defined('SIGKILL') ? SIGKILL : 9);
        }
    }
    $GLOBALS['__martis_serve_processes'] = [];
});

it('http transport responds to tools/list on /mcp', function () {
    $port = pickPort();
    $process = spawnServe(['--transport=http', "--port={$port}", '--no-warn-on-public']);

    expect(waitForPort('127.0.0.1', $port))->toBeTrue('server did not bind in time');

    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ]);

    $ch = curl_init("http://127.0.0.1:{$port}/mcp");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $process->stop(2);

    expect($status)->toBe(200);
    $decoded = json_decode((string) $body, true);
    expect($decoded['result']['tools'])->toBeArray()->toHaveCount(3);
    $names = array_map(fn ($t) => $t['name'], $decoded['result']['tools']);
    expect($names)->toContain('martis_doc_list', 'martis_doc_read', 'martis_doc_search');
});

it('http transport with token rejects missing Authorization with 401', function () {
    $port = pickPort();
    $process = spawnServe(
        ['--transport=http', "--port={$port}", '--no-warn-on-public'],
        ['MARTIS_MCP_HTTP_TOKEN' => 'test-token-xyz'],
    );

    expect(waitForPort('127.0.0.1', $port))->toBeTrue();

    $ch = curl_init("http://127.0.0.1:{$port}/mcp");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $process->stop(2);

    expect($status)->toBe(401);
});

it('http transport with token accepts correct Authorization', function () {
    $port = pickPort();
    $process = spawnServe(
        ['--transport=http', "--port={$port}", '--no-warn-on-public'],
        ['MARTIS_MCP_HTTP_TOKEN' => 'test-token-xyz'],
    );

    expect(waitForPort('127.0.0.1', $port))->toBeTrue();

    $ch = curl_init("http://127.0.0.1:{$port}/mcp");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'Authorization: Bearer test-token-xyz',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $process->stop(2);

    expect($status)->toBe(200);
});

it('http transport exposes /health on the configured port when --health-port is set', function () {
    $mcpPort = pickPort();
    $healthPort = pickPort();
    $process = spawnServe([
        '--transport=http',
        "--port={$mcpPort}",
        "--health-port={$healthPort}",
        '--no-warn-on-public',
    ]);

    expect(waitForPort('127.0.0.1', $mcpPort))->toBeTrue();
    expect(waitForPort('127.0.0.1', $healthPort))->toBeTrue();

    $ch = curl_init("http://127.0.0.1:{$healthPort}/health");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $process->stop(2);

    expect($status)->toBe(200);
    $decoded = json_decode((string) $body, true);
    expect($decoded)->toMatchArray([
        'status' => 'ok',
        'transport' => 'http',
        'tool_count' => 3,
    ]);
});

it('warns when host=0.0.0.0 without a token (and stays silent with --no-warn-on-public)', function () {
    $port = pickPort();
    $process = spawnServe(['--transport=http', '--host=0.0.0.0', "--port={$port}"]);

    expect(waitForPort('0.0.0.0', $port))->toBeTrue();
    usleep(200_000);
    $stderr = $process->getIncrementalErrorOutput();
    $process->stop(2);

    expect($stderr)->toContain('0.0.0.0')->toContain('MARTIS_MCP_HTTP_TOKEN');

    // No-warn flag silences it.
    $port2 = pickPort();
    $silent = spawnServe(['--transport=http', '--host=0.0.0.0', "--port={$port2}", '--no-warn-on-public']);
    expect(waitForPort('0.0.0.0', $port2))->toBeTrue();
    usleep(200_000);
    $silentStderr = $silent->getIncrementalErrorOutput();
    $silent->stop(2);

    expect($silentStderr)->not->toContain('MARTIS_MCP_HTTP_TOKEN');
});

it('warns about the health endpoint on 0.0.0.0 even when a token is set', function () {
    // Setting a token silenced the original warning, but the health endpoint
    // was still publicly bound without authentication. The fix emits a dedicated
    // warning for the health port whenever host=0.0.0.0 and --health-port is active,
    // regardless of whether MARTIS_MCP_HTTP_TOKEN is set.
    $port = pickPort();
    $healthPort = pickPort();
    $process = spawnServe(
        ['--transport=http', '--host=0.0.0.0', "--port={$port}", "--health-port={$healthPort}"],
        ['MARTIS_MCP_HTTP_TOKEN' => 'test-token-for-health-warn'],
    );

    expect(waitForPort('0.0.0.0', $port))->toBeTrue();
    usleep(200_000);
    $stderr = $process->getIncrementalErrorOutput();
    $process->stop(2);

    // The MCP docs API warning must NOT fire (token is set).
    expect($stderr)->not->toContain('MARTIS_MCP_HTTP_TOKEN');
    // The health endpoint warning MUST fire.
    expect($stderr)->toContain('/health')->toContain('0.0.0.0');
});

it('stdio default keeps producing the three tools (regression guard)', function () {
    $root = artisanPath();
    $process = new Process(
        ['php', 'vendor/bin/testbench', 'martis:mcp-serve'],
        $root,
        array_merge($_SERVER, $_ENV, ['TESTBENCH_WORKING_PATH' => $root]),
    );
    $process->setInput(
        '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"smoke","version":"1.0"}}}'."\n".
        '{"jsonrpc":"2.0","method":"notifications/initialized"}'."\n".
        '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'."\n"
    );
    $process->setTimeout(5);
    $process->start();

    // Wait for process to finish naturally (stdin closes after setInput is consumed).
    $deadline = microtime(true) + 5.0;
    while ($process->isRunning() && microtime(true) < $deadline) {
        usleep(100_000);
    }
    $process->stop(2);

    // The MCP server logs tool registrations and dispatched responses to stderr
    // (all DEBUG/INFO lines go through the logger which writes to STDERR).
    // The ReactPHP WritableResourceStream for stdout may not flush before the
    // loop stops on a closed-stdin scenario, so we assert on stderr which is
    // reliably written synchronously by fwrite(STDERR, ...) in the logger.
    $combined = $process->getOutput().$process->getErrorOutput();
    expect($combined)->toContain('martis_doc_list')
        ->toContain('martis_doc_read')
        ->toContain('martis_doc_search');
});

it('exits cleanly on SIGTERM in http mode', function () {
    $port = pickPort();
    $process = spawnServe(['--transport=http', "--port={$port}", '--no-warn-on-public']);

    // Wait for the socket AND the "is up and listening" stderr marker.
    // Both must be true before we send SIGTERM — otherwise the signal
    // races the ReactPHP loop's signal-handler registration and the
    // process never receives the SIGTERM cleanly. Previously this was
    // a "wait 5s" wall clock and flaked on slow runners; the marker
    // gate is deterministic.
    expect(waitForPort('127.0.0.1', $port))->toBeTrue('server did not bind in time');
    expect(waitForServeReady($process))->toBeTrue('signal handlers were not registered in time');

    $pid = $process->getPid();
    expect($pid)->toBeInt();
    $process->signal(SIGTERM);

    // Generous 10s budget for the loop to drain on slow CI. The
    // afterEach will SIGKILL if this is still running, so a flaky
    // failure here never leaks the subprocess into downstream tests.
    $started = microtime(true);
    while ($process->isRunning() && microtime(true) - $started < 10.0) {
        usleep(50_000);
    }
    expect($process->isRunning())->toBeFalse('process did not exit within 10s of SIGTERM');
    expect($process->getExitCode())->toBe(0);
});
