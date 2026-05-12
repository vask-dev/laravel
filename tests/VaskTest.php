<?php

declare(strict_types=1);

use Vask\Laravel\Facades\Vask as VaskFacade;
use Vask\Laravel\Vask;

beforeEach(function (): void {
    resolve(Vask::class)->flushHandlers();
});

it('registers a handler for each webhook event type', function (string $method, string $event): void {
    $handler = fn () => null;

    VaskFacade::{$method}($handler);

    expect(resolve(Vask::class)->handlerFor($event))->toBe($handler);
})->with([
    ['onChannelOccupied', Vask::EVENT_CHANNEL_OCCUPIED],
    ['onChannelVacated', Vask::EVENT_CHANNEL_VACATED],
    ['onMemberAdded', Vask::EVENT_MEMBER_ADDED],
    ['onMemberRemoved', Vask::EVENT_MEMBER_REMOVED],
    ['onClientEvent', Vask::EVENT_CLIENT_EVENT],
]);

it('accepts closures, [class, method] arrays, and class strings as handlers', function (): void {
    VaskFacade::onChannelOccupied(fn (): string => 'closure');
    VaskFacade::onMemberAdded([StubHandler::class, 'memberJoined']);
    VaskFacade::onClientEvent(StubHandler::class);

    expect(resolve(Vask::class)->handlerFor(Vask::EVENT_CHANNEL_OCCUPIED))->toBeInstanceOf(Closure::class);
    expect(resolve(Vask::class)->handlerFor(Vask::EVENT_MEMBER_ADDED))->toBe([StubHandler::class, 'memberJoined']);
    expect(resolve(Vask::class)->handlerFor(Vask::EVENT_CLIENT_EVENT))->toBe(StubHandler::class);
});

it('overwrites a previously registered handler for the same event', function (): void {
    $first = fn (): string => 'first';
    $second = fn (): string => 'second';

    VaskFacade::onChannelOccupied($first);
    VaskFacade::onChannelOccupied($second);

    expect(resolve(Vask::class)->handlerFor(Vask::EVENT_CHANNEL_OCCUPIED))->toBe($second);
});

it('returns null when no handler is registered', function (): void {
    expect(resolve(Vask::class)->handlerFor(Vask::EVENT_CHANNEL_OCCUPIED))->toBeNull();
});

it('is registered as a singleton so handlers survive across resolutions', function (): void {
    VaskFacade::onChannelOccupied(fn (): string => 'singleton');

    $resolved = resolve(Vask::class);

    expect($resolved->handlerFor(Vask::EVENT_CHANNEL_OCCUPIED))->not->toBeNull();
});

class StubHandler
{
    public function __invoke(object $payload): void {}

    public function memberJoined(object $payload): void {}
}
