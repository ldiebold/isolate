<?php

beforeEach(function () {
    config()->set('app.url', 'http://localhost:8000');
    config()->set('isolate.max_instances', 50);
    config()->set('isolate.resources', [
        ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'browser_facing' => true, 'active_when' => 'always'],
        ['type' => 'derived', 'env' => 'APP_URL', 'rewrite_port_of' => 'APP_URL', 'port_from' => 'SERVER_PORT', 'active_when' => 'always'],
    ]);
});

it('shows the resolved values for the current instance', function () {
    $this->artisan('isolate:status')
        ->expectsOutputToContain('SERVER_PORT')
        ->assertSuccessful();
});

it('lists candidate numbers with their status', function () {
    $this->artisan('isolate:list', ['--limit' => '3'])
        ->expectsOutputToContain('free')
        ->assertSuccessful();
});

it('marks numbers whose browser-facing port is restricted', function () {
    config()->set('isolate.restricted_ports', [8001]);

    $this->artisan('isolate:list', ['--limit' => '3'])
        ->expectsOutputToContain('restricted')
        ->assertSuccessful();
});
