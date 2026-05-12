<?php

declare(strict_types=1);

use Vask\Laravel\Support\UserAgent;
use Vask\Laravel\VaskServiceProvider;

it('sets the User-Agent on the Pusher Guzzle client_options at boot time', function (): void {
    // The service provider already booted with app.name='Laravel' / env='testing',
    // so the slot is already filled. Clear it to exercise the fresh-boot path
    // with a meaningful app.name in place.
    config()->set('app.name', 'Vask Web');
    app()['env'] = 'local';
    config()->set('broadcasting.connections.pusher.client_options.headers.User-Agent');

    VaskServiceProvider::applyUserAgentToPusherBroadcaster();

    $ua = config('broadcasting.connections.pusher.client_options.headers.User-Agent');

    expect($ua)
        ->toBeString()
        ->toStartWith('vask-web/local vask-laravel');
    expect($ua)->toBe(UserAgent::build());
});

it('writes the UA on a fresh provider boot when nothing has been set yet', function (): void {
    // Verify the boot-time write actually happened — the provider runs
    // before any test code, so the slot should already be populated.
    $ua = config('broadcasting.connections.pusher.client_options.headers.User-Agent');

    expect($ua)
        ->toBeString()
        ->toStartWith('laravel/testing vask-laravel');
});

it('does not clobber a User-Agent the host app already set', function (): void {
    config()->set(
        'broadcasting.connections.pusher.client_options.headers.User-Agent',
        'my-custom-app/v9 some-marker',
    );

    VaskServiceProvider::applyUserAgentToPusherBroadcaster();

    expect(config('broadcasting.connections.pusher.client_options.headers.User-Agent'))
        ->toBe('my-custom-app/v9 some-marker');
});
