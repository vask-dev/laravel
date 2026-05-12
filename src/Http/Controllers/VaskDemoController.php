<?php

declare(strict_types=1);

namespace Vask\Laravel\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Vask\Laravel\Broadcasting\VaskDemoEvent;

class VaskDemoController
{
    public function show(): View
    {
        // Pull from the same config slots vask:install writes to .env, so
        // what the browser sees matches what the server is broadcasting to.
        $key = $this->configString('broadcasting.connections.pusher.key');
        $host = $this->configString('broadcasting.connections.pusher.options.host');
        $port = $this->configInt('broadcasting.connections.pusher.options.port', 443);
        $scheme = $this->configString('broadcasting.connections.pusher.options.scheme', 'https');
        $cluster = $this->configString('broadcasting.connections.pusher.options.cluster', 'mt1');

        return view('vask::demo', [
            'key' => $key,
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'cluster' => $cluster,
            'channel' => VaskDemoEvent::CHANNEL,
            'eventName' => VaskDemoEvent::NAME,
            'clientEventName' => VaskDemoEvent::CLIENT_EVENT_NAME,
            'configured' => $key !== '' && $host !== '',
        ]);
    }

    public function broadcast(Request $request): Response
    {
        // validate() throws on failure; we re-read the values through narrow
        // type guards below so phpstan keeps a non-mixed view of each field.
        $request->validate([
            'emoji' => ['required', 'string', 'max:8'],
            'x' => ['required', 'numeric', 'between:0,1'],
            'senderId' => ['required', 'string', 'max:64'],
            'sentAt' => ['required', 'numeric'],
        ]);

        broadcast(new VaskDemoEvent(
            emoji: $this->stringInput($request, 'emoji'),
            x: $this->floatInput($request, 'x'),
            id: (string) Str::uuid(),
            senderId: $this->stringInput($request, 'senderId'),
            sentAt: $this->floatInput($request, 'sentAt'),
        ));

        return new Response('', 204);
    }

    /**
     * Pusher channel-auth endpoint for the private demo channel.
     *
     * Restricted to exactly VaskDemoEvent::CHANNEL — the route is local-only,
     * but we still don't want it to behave as a general-purpose signer that
     * could vouch for arbitrary private channels in the host app.
     */
    public function auth(Request $request): JsonResponse
    {
        $socketId = $this->stringInput($request, 'socket_id');
        $channelName = $this->stringInput($request, 'channel_name');

        throw_if($channelName !== VaskDemoEvent::CHANNEL, HttpException::class, 403, 'Demo auth route only signs '.VaskDemoEvent::CHANNEL.'.');

        // Pusher socket IDs look like "12345.67890" — guard against weird
        // input being concatenated into the signed string.
        throw_if($socketId === '' || preg_match('/^\d+\.\d+$/', $socketId) !== 1, HttpException::class, 403, 'Invalid socket_id.');

        $key = $this->configString('broadcasting.connections.pusher.key');
        $secret = $this->configString('broadcasting.connections.pusher.secret');

        throw_if($key === '' || $secret === '', HttpException::class, 500, 'Vask credentials are not configured. See `php artisan vask:doctor`.');

        $signature = hash_hmac('sha256', $socketId.':'.$channelName, $secret);

        return new JsonResponse(['auth' => $key.':'.$signature]);
    }

    protected function stringInput(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    protected function floatInput(Request $request, string $key): float
    {
        $value = $request->input($key);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function configString(string $key, string $default = ''): string
    {
        $value = config()->get($key);

        return is_string($value) ? $value : $default;
    }

    protected function configInt(string $key, int $default): int
    {
        $value = config()->get($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
