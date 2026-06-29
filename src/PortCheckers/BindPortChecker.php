<?php

namespace Ldiebold\Isolate\PortCheckers;

use Ldiebold\Isolate\Contracts\PortChecker;

/**
 * Portable pure-PHP port probe: attempt to bind a TCP listener on loopback. If
 * the bind succeeds the port is free (a 0.0.0.0 listener would also block the
 * loopback bind, so this catches those too); if it fails the port is in use.
 */
class BindPortChecker implements PortChecker
{
    public function __construct(protected string $host = '127.0.0.1') {}

    public function inUse(int $port): bool
    {
        $errno = 0;
        $error = '';

        $socket = @stream_socket_server(
            "tcp://{$this->host}:{$port}",
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if ($socket === false) {
            return true;
        }

        fclose($socket);

        return false;
    }
}
