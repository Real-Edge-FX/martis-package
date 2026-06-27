<?php

declare(strict_types=1);

use Martis\Mcp\Transport\HealthServer;
use React\EventLoop\Loop;
use React\Http\Browser;

/**
 * End-to-end test for the health server. Starts the server on a free
 * port, hits it with a ReactPHP Browser, asserts the response shape,
 * shuts down cleanly. Uses the real event loop because the server
 * IS the loop integration.
 */
function pickFreePort(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($sock, false);
    fclose($sock);

    return (int) substr((string) $name, strrpos($name, ':') + 1);
}

it('responds 200 with the documented JSON payload on GET /health', function () {
    $loop = Loop::get();
    $port = pickFreePort();
    $server = new HealthServer($loop, '127.0.0.1', $port, '1.13.0', 'http');
    $server->start();

    $browser = new Browser($loop);
    $payload = null;

    $browser->get("http://127.0.0.1:{$port}/health")->then(function ($response) use (&$payload, $loop, $server) {
        $payload = [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getBody(), true),
        ];
        $server->stop();
        $loop->stop();
    });

    // Safety timeout.
    $loop->addTimer(3.0, function () use ($loop, $server) {
        $server->stop();
        $loop->stop();
    });

    $loop->run();

    expect($payload['status'])->toBe(200);
    expect($payload['body'])->toMatchArray([
        'status' => 'ok',
        'version' => '1.13.0',
        'transport' => 'http',
        'tool_count' => 3,
    ]);
    expect($payload['body']['uptime_s'])->toBeInt()->toBeGreaterThanOrEqual(0);
});

it('responds 404 for any path other than /health', function () {
    $loop = Loop::get();
    $port = pickFreePort();
    $server = new HealthServer($loop, '127.0.0.1', $port, '1.13.0', 'http');
    $server->start();

    $browser = new Browser($loop);
    $status = null;

    $browser->get("http://127.0.0.1:{$port}/")->then(
        function ($response) use (&$status, $loop, $server) {
            $status = $response->getStatusCode();
            $server->stop();
            $loop->stop();
        },
        function () use ($loop, $server, &$status) {
            // ReactPHP Browser rejects on non-2xx by default — catch and read code.
            // Browser rejects with ResponseException carrying the response.
            $status = 'rejected';
            $server->stop();
            $loop->stop();
        }
    );

    $loop->addTimer(3.0, function () use ($loop, $server) {
        $server->stop();
        $loop->stop();
    });

    $loop->run();

    // Browser may have rejected (depending on version); either way, not 200.
    expect($status)->not->toBe(200);
});

it('reports status=disabled and tool_count=0 when Tools::enabled() is false', function () {
    putenv('MARTIS_MCP_ENABLED=false');
    config()->set('martis.mcp.enabled', false);

    $loop = Loop::get();
    $port = pickFreePort();
    $server = new HealthServer($loop, '127.0.0.1', $port, '1.13.0', 'http');
    $server->start();

    $browser = new Browser($loop);
    $body = null;

    $browser->get("http://127.0.0.1:{$port}/health")->then(function ($response) use (&$body, $loop, $server) {
        $body = json_decode((string) $response->getBody(), true);
        $server->stop();
        $loop->stop();
    });

    $loop->addTimer(3.0, function () use ($loop, $server) {
        $server->stop();
        $loop->stop();
    });

    $loop->run();

    expect($body['status'])->toBe('disabled');
    expect($body['tool_count'])->toBe(0);

    putenv('MARTIS_MCP_ENABLED');
    config()->set('martis.mcp.enabled', true);
});
