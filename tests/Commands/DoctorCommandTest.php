<?php

declare(strict_types=1);

use Pusher\Pusher;

beforeEach(function (): void {
    // Default to a fully valid configuration; individual tests break specific pieces.
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher.key', 'k');
    config()->set('broadcasting.connections.pusher.secret', 's');
    config()->set('broadcasting.connections.pusher.app_id', 'a');
    config()->set('broadcasting.connections.pusher.options.host', 'wss.vask.dev');
    config()->set('broadcasting.connections.pusher.options.port', 443);
    config()->set('broadcasting.connections.pusher.options.scheme', 'https');

    // Pretend broadcasting is wired up in the testbench app — most checks
    // depend on this. Individual tests can remove these to test the failure
    // path.
    $basePath = app()->basePath();
    @mkdir($basePath.'/routes', 0777, true);
    @mkdir($basePath.'/bootstrap', 0777, true);
    file_put_contents($basePath.'/routes/channels.php', "<?php\n");
    file_put_contents($basePath.'/bootstrap/app.php', "<?php\n->withRouting(channels: __DIR__.'/../routes/channels.php')\n");
});

afterEach(function (): void {
    $basePath = app()->basePath();
    @unlink($basePath.'/routes/channels.php');
    @unlink($basePath.'/bootstrap/app.php');
});

it('passes when configuration is correct', function (): void {
    test()->artisan('vask:doctor', ['--no-broadcast' => true, '--no-ping' => true])
        ->expectsOutputToContain("Broadcast driver is 'pusher'")
        ->expectsOutputToContain("Host is 'wss.vask.dev'")
        ->expectsOutputToContain('Port is 443')
        ->expectsOutputToContain('Scheme is https')
        ->expectsOutputToContain('Credentials are set')
        ->expectsOutputToContain('All checks passed.')
        ->assertSuccessful();
});

it('fails when broadcast driver is not pusher', function (): void {
    config()->set('broadcasting.default', 'log');

    test()->artisan('vask:doctor', ['--no-broadcast' => true, '--no-ping' => true])
        ->expectsOutputToContain("Broadcast driver is 'log', expected 'pusher'")
        ->assertFailed();
});

it('fails when host is not wss.vask.dev', function (): void {
    config()->set('broadcasting.connections.pusher.options.host', 'api-mt1.pusher.com');

    test()->artisan('vask:doctor', ['--no-broadcast' => true, '--no-ping' => true])
        ->expectsOutputToContain("Host is 'api-mt1.pusher.com'")
        ->assertFailed();
});

it('fails when port is not 443', function (): void {
    config()->set('broadcasting.connections.pusher.options.port', 80);

    test()->artisan('vask:doctor', ['--no-broadcast' => true, '--no-ping' => true])
        ->expectsOutputToContain("Port is '80'")
        ->assertFailed();
});

it('fails when scheme is not https', function (): void {
    config()->set('broadcasting.connections.pusher.options.scheme', 'http');

    test()->artisan('vask:doctor', ['--no-broadcast' => true, '--no-ping' => true])
        ->expectsOutputToContain("Scheme is 'http'")
        ->assertFailed();
});

it('fails when credentials are missing', function (): void {
    config()->set('broadcasting.connections.pusher.key', '');
    config()->set('broadcasting.connections.pusher.secret', '');

    test()->artisan('vask:doctor', ['--no-broadcast' => true, '--no-ping' => true])
        ->expectsOutputToContain("Missing credential 'key'")
        ->expectsOutputToContain("Missing credential 'secret'")
        ->assertFailed();
});

it('checks the pusher SDK is installed', function (): void {
    expect(class_exists(Pusher::class))->toBeTrue();
});

it('reports broadcasting as wired when the files are in place', function (): void {
    test()->artisan('vask:doctor', ['--no-broadcast' => true, '--no-ping' => true])
        ->expectsOutputToContain('Laravel broadcasting is wired')
        ->assertSuccessful();
});

it('fails when routes/channels.php is missing', function (): void {
    @unlink(app()->basePath().'/routes/channels.php');

    test()->artisan('vask:doctor', ['--no-broadcast' => true, '--no-ping' => true])
        ->expectsOutputToContain('Laravel broadcasting is not wired up')
        ->assertFailed();
});
