<?php

use Ldiebold\Isolate\PortCheckers\BindPortChecker;

it('reports a bound port as in use and a free port as available', function () {
    $checker = new BindPortChecker;

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($listener)->not->toBeFalse();

    $name = stream_socket_get_name($listener, false);
    $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

    expect($checker->inUse($port))->toBeTrue();

    fclose($listener);

    expect($checker->inUse($port))->toBeFalse();
});
