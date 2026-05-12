<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Vask\Laravel\Broadcasting\VaskDemoEvent;
use Vask\Laravel\VaskServiceProvider;

beforeEach(function (): void {
    config()->set('broadcasting.connections.pusher.key', 'test-key');
    config()->set('broadcasting.connections.pusher.secret', 'test-secret');
    config()->set('broadcasting.connections.pusher.options.host', 'wss.vask.dev');
    config()->set('broadcasting.connections.pusher.options.port', 443);
    config()->set('broadcasting.connections.pusher.options.scheme', 'https');
    config()->set('broadcasting.connections.pusher.options.cluster', 'mt1');
});

it('does not auto-register the demo route outside the local environment', function (): void {
    // Orchestra Testbench defaults to env=testing, so the boot-time gate
    // should keep the routes off the table.
    expect(app()->environment('local'))->toBeFalse();
    expect(Route::has(VaskServiceProvider::DEMO_ROUTE_NAME))->toBeFalse();
    expect(Route::has(VaskServiceProvider::DEMO_BROADCAST_ROUTE_NAME))->toBeFalse();
});

it('renders the demo view with the Pusher config from broadcasting.* config keys', function (): void {
    VaskServiceProvider::registerDemoRoutes();

    $response = $this->get(VaskServiceProvider::DEMO_PATH);

    $response->assertOk();
    $response->assertSee('test-key', false);
    $response->assertSee('wss.vask.dev', false);
    $response->assertSee(VaskDemoEvent::CHANNEL, false);
});

it('shows a "not configured" banner when the Pusher key is missing', function (): void {
    config()->set('broadcasting.connections.pusher.key', '');

    VaskServiceProvider::registerDemoRoutes();

    $response = $this->get(VaskServiceProvider::DEMO_PATH);

    $response->assertOk();
    $response->assertSee("isn't configured yet", false);
});

it('dispatches a VaskDemoEvent on POST to the broadcast endpoint', function (): void {
    Event::fake([VaskDemoEvent::class]);
    VaskServiceProvider::registerDemoRoutes();

    $response = $this->postJson(VaskServiceProvider::DEMO_PATH.'/broadcast', [
        'emoji' => '🎉',
        'x' => 0.42,
        'senderId' => 's-abc123',
        'sentAt' => 1234.5,
    ]);

    $response->assertNoContent();

    Event::assertDispatched(VaskDemoEvent::class, fn (VaskDemoEvent $event): bool => $event->emoji === '🎉'
        && $event->senderId === 's-abc123'
        && abs($event->sentAt - 1234.5) < 0.001
        && abs($event->x - 0.42) < 0.001
        && $event->id !== '');
});

it('validates the POST payload', function (): void {
    VaskServiceProvider::registerDemoRoutes();

    $response = $this->postJson(VaskServiceProvider::DEMO_PATH.'/broadcast', [
        'emoji' => '🎉',
        // missing x, senderId, sentAt
    ]);

    $response->assertStatus(422);
});

it('treats VASK_NO_DEMO=true as a request to disable the demo route', function (): void {
    putenv('VASK_NO_DEMO=true');

    try {
        expect(VaskServiceProvider::demoDisabledByEnv())->toBeTrue();
    } finally {
        putenv('VASK_NO_DEMO');
    }
});

it('treats VASK_NO_DEMO=1 as a request to disable the demo route', function (): void {
    putenv('VASK_NO_DEMO=1');

    try {
        expect(VaskServiceProvider::demoDisabledByEnv())->toBeTrue();
    } finally {
        putenv('VASK_NO_DEMO');
    }
});

it('treats an unset VASK_NO_DEMO as "demo enabled"', function (): void {
    putenv('VASK_NO_DEMO');

    expect(VaskServiceProvider::demoDisabledByEnv())->toBeFalse();
});

it('treats VASK_NO_DEMO=false as "demo enabled"', function (): void {
    putenv('VASK_NO_DEMO=false');

    try {
        expect(VaskServiceProvider::demoDisabledByEnv())->toBeFalse();
    } finally {
        putenv('VASK_NO_DEMO');
    }
});

it('signs Pusher channel auth requests for the demo channel', function (): void {
    VaskServiceProvider::registerDemoRoutes();

    $response = $this->postJson(VaskServiceProvider::DEMO_PATH.'/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => VaskDemoEvent::CHANNEL,
    ]);

    $response->assertOk();

    $expectedSig = hash_hmac('sha256', '12345.67890:'.VaskDemoEvent::CHANNEL, 'test-secret');
    $response->assertExactJson(['auth' => 'test-key:'.$expectedSig]);
});

it('refuses to sign auth requests for channels other than the demo channel', function (): void {
    VaskServiceProvider::registerDemoRoutes();

    $response = $this->postJson(VaskServiceProvider::DEMO_PATH.'/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => 'private-some-other-channel',
    ]);

    $response->assertForbidden();
});

it('rejects malformed socket ids', function (): void {
    VaskServiceProvider::registerDemoRoutes();

    $response = $this->postJson(VaskServiceProvider::DEMO_PATH.'/auth', [
        'socket_id' => 'not-a-socket-id; rm -rf',
        'channel_name' => VaskDemoEvent::CHANNEL,
    ]);

    $response->assertForbidden();
});

it('rejects empty socket_id on auth', function (): void {
    VaskServiceProvider::registerDemoRoutes();

    $response = $this->postJson(VaskServiceProvider::DEMO_PATH.'/auth', [
        'socket_id' => '',
        'channel_name' => VaskDemoEvent::CHANNEL,
    ]);

    $response->assertForbidden();
});

it('returns 500 from the auth route when Vask credentials are not configured', function (): void {
    config()->set('broadcasting.connections.pusher.key', '');
    config()->set('broadcasting.connections.pusher.secret', '');

    VaskServiceProvider::registerDemoRoutes();

    $response = $this->postJson(VaskServiceProvider::DEMO_PATH.'/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => VaskDemoEvent::CHANNEL,
    ]);

    $response->assertStatus(500);
});

it('uses the private channel prefix so client events are protocol-allowed', function (): void {
    expect(VaskDemoEvent::CHANNEL)->toStartWith('private-');
});
