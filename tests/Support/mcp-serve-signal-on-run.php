<?php

declare(strict_types=1);

/*
 * Prepended to a `martis:mcp-serve` that McpServeCommandTransportTest
 * spawns (`php -d auto_prepend_file=...`): the server's event loop sends
 * its own process a SIGTERM as it starts to run, and is otherwise the loop
 * ReactPHP gives it.
 *
 * The server logs "is up and listening", and the stdio transport opens its
 * session, before the loop runs, so a SIGTERM sent right after that line
 * can be handled before `run()`, which then clears the stop. Whether one
 * sent from outside lands there depends on how fast the machine runs that
 * stretch; this lands it there on every run.
 */

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

Loop::set(new class(Loop::get()) implements LoopInterface
{
    public function __construct(private readonly LoopInterface $loop) {}

    public function run(): void
    {
        // With async signals (ReactPHP turns them on), the handlers run
        // as this call returns, before the loop below starts.
        posix_kill(posix_getpid(), SIGTERM);

        $this->loop->run();
    }

    public function stop(): void
    {
        $this->loop->stop();
    }

    public function addReadStream($stream, $listener): void
    {
        $this->loop->addReadStream($stream, $listener);
    }

    public function addWriteStream($stream, $listener): void
    {
        $this->loop->addWriteStream($stream, $listener);
    }

    public function removeReadStream($stream): void
    {
        $this->loop->removeReadStream($stream);
    }

    public function removeWriteStream($stream): void
    {
        $this->loop->removeWriteStream($stream);
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        return $this->loop->addTimer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        return $this->loop->addPeriodicTimer($interval, $callback);
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->loop->cancelTimer($timer);
    }

    public function futureTick($listener): void
    {
        $this->loop->futureTick($listener);
    }

    public function addSignal($signal, $listener): void
    {
        $this->loop->addSignal($signal, $listener);
    }

    public function removeSignal($signal, $listener): void
    {
        $this->loop->removeSignal($signal, $listener);
    }
});
