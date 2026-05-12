<?php

declare(strict_types=1);

namespace Vask\Laravel\Http\Controllers;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vask\Laravel\Vask;
use Vask\Laravel\Webhooks\Payloads\ChannelOccupiedPayload;
use Vask\Laravel\Webhooks\Payloads\ChannelVacatedPayload;
use Vask\Laravel\Webhooks\Payloads\ClientEventPayload;
use Vask\Laravel\Webhooks\Payloads\MemberAddedPayload;
use Vask\Laravel\Webhooks\Payloads\MemberRemovedPayload;

class VaskWebhookController
{
    public function __construct(
        protected Vask $vask,
        protected Container $container,
    ) {}

    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();

        $this->verifySignature($request, $rawBody);

        $payload = json_decode($rawBody, true);

        abort_if(! is_array($payload), 400, 'Invalid webhook body.');

        $timeMs = $payload['time_ms'] ?? null;
        if (! is_int($timeMs)) {
            $timeMs = 0;
        }

        $events = $payload['events'] ?? [];
        if (! is_array($events)) {
            return new Response('', 204);
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $this->dispatch($this->withStringKeys($event), $timeMs);
        }

        return new Response('', 204);
    }

    protected function verifySignature(Request $request, string $rawBody): void
    {
        $rawSecret = config()->get('broadcasting.connections.pusher.secret');
        $rawKey = config()->get('broadcasting.connections.pusher.key');

        $secret = is_string($rawSecret) ? $rawSecret : '';
        $expectedKey = is_string($rawKey) ? $rawKey : '';

        abort_if($secret === '' || $expectedKey === '', 500, 'Vask credentials are not configured. See `php artisan vask:doctor`.');

        // Laravel narrows `header($key, $default)` to `string` when $default
        // is a string (matches the single-value case). Multi-value headers
        // would return an array, which we don't expect for these.
        $providedKey = $request->header('X-Pusher-Key', '');
        $providedSignature = $request->header('X-Pusher-Signature', '');

        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);

        $keyMatches = hash_equals($expectedKey, $providedKey);
        $signatureMatches = hash_equals($expectedSignature, $providedSignature);

        abort_if(! $keyMatches || ! $signatureMatches, 401, 'Invalid webhook signature.');
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function dispatch(array $event, int $timeMs): void
    {
        $name = $this->stringField($event, 'name');

        $handler = $this->vask->handlerFor($name);
        if ($handler === null) {
            return;
        }

        $payload = $this->payloadFor($name, $event, $timeMs);
        if ($payload === null) {
            return;
        }

        $this->invoke($handler, $payload);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function payloadFor(string $name, array $event, int $timeMs): ?object
    {
        $channel = $this->stringField($event, 'channel');

        return match ($name) {
            Vask::EVENT_CHANNEL_OCCUPIED => new ChannelOccupiedPayload(
                channel: $channel,
                timeMs: $timeMs,
            ),
            Vask::EVENT_CHANNEL_VACATED => new ChannelVacatedPayload(
                channel: $channel,
                timeMs: $timeMs,
            ),
            Vask::EVENT_MEMBER_ADDED => new MemberAddedPayload(
                channel: $channel,
                userId: $this->stringField($event, 'user_id'),
                timeMs: $timeMs,
            ),
            Vask::EVENT_MEMBER_REMOVED => new MemberRemovedPayload(
                channel: $channel,
                userId: $this->stringField($event, 'user_id'),
                timeMs: $timeMs,
            ),
            Vask::EVENT_CLIENT_EVENT => new ClientEventPayload(
                channel: $channel,
                event: $this->stringField($event, 'event'),
                data: $this->nullableStringField($event, 'data'),
                socketId: $this->nullableStringField($event, 'socket_id'),
                userId: $this->nullableStringField($event, 'user_id'),
                timeMs: $timeMs,
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function stringField(array $event, string $key): string
    {
        $value = $event[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Convert an array<mixed, mixed> to array<string, mixed> by dropping
     * any non-string keys. Used at the entry point where we receive
     * decoded JSON that hasn't been shape-validated yet.
     *
     * @param  array<mixed, mixed>  $array
     * @return array<string, mixed>
     */
    protected function withStringKeys(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function nullableStringField(array $event, string $key): ?string
    {
        $value = $event[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  Closure|array{0: class-string, 1: string}|class-string  $handler
     */
    protected function invoke(Closure|array|string $handler, object $payload): void
    {
        if (is_string($handler) && class_exists($handler)) {
            $handler = $this->container->make($handler);
        } elseif (is_array($handler)) {
            $handler = [$this->container->make($handler[0]), $handler[1]];
        }

        if (! is_callable($handler)) {
            return;
        }

        $handler($payload);
    }
}
