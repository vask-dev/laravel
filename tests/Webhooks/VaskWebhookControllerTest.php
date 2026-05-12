<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vask\Laravel\Facades\Vask as VaskFacade;
use Vask\Laravel\Http\Controllers\VaskWebhookController;
use Vask\Laravel\Vask;
use Vask\Laravel\Webhooks\Payloads\ChannelOccupiedPayload;
use Vask\Laravel\Webhooks\Payloads\ChannelVacatedPayload;
use Vask\Laravel\Webhooks\Payloads\ClientEventPayload;
use Vask\Laravel\Webhooks\Payloads\MemberAddedPayload;
use Vask\Laravel\Webhooks\Payloads\MemberRemovedPayload;

const TEST_KEY = 'test-app-key';
const TEST_SECRET = 'test-app-secret';

beforeEach(function (): void {
    config()->set('broadcasting.connections.pusher.key', TEST_KEY);
    config()->set('broadcasting.connections.pusher.secret', TEST_SECRET);

    resolve(Vask::class)->flushHandlers();

    // The package only registers the route after a handler exists. These tests
    // exercise the controller directly, so we force-register the route at the
    // canonical path bypassing the handler check.
    if (! Route::has(Vask::ROUTE_NAME)) {
        Route::post(Vask::DEFAULT_WEBHOOK_PATH, VaskWebhookController::class)
            ->name(Vask::ROUTE_NAME);
    }
});

function postWebhook(array $body, ?string $signature = null, ?string $key = TEST_KEY)
{
    $raw = json_encode($body);
    $signature ??= hash_hmac('sha256', $raw, TEST_SECRET);

    return test()->call(
        'POST',
        Vask::DEFAULT_WEBHOOK_PATH,
        [],
        [],
        [],
        [
            'HTTP_X-Pusher-Key' => $key,
            'HTTP_X-Pusher-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        $raw,
    );
}

it('returns 204 on a valid webhook', function (): void {
    $response = postWebhook([
        'time_ms' => 1700000000000,
        'events' => [],
    ]);

    $response->assertNoContent();
});

it('rejects requests with an invalid signature', function (): void {
    $response = postWebhook(['events' => []], signature: 'wrong-signature');

    $response->assertStatus(401);
});

it('rejects requests with an invalid app key', function (): void {
    $response = postWebhook(['events' => []], key: 'not-the-real-key');

    $response->assertStatus(401);
});

it('returns 500 when credentials are not configured', function (): void {
    config()->set('broadcasting.connections.pusher.key', '');
    config()->set('broadcasting.connections.pusher.secret', '');

    $response = postWebhook(['events' => []], signature: 'irrelevant', key: '');

    $response->assertStatus(500);
});

it('returns 400 when the body is not valid JSON', function (): void {
    $signature = hash_hmac('sha256', 'not-json', TEST_SECRET);

    $response = test()->call('POST', Vask::DEFAULT_WEBHOOK_PATH, [], [], [], [
        'HTTP_X-Pusher-Key' => TEST_KEY,
        'HTTP_X-Pusher-Signature' => $signature,
    ], 'not-json');

    $response->assertStatus(400);
});

it('dispatches channel_occupied to the registered handler with a typed payload', function (): void {
    $captured = null;
    VaskFacade::onChannelOccupied(function (ChannelOccupiedPayload $payload) use (&$captured): void {
        $captured = $payload;
    });

    postWebhook([
        'time_ms' => 1700000000123,
        'events' => [['name' => 'channel_occupied', 'channel' => 'orders.42']],
    ])->assertNoContent();

    expect($captured)->toBeInstanceOf(ChannelOccupiedPayload::class);
    expect($captured->channel)->toBe('orders.42');
    expect($captured->timeMs)->toBe(1700000000123);
});

it('dispatches channel_vacated', function (): void {
    $captured = null;
    VaskFacade::onChannelVacated(function (ChannelVacatedPayload $payload) use (&$captured): void {
        $captured = $payload;
    });

    postWebhook([
        'time_ms' => 1700000000000,
        'events' => [['name' => 'channel_vacated', 'channel' => 'orders.42']],
    ])->assertNoContent();

    expect($captured)->toBeInstanceOf(ChannelVacatedPayload::class);
    expect($captured->channel)->toBe('orders.42');
});

it('dispatches member_added with user_id', function (): void {
    $captured = null;
    VaskFacade::onMemberAdded(function (MemberAddedPayload $payload) use (&$captured): void {
        $captured = $payload;
    });

    postWebhook([
        'time_ms' => 1700000000000,
        'events' => [[
            'name' => 'member_added',
            'channel' => 'presence-chat.7',
            'user_id' => 'user-99',
        ]],
    ])->assertNoContent();

    expect($captured)->toBeInstanceOf(MemberAddedPayload::class);
    expect($captured->channel)->toBe('presence-chat.7');
    expect($captured->userId)->toBe('user-99');
});

it('dispatches member_removed with user_id', function (): void {
    $captured = null;
    VaskFacade::onMemberRemoved(function (MemberRemovedPayload $payload) use (&$captured): void {
        $captured = $payload;
    });

    postWebhook([
        'time_ms' => 1700000000000,
        'events' => [[
            'name' => 'member_removed',
            'channel' => 'presence-chat.7',
            'user_id' => 'user-99',
        ]],
    ])->assertNoContent();

    expect($captured)->toBeInstanceOf(MemberRemovedPayload::class);
    expect($captured->userId)->toBe('user-99');
});

it('dispatches client_event with full metadata', function (): void {
    $captured = null;
    VaskFacade::onClientEvent(function (ClientEventPayload $payload) use (&$captured): void {
        $captured = $payload;
    });

    postWebhook([
        'time_ms' => 1700000000000,
        'events' => [[
            'name' => 'client_event',
            'channel' => 'private-orders.42',
            'event' => 'client-typing',
            'data' => '{"who":"alice"}',
            'socket_id' => '12345.67890',
            'user_id' => 'user-99',
        ]],
    ])->assertNoContent();

    expect($captured)->toBeInstanceOf(ClientEventPayload::class);
    expect($captured->channel)->toBe('private-orders.42');
    expect($captured->event)->toBe('client-typing');
    expect($captured->data)->toBe('{"who":"alice"}');
    expect($captured->socketId)->toBe('12345.67890');
    expect($captured->userId)->toBe('user-99');
});

it('silently skips events with no registered handler', function (): void {
    // No handler registered.
    postWebhook([
        'events' => [['name' => 'channel_occupied', 'channel' => 'orders.42']],
    ])->assertNoContent();
});

it('silently skips unknown event names', function (): void {
    $called = false;
    VaskFacade::onChannelOccupied(function () use (&$called): void {
        $called = true;
    });

    postWebhook([
        'events' => [['name' => 'totally_made_up', 'channel' => 'x']],
    ])->assertNoContent();

    expect($called)->toBeFalse();
});

it('invokes class-string invokable handlers via the container', function (): void {
    VaskFacade::onChannelOccupied(CapturingHandler::class);
    CapturingHandler::$captured = null;

    postWebhook([
        'events' => [['name' => 'channel_occupied', 'channel' => 'orders.42']],
    ])->assertNoContent();

    expect(CapturingHandler::$captured)->toBeInstanceOf(ChannelOccupiedPayload::class);
    expect(CapturingHandler::$captured->channel)->toBe('orders.42');
});

it('invokes [class, method] handlers via the container', function (): void {
    VaskFacade::onChannelOccupied([CapturingHandler::class, 'capture']);
    CapturingHandler::$captured = null;

    postWebhook([
        'events' => [['name' => 'channel_occupied', 'channel' => 'orders.99']],
    ])->assertNoContent();

    expect(CapturingHandler::$captured->channel)->toBe('orders.99');
});

it('dispatches every event in a multi-event payload in order', function (): void {
    $captured = [];
    VaskFacade::onChannelOccupied(function (ChannelOccupiedPayload $p) use (&$captured): void {
        $captured[] = ['occupied', $p->channel];
    });
    VaskFacade::onChannelVacated(function (ChannelVacatedPayload $p) use (&$captured): void {
        $captured[] = ['vacated', $p->channel];
    });

    postWebhook([
        'time_ms' => 1700000000000,
        'events' => [
            ['name' => 'channel_occupied', 'channel' => 'a'],
            ['name' => 'channel_vacated', 'channel' => 'b'],
            ['name' => 'channel_occupied', 'channel' => 'c'],
        ],
    ])->assertNoContent();

    expect($captured)->toBe([
        ['occupied', 'a'],
        ['vacated', 'b'],
        ['occupied', 'c'],
    ]);
});

class CapturingHandler
{
    public static ?object $captured = null;

    public function __invoke(object $payload): void
    {
        self::$captured = $payload;
    }

    public function capture(object $payload): void
    {
        self::$captured = $payload;
    }
}
