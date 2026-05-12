<?php

declare(strict_types=1);

namespace Vask\Laravel;

use Closure;
use Illuminate\Support\Facades\Route;
use Vask\Laravel\Http\Controllers\VaskWebhookController;

class Vask
{
    public const EVENT_CHANNEL_OCCUPIED = 'channel_occupied';

    public const EVENT_CHANNEL_VACATED = 'channel_vacated';

    public const EVENT_MEMBER_ADDED = 'member_added';

    public const EVENT_MEMBER_REMOVED = 'member_removed';

    public const EVENT_CLIENT_EVENT = 'client_event';

    public const DEFAULT_WEBHOOK_PATH = '/webhooks/vask';

    public const ROUTE_NAME = 'vask.webhook';

    /** @var array<string, Closure|array{0: class-string, 1: string}|class-string> */
    protected array $handlers = [];

    protected string $webhookPath = self::DEFAULT_WEBHOOK_PATH;

    protected bool $autoRegisterRoute = true;

    protected bool $routeRegistered = false;

    /**
     * Register a handler invoked when a channel becomes occupied.
     *
     * Accepts an invokable class name, a [class, method] array, or a Closure.
     * The handler receives a ChannelOccupiedPayload as its only argument.
     *
     * @param  Closure|array{0: class-string, 1: string}|class-string  $handler
     */
    public function onChannelOccupied(Closure|array|string $handler): static
    {
        return $this->on(self::EVENT_CHANNEL_OCCUPIED, $handler);
    }

    /**
     * @param  Closure|array{0: class-string, 1: string}|class-string  $handler
     */
    public function onChannelVacated(Closure|array|string $handler): static
    {
        return $this->on(self::EVENT_CHANNEL_VACATED, $handler);
    }

    /**
     * @param  Closure|array{0: class-string, 1: string}|class-string  $handler
     */
    public function onMemberAdded(Closure|array|string $handler): static
    {
        return $this->on(self::EVENT_MEMBER_ADDED, $handler);
    }

    /**
     * @param  Closure|array{0: class-string, 1: string}|class-string  $handler
     */
    public function onMemberRemoved(Closure|array|string $handler): static
    {
        return $this->on(self::EVENT_MEMBER_REMOVED, $handler);
    }

    /**
     * @param  Closure|array{0: class-string, 1: string}|class-string  $handler
     */
    public function onClientEvent(Closure|array|string $handler): static
    {
        return $this->on(self::EVENT_CLIENT_EVENT, $handler);
    }

    /**
     * @param  Closure|array{0: class-string, 1: string}|class-string  $handler
     */
    public function on(string $event, Closure|array|string $handler): static
    {
        $this->handlers[$event] = $handler;

        return $this;
    }

    /**
     * @return Closure|array{0: class-string, 1: string}|class-string|null
     */
    public function handlerFor(string $event): Closure|array|string|null
    {
        return $this->handlers[$event] ?? null;
    }

    /**
     * @return array<string, Closure|array{0: class-string, 1: string}|class-string>
     */
    public function handlers(): array
    {
        return $this->handlers;
    }

    public function flushHandlers(): void
    {
        $this->handlers = [];
        $this->routeRegistered = false;
    }

    /**
     * Override the path where the webhook route will be auto-registered.
     * Pass nothing to read the current path.
     *
     * @return ($path is null ? string : static)
     */
    public function webhookPath(?string $path = null): string|static
    {
        if ($path === null) {
            return $this->webhookPath;
        }

        $this->webhookPath = '/'.ltrim($path, '/');

        return $this;
    }

    /**
     * Disable automatic webhook route registration. Use this if you want to
     * register the route yourself (e.g. to add custom middleware).
     */
    public function disableAutoWebhookRoute(): static
    {
        $this->autoRegisterRoute = false;

        return $this;
    }

    public function enableAutoWebhookRoute(): static
    {
        $this->autoRegisterRoute = true;

        return $this;
    }

    public function shouldAutoRegisterWebhookRoute(): bool
    {
        return $this->autoRegisterRoute && $this->handlers !== [];
    }

    /**
     * Register the webhook route if auto-registration is enabled and at least
     * one handler has been registered. Idempotent.
     */
    public function registerWebhookRouteIfNeeded(): bool
    {
        if (! $this->shouldAutoRegisterWebhookRoute() || $this->routeRegistered) {
            return false;
        }

        Route::post($this->webhookPath, VaskWebhookController::class)
            ->name(self::ROUTE_NAME);

        // Laravel's named-routes lookup only auto-refreshes when the router
        // actually dispatches. Routes registered via Route::post()->name(...)
        // outside the routing files (like ours, in a service provider) aren't
        // findable via Route::has()/getByName() until the lookup is rebuilt.
        Route::getRoutes()->refreshNameLookups();

        $this->routeRegistered = true;

        return true;
    }
}
