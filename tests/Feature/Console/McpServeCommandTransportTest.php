<?php

declare(strict_types=1);

use Martis\Tests\Support\SkeletonSnapshot;
use Martis\Tests\TestCase;
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
 *   APP_BASE_PATH is the skeleton the test application runs in, so the
 *   subprocess boots there too: a parallel worker has a copy of its own
 *   (TestCase::applicationBasePath()), and the afterEach hook looks there
 *   for the `.env` a killed subprocess leaves. Testbench reads APP_BASE_PATH
 *   from $_ENV, which PHP fills from the environment only when
 *   variables_order has an E.
 */
function pickPort(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($sock, false);
    fclose($sock);

    return (int) substr((string) $name, strrpos($name, ':') + 1);
}

/**
 * The ceiling of every wait on the subprocess, in seconds:
 * MARTIS_TEST_PROCESS_TIMEOUT, 30 by default. Each wait ends as soon as its
 * condition holds, so a fast machine never waits for it; a loaded one
 * (parallel suites, a Docker VM, where a fixed 5s budget failed) gets the
 * room it needs.
 */
function mcpBudget(): float
{
    $raw = getenv('MARTIS_TEST_PROCESS_TIMEOUT');

    return is_string($raw) && is_numeric($raw) && (float) $raw > 0 ? (float) $raw : 30.0;
}

/**
 * Wait until `$condition` holds, and fail with why it did not: how long it
 * waited, whether the process exited (and its exit code) or was still
 * running, and its stderr and stdout. Stops waiting as soon as the process
 * exits, unless `$untilExit` is what it waits for.
 */
function waitUntil(Process $process, callable $condition, string $what, ?float $budget = null, bool $untilExit = false): void
{
    $budget ??= mcpBudget();
    $started = microtime(true);

    while (true) {
        if ($condition()) {
            return;
        }
        if (! $untilExit && ! $process->isRunning()) {
            break;
        }
        if (microtime(true) - $started >= $budget) {
            break;
        }
        usleep(50_000);
    }

    throw new RuntimeException(processDiagnostics($process, $what, microtime(true) - $started));
}

function processDiagnostics(Process $process, string $what, float $waited): string
{
    $state = $process->isRunning()
        ? 'the process was still running'
        : 'the process had exited with code '.var_export($process->getExitCode(), true);

    return sprintf(
        "%s: gave up after %.1fs (ceiling %gs, MARTIS_TEST_PROCESS_TIMEOUT); %s.\n--- stderr ---\n%s\n--- stdout ---\n%s",
        $what,
        $waited,
        mcpBudget(),
        $state,
        trim($process->getErrorOutput()) ?: '(empty)',
        trim($process->getOutput()) ?: '(empty)',
    );
}

/** Block until the server accepts connections on the port. */
function waitForPort(Process $process, string $host, int $port): void
{
    waitUntil($process, function () use ($host, $port): bool {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);

            return true;
        }

        return false;
    }, "the server did not bind {$host}:{$port}");
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

function mcpServeProcess(array $extraArgs = [], array $extraEnv = []): Process
{
    $root = artisanPath();
    $cmd = ['php', '-d', 'variables_order=EGPCS', 'vendor/bin/testbench', 'martis:mcp-serve', ...$extraArgs];
    $env = array_merge($_SERVER, $_ENV, $extraEnv, [
        'TESTBENCH_WORKING_PATH' => $root,
        'APP_BASE_PATH' => base_path(),
    ]);

    return new Process($cmd, $root, $env);
}

function spawnServe(array $extraArgs = [], array $extraEnv = []): Process
{
    $process = mcpServeProcess($extraArgs, $extraEnv);
    $process->start();
    $GLOBALS['__martis_serve_processes'][] = $process;

    return $process;
}

/**
 * Stop a server the test is done with: SIGTERM, then SIGKILL after the
 * budget, so a slow shutdown is not cut short into a SIGKILL that leaves
 * the skeleton's `.env` behind.
 */
function stopServe(Process $process): void
{
    $process->stop(mcpBudget(), defined('SIGKILL') ? SIGKILL : 9);
}

/**
 * Block until `Server is up and listening` appears on stderr, proving the
 * ReactPHP loop has registered its signal handlers. Without this gate the
 * SIGTERM test races the boot sequence on slow CI runners and fires the
 * signal before the handler is wired, leaving the subprocess running.
 */
function waitForServeReady(Process $process): void
{
    waitUntil($process, fn (): bool => str_contains($process->getErrorOutput(), 'is up and listening'), 'the signal handlers were not registered');
}

/** A POST to the MCP endpoint: [status, body]. */
function mcpPost(int $port, string $payload, array $headers): array
{
    $ch = curl_init("http://127.0.0.1:{$port}/mcp");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int) ceil(mcpBudget()));
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, (string) $body];
}

// `vendor/bin/testbench` also links the package's vendor/ into the skeleton
// while it runs and removes the link only when it exits cleanly: put the
// skeleton's `vendor` back as this file found it.
beforeAll(function () {
    $GLOBALS['__martis_mcp_skeleton'] = SkeletonSnapshot::take(TestCase::applicationBasePath(), ['vendor']);
});

afterAll(function () {
    $GLOBALS['__martis_mcp_skeleton']->restore();
});

beforeEach(function () {
    // `vendor/bin/testbench` puts a `.env` in the skeleton when it boots (a
    // copy of the package root's `.env`, `.env.example` or `.env.dist` when
    // there is one, otherwise of the skeleton's `.env.example`) and deletes
    // it only when it exits cleanly or traps the signal. A subprocess killed
    // with SIGKILL (the stop() fallback below), or sent SIGTERM before it
    // registers its handlers, leaves the copy in the testbench skeleton
    // under vendor/, where every later `vendor/bin/testbench` run finds it.
    // Remember whether a `.env` was there, so the afterEach hook removes
    // only a copy this test left.
    $this->skeletonEnvExisted = is_file(base_path('.env'));
});

afterEach(function () {
    foreach ($GLOBALS['__martis_serve_processes'] as $p) {
        if ($p instanceof Process && $p->isRunning()) {
            // SIGTERM first, then SIGKILL after a 5s grace. Symfony's
            // Process::stop() handles the escalation but we need to
            // also reap the pid so the next test file doesn't inherit
            // an orphaned listener on the bound port.
            $p->stop(5, defined('SIGKILL') ? SIGKILL : 9);
        }
    }
    $GLOBALS['__martis_serve_processes'] = [];

    // Unknown (setUp failed before beforeEach ran) counts as "was there":
    // never delete a `.env` this test did not see appear.
    if (! ($this->skeletonEnvExisted ?? true) && is_file(base_path('.env'))) {
        @unlink(base_path('.env'));
    }
});

it('http transport responds to tools/list on /mcp', function () {
    $port = pickPort();
    $process = spawnServe(['--transport=http', "--port={$port}", '--no-warn-on-public']);

    waitForPort($process, '127.0.0.1', $port);

    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ]);

    [$status, $body] = mcpPost($port, (string) $payload, [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
    ]);

    stopServe($process);

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

    waitForPort($process, '127.0.0.1', $port);

    [$status] = mcpPost($port, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', ['Content-Type: application/json']);

    stopServe($process);

    expect($status)->toBe(401);
});

it('http transport with token accepts correct Authorization', function () {
    $port = pickPort();
    $process = spawnServe(
        ['--transport=http', "--port={$port}", '--no-warn-on-public'],
        ['MARTIS_MCP_HTTP_TOKEN' => 'test-token-xyz'],
    );

    waitForPort($process, '127.0.0.1', $port);

    [$status] = mcpPost($port, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'Authorization: Bearer test-token-xyz',
    ]);

    stopServe($process);

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

    waitForPort($process, '127.0.0.1', $mcpPort);
    waitForPort($process, '127.0.0.1', $healthPort);

    $ch = curl_init("http://127.0.0.1:{$healthPort}/health");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int) ceil(mcpBudget()));
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    stopServe($process);

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

    waitForPort($process, '0.0.0.0', $port);
    // The warnings are written before the loop reports it is up.
    waitForServeReady($process);
    $stderr = $process->getIncrementalErrorOutput();
    stopServe($process);

    expect($stderr)->toContain('0.0.0.0')->toContain('MARTIS_MCP_HTTP_TOKEN');

    // No-warn flag silences it.
    $port2 = pickPort();
    $silent = spawnServe(['--transport=http', '--host=0.0.0.0', "--port={$port2}", '--no-warn-on-public']);
    waitForPort($silent, '0.0.0.0', $port2);
    waitForServeReady($silent);
    $silentStderr = $silent->getIncrementalErrorOutput();
    stopServe($silent);

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

    waitForPort($process, '0.0.0.0', $port);
    // The warnings are written before the loop reports it is up.
    waitForServeReady($process);
    $stderr = $process->getIncrementalErrorOutput();
    stopServe($process);

    // The MCP docs API warning must NOT fire (token is set).
    expect($stderr)->not->toContain('MARTIS_MCP_HTTP_TOKEN');
    // The health endpoint warning MUST fire.
    expect($stderr)->toContain('/health')->toContain('0.0.0.0');
});

it('stdio default keeps producing the three tools (regression guard)', function () {
    $process = mcpServeProcess();
    $process->setInput(
        '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"smoke","version":"1.0"}}}'."\n".
        '{"jsonrpc":"2.0","method":"notifications/initialized"}'."\n".
        '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'."\n"
    );
    $process->setTimeout(null);
    $process->start();
    $GLOBALS['__martis_serve_processes'][] = $process;

    // The process ends on its own once stdin (the input above) is consumed.
    waitUntil($process, fn (): bool => ! $process->isRunning(), 'the stdio server did not exit after its input', untilExit: true);

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
    waitForPort($process, '127.0.0.1', $port);
    waitForServeReady($process);

    $pid = $process->getPid();
    expect($pid)->toBeInt();
    $process->signal(SIGTERM);

    // The loop drains and exits; the afterEach kills it if it does not,
    // so a failure here never leaks the subprocess into later tests.
    waitUntil($process, fn (): bool => ! $process->isRunning(), 'the server did not exit after SIGTERM', untilExit: true);
    expect($process->getExitCode())->toBe(0, processDiagnostics($process, 'the server exited with an error after SIGTERM', 0.0));
});
