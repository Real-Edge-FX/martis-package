<?php

declare(strict_types=1);

namespace Martis\Mcp\Transport;

use PhpMcp\Schema\JsonRpc\Message;
use PhpMcp\Server\Exception\TransportException;
use PhpMcp\Server\Transports\StdioServerTransport;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

/**
 * The stdio transport, writing each response to STDOUT before it returns.
 *
 * The bundled transport buffers its writes in a ReactPHP stream and, when
 * STDIN closes (a client that sends its requests and closes the pipe),
 * closes that stream at once: `close()` drops what is still buffered, so the
 * responses were logged as sent and never written, and it closed the STDOUT
 * resource itself, so the console's shutdown failed ("StreamOutput needs a
 * stream", exit 255). Here a response is written, all of it, before the
 * next request is read, and closing leaves STDOUT open.
 */
final class FlushingStdioServerTransport extends StdioServerTransport
{
    /** @var resource */
    private $output;

    /**
     * @param  resource  $inputStreamResource
     * @param  resource  $outputStreamResource
     */
    public function __construct($inputStreamResource = STDIN, $outputStreamResource = STDOUT)
    {
        parent::__construct($inputStreamResource, $outputStreamResource);
        $this->output = $outputStreamResource;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return PromiseInterface<void>
     */
    public function sendMessage(Message $message, string $sessionId, array $context = []): PromiseInterface
    {
        if ($this->closing) {
            return reject(new TransportException('Stdio transport is closed.'));
        }

        $payload = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        // The parent's stream made STDOUT non-blocking: write until all of
        // it is out, waiting for the pipe when it is full.
        while ($payload !== '') {
            $written = @fwrite($this->output, $payload);
            if ($written === false) {
                return reject(new TransportException('Could not write to STDOUT.'));
            }
            $payload = (string) substr($payload, $written);
            if ($payload !== '') {
                $read = null;
                $write = [$this->output];
                $except = null;
                @stream_select($read, $write, $except, 1);
            }
        }
        @fflush($this->output);

        /** @var Deferred<void> $done */
        $done = new Deferred;
        $done->resolve(null);

        return $done->promise();
    }

    public function close(): void
    {
        // Never close the STDOUT resource: nothing is buffered in the
        // stream, and the console still writes to it on its way out.
        $this->stdout = null;

        parent::close();
    }
}
