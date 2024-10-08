<?php

namespace Ratchet\Server;
use Ratchet\MessageComponentInterface;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;

/**
 * Creates an open-ended socket to listen on a port for incoming connections.
 * Events are delegated through this to attached applications
 */
class IoServer
{
    public ?LoopInterface $loop;
    public MessageComponentInterface $app;
    public ServerInterface $socket;
    public ConnectionInterface $decor;

    public function __construct(MessageComponentInterface $app, ServerInterface $socket, LoopInterface $loop = null) {
        if (! str_contains(PHP_VERSION, "hiphop")) {
            gc_enable();
        }

        set_time_limit(0);
        ob_implicit_flush();

        $this->loop = $loop;
        $this->app = $app;
        $this->socket = $socket;

        $socket->on('connection', $this->handleConnect(...));
    }

    public function run(): void
    {
        if (null === $this->loop) {
            throw new \RuntimeException("A React Loop was not provided during instantiation");
        }

        // @codeCoverageIgnoreStart
        $this->loop->run();
        // @codeCoverageIgnoreEnd
    }

    public function handleConnect(ConnectionInterface $conn): void {
        $conn->decor = new IoConnection($conn);
        $conn->decor->resourceId = (int) $conn->stream;

        $uri = $conn->getRemoteAddress();
        $conn->decor->remoteAddress = trim(
            parse_url((! str_contains((string) $uri, '://') ? 'tcp://' : '') . $uri, PHP_URL_HOST),
            '[]'
        );

        $this->app->onOpen($conn->decor);

        $conn->on('data', function ($data) use ($conn): void {
            $this->handleData($data, $conn);
        });
        $conn->on('close', function () use ($conn): void {
            $this->handleEnd($conn);
        });
        $conn->on('error', function (\Exception $e) use ($conn): void {
            $this->handleError($e, $conn);
        });
    }

    /**
     * Data has been received from React
     *
     * @param string                            $data
     * @param ConnectionInterface $conn
     */
    public function handleData($data, $conn): void {
        try {
            $this->app->onMessage($conn->decor, $data);
        } catch (\Exception $e) {
            $this->handleError($e, $conn);
        }
    }

    /**
     * A connection has been closed by React
     *
     * @param ConnectionInterface $conn
     */
    public function handleEnd($conn): void {
        try {
            $this->app->onClose($conn->decor);
        } catch (\Exception $e) {
            $this->handleError($e, $conn);
        }

        unset($conn->decor);
    }

    /**
     * An error has occurred, let the listening application know
     *
     * @param ConnectionInterface $conn
     */
    public function handleError(\Exception $e, $conn): void {
        $this->app->onError($conn->decor, $e);
    }
}
