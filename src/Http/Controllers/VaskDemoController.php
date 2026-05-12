<?php

declare(strict_types=1);

namespace Vask\Laravel\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
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
