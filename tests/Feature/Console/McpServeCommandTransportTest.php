<?php

declare(strict_types=1);

use Martis\Console\McpServeCommand;
use Martis\Tests\Support\SkeletonSnapshot;
use Martis\Tests\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
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
    if ($raw === false || $raw === '') {
        return 30.0;
    }
    if (! is_numeric($raw) || (float) $raw <= 0) {
        throw new InvalidArgumentException("MARTIS_TEST_PROCESS_TIMEOUT must be a positive number of seconds, got \"{$raw}\".");
    }

    return (float) $raw;
}

/**
 * Wait until `$condition` holds, and fail with why it did not: how long it
 * waited, whether the process exited (and its exit code) or was still
 * running, and its stderr and stdout. Stops waiting as soon as the process
 * exits, unless `$untilExit` is what it waits for.
 */
function waitUntil(Process $process, callable $condition, string $what, bool $untilExit = false): void
{
    $started = microtime(true);

    while (true) {
        if ($condition()) {
            return;
        }
        if (! $untilExit && ! $process->isRunning()) {
            break;
        }
        if (microtime(true) - $started >= mcpBudget()) {
            break;
        }
        usleep(50_000);
    }

    throw new RuntimeException(sprintf(
        '%s (waited %.1fs of the %gs MARTIS_TEST_PROCESS_TIMEOUT ceiling); %s',
        $what,
        microtime(true) - $started,
        mcpBudget(),
        describeProcess($process),
    ));
}

/** Whether the process runs or how it exited, and what it wrote. */
function describeProcess(Process $process): string
{
    $state = $process->isRunning()
        ? 'the process was still running'
        : 'the process had exited with code '.var_export($process->getExitCode(), true);

    return sprintf(
        "%s.\n--- stderr ---\n%s\n--- stdout ---\n%s",
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
 * Block until `Server is up and listening` appears on stderr. The server
 * logs it inside `listen()`, before it runs the loop, so it is not proof
 * that the loop runs: a signal sent right after it can arrive before
 * `run()`. `mcpRoundTrip()` is that proof.
 */
function waitForServeReady(Process $process): void
{
    waitUntil($process, fn (): bool => str_contains($process->getErrorOutput(), 'is up and listening'), 'the server did not report it was listening');
}

/**
 * A request to `$url`: [status, body, curl error]. The status and the error
 * go into every assertion message, with the process's own diagnostics.
 *
 * @param  list<string>  $headers
 * @return array{0: int, 1: string, 2: string}
 */
function mcpRequest(string $url, ?string $payload = null, array $headers = []): array
{
    $ch = curl_init($url);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int) ceil(mcpBudget()));
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [$status, (string) $body, $error];
}

/** A POST to the MCP endpoint. */
function mcpPost(int $port, string $payload, array $headers): array
{
    return mcpRequest("http://127.0.0.1:{$port}/mcp", $payload, $headers);
}

/** What an assertion on a response says when it fails. */
function responseDiagnostics(Process $process, array $response): string
{
    [$status, $body, $error] = $response;

    return sprintf('status %d, curl error "%s", body %s; %s', $status, $error, $body === '' ? '(empty)' : $body, describeProcess($process));
}

/**
 * An HTTP round trip to the server: an answer (any status) proves its loop
 * runs, so a signal sent now reaches the handlers it registered.
 */
function mcpRoundTrip(Process $process, int $port): void
{
    $response = mcpPost($port, '{"jsonrpc":"2.0","id":99,"method":"ping"}', ['Content-Type: application/json', 'Accept: application/json, text/event-stream']);

    expect($response[0])->toBeGreaterThan(0, 'the server did not answer a ping: '.responseDiagnostics($process, $response));
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

    $response = mcpPost($port, (string) $payload, [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
    ]);
    [$status, $body] = $response;

    expect($status)->toBe(200, responseDiagnostics($process, $response));
    stopServe($process);

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

    $response = mcpPost($port, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', ['Content-Type: application/json']);

    expect($response[0])->toBe(401, responseDiagnostics($process, $response));
    stopServe($process);
});

it('http transport with token accepts correct Authorization', function () {
    $port = pickPort();
    $process = spawnServe(
        ['--transport=http', "--port={$port}", '--no-warn-on-public'],
        ['MARTIS_MCP_HTTP_TOKEN' => 'test-token-xyz'],
    );

    waitForPort($process, '127.0.0.1', $port);

    $response = mcpPost($port, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'Authorization: Bearer test-token-xyz',
    ]);

    expect($response[0])->toBe(200, responseDiagnostics($process, $response));
    stopServe($process);
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

    $response = mcpRequest("http://127.0.0.1:{$healthPort}/health");
    [, $body] = $response;

    expect($response[0])->toBe(200, responseDiagnostics($process, $response));
    stopServe($process);

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
    mcpRoundTrip($process, $port);
    stopServe($process);

    expect($stderr)->toContain('[martis:mcp-serve] WARNING: bound to 0.0.0.0 without MARTIS_MCP_HTTP_TOKEN. Anyone reaching this port can call the docs API.');

    // No-warn flag silences it.
    $port2 = pickPort();
    $silent = spawnServe(['--transport=http', '--host=0.0.0.0', "--port={$port2}", '--no-warn-on-public']);
    waitForPort($silent, '0.0.0.0', $port2);
    waitForServeReady($silent);
    $silentStderr = $silent->getIncrementalErrorOutput();
    mcpRoundTrip($silent, $port2);
    stopServe($silent);

    expect($silentStderr)->not->toContain('WARNING: bound to 0.0.0.0');
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
    mcpRoundTrip($process, $port);
    stopServe($process);

    // The MCP docs API warning must NOT fire (token is set).
    expect($stderr)->not->toContain('WARNING: bound to 0.0.0.0 without MARTIS_MCP_HTTP_TOKEN');
    // The health endpoint warning MUST fire.
    expect($stderr)->toContain('[martis:mcp-serve] WARNING: /health endpoint is bound to 0.0.0.0 without authentication.');
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

    // The responses reach stdout, one JSON-RPC message per line, and the
    // server exits cleanly once its input is done.
    expect($process->getExitCode())->toBe(0, describeProcess($process));
    $responses = array_map(fn (string $line) => json_decode($line, true), array_values(array_filter(explode("\n", $process->getOutput()))));
    $toolsList = collect($responses)->firstWhere('id', 2);
    expect($toolsList, describeProcess($process))->not->toBeNull()
        ->and(array_column($toolsList['result']['tools'] ?? [], 'name'))->toBe(['martis_doc_list', 'martis_doc_read', 'martis_doc_search']);
});

it('exits cleanly on SIGTERM in http mode', function () {
    $port = pickPort();
    $process = spawnServe(['--transport=http', "--port={$port}", '--no-warn-on-public']);

    // The socket, the "is up and listening" marker and an HTTP round trip:
    // the marker is logged before the loop runs, so only an answer proves
    // the loop (and its signal handlers) run when the SIGTERM is sent.
    waitForPort($process, '127.0.0.1', $port);
    waitForServeReady($process);
    mcpRoundTrip($process, $port);

    $pid = $process->getPid();
    expect($pid)->toBeInt();
    $process->signal(SIGTERM);

    // The loop drains and exits; the afterEach kills it if it does not,
    // so a failure here never leaks the subprocess into later tests.
    waitUntil($process, fn (): bool => ! $process->isRunning(), 'the server did not exit after SIGTERM', untilExit: true);
    expect($process->getExitCode())->toBe(0, 'the server exited with an error after SIGTERM: '.describeProcess($process));
});

it('exits with an error, instead of hanging, when the port is already in use', function () {
    $port = pickPort();
    $occupant = stream_socket_server("tcp://127.0.0.1:{$port}");
    try {
        $process = spawnServe(['--transport=http', "--port={$port}", '--no-warn-on-public']);

        // Before the fix the loop ReactPHP runs at shutdown waited on the
        // signal listeners forever.
        waitUntil($process, fn (): bool => ! $process->isRunning(), 'the server did not exit after failing to bind', untilExit: true);

        expect($process->getExitCode())->toBe(1, describeProcess($process))
            ->and($process->getErrorOutput())->toContain('[martis:mcp-serve] critical:')->toContain('Address already in use');
    } finally {
        fclose($occupant);
    }
});

it('refuses a MARTIS_TEST_PROCESS_TIMEOUT that is not a positive number, naming it', function (string $value) {
    $previous = getenv('MARTIS_TEST_PROCESS_TIMEOUT');
    putenv("MARTIS_TEST_PROCESS_TIMEOUT={$value}");
    try {
        expect(fn () => mcpBudget())->toThrow(InvalidArgumentException::class, "MARTIS_TEST_PROCESS_TIMEOUT must be a positive number of seconds, got \"{$value}\".");
    } finally {
        putenv($previous === false ? 'MARTIS_TEST_PROCESS_TIMEOUT' : "MARTIS_TEST_PROCESS_TIMEOUT={$previous}");
    }
})->with(['abc', '0', '-5']);

it('stops on a signal handled before its loop runs', function () {
    // The server logs "is up and listening" before it runs the loop, and
    // `run()` clears a stop requested before it. From outside the window is
    // too short to hit, so the handler is driven in this process: a signal
    // delivered between registration and `run()` must still end the loop.
    $previous = Loop::get();
    $loop = new StreamSelectLoop;
    Loop::set($loop);
    try {
        $register = new ReflectionMethod(McpServeCommand::class, 'registerSignalHandlers');
        $register->invoke(app(McpServeCommand::class), null);
        posix_kill(getmypid(), SIGTERM);
        // What the signal listeners keep alive, plus a fuse if the stop was lost.
        $keepAlive = $loop->addPeriodicTimer(0.05, static fn () => null);
        $fuseBlown = false;
        $loop->addTimer(3.0, function () use ($loop, &$fuseBlown): void {
            $fuseBlown = true;
            $loop->stop();
        });

        $loop->run();

        expect($fuseBlown)->toBeFalse('the loop kept running after a SIGTERM handled before run()');
        $loop->cancelTimer($keepAlive);
    } finally {
        // The listeners stay on the discarded loop: give the signals back to
        // PHP so a Ctrl+C still ends the test run.
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
        Loop::set($previous);
    }
})->skip(! function_exists('posix_kill') || ! function_exists('pcntl_signal'), 'needs ext-posix and ext-pcntl');
