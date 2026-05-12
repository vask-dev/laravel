<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vask\Laravel\Facades\Vask as VaskFacade;
use Vask\Laravel\Vask;

beforeEach(function (): void {
    // Each test starts with a fresh state on the singleton. The route registry
    // is per-test (Orchestra Testbench boots a fresh app per test).
    $vask = resolve(Vask::class);
    $vask->flushHandlers();
    $vask->enableAutoWebhookRoute();
    $vask->webhookPath(Vask::DEFAULT_WEBHOOK_PATH);
});

it('does not auto-register a route when no handlers are registered', function (): void {
    expect(VaskFacade::shouldAutoRegisterWebhookRoute())->toBeFalse();

    resolve(Vask::class)->registerWebhookRouteIfNeeded();

    expect(Route::has(Vask::ROUTE_NAME))->toBeFalse();
});

it('auto-registers the route after a handler is registered', function (): void {
    VaskFacade::onChannelOccupied(fn () => null);

    $registered = resolve(Vask::class)->registerWebhookRouteIfNeeded();

    expect($registered)->toBeTrue();
    expect(Route::has(Vask::ROUTE_NAME))->toBeTrue();

    $route = Route::getRoutes()->getByName(Vask::ROUTE_NAME);
    expect($route->uri())->toBe(ltrim(Vask::DEFAULT_WEBHOOK_PATH, '/'));
    expect($route->methods())->toContain('POST');
});

it('respects a custom webhook path', function (): void {
    VaskFacade::webhookPath('/custom/vask-hook');
    VaskFacade::onMemberAdded(fn () => null);

    resolve(Vask::class)->registerWebhookRouteIfNeeded();

    $route = Route::getRoutes()->getByName(Vask::ROUTE_NAME);
    expect($route->uri())->toBe('custom/vask-hook');
});

it('normalises the webhook path to start with a slash', function (): void {
    VaskFacade::webhookPath('no-slash-prefix');

    expect(resolve(Vask::class)->webhookPath())->toBe('/no-slash-prefix');
});

it('does not auto-register when disableAutoWebhookRoute() has been called', function (): void {
    VaskFacade::onChannelOccupied(fn () => null);
    VaskFacade::disableAutoWebhookRoute();

    $registered = resolve(Vask::class)->registerWebhookRouteIfNeeded();

    expect($registered)->toBeFalse();
    expect(Route::has(Vask::ROUTE_NAME))->toBeFalse();
});

it('does not register the same route twice', function (): void {
    VaskFacade::onChannelOccupied(fn () => null);

    $first = resolve(Vask::class)->registerWebhookRouteIfNeeded();
    $second = resolve(Vask::class)->registerWebhookRouteIfNeeded();

    expect($first)->toBeTrue();
    expect($second)->toBeFalse();
});

it('exposes shouldAutoRegisterWebhookRoute() as the boot-time predicate', function (): void {
    expect(VaskFacade::shouldAutoRegisterWebhookRoute())->toBeFalse();

    VaskFacade::onClientEvent(fn () => null);
    expect(VaskFacade::shouldAutoRegisterWebhookRoute())->toBeTrue();

    VaskFacade::disableAutoWebhookRoute();
    expect(VaskFacade::shouldAutoRegisterWebhookRoute())->toBeFalse();
});
