<?php

use Ldiebold\Isolate\Support\RestrictedPorts;

it('reports membership in the restricted set', function () {
    $ports = new RestrictedPorts([22, 6000, 6697]);

    expect($ports->isRestricted(22))->toBeTrue()
        ->and($ports->isRestricted(6000))->toBeTrue()
        ->and($ports->isRestricted(8000))->toBeFalse();
});

it('treats an empty set as nothing restricted', function () {
    $ports = new RestrictedPorts([]);

    expect($ports->isRestricted(22))->toBeFalse();
});
