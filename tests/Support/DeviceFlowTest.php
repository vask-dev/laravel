<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Vask\Laravel\Support\DeviceFlow;
use Vask\Laravel\Support\DeviceFlowResult;

beforeEach(function (): void {
    $this->slept = [];
    $this->flow = new DeviceFlow(function (int $seconds): void {
        $this->slept[] = $seconds;
    });
});

it('requests a device code and parses the response', function (): void {
    Http::fake([
        '*/oauth/device/code' => Http::response([
            'device_code' => 'dev-abc',
            'user_code' => 'WDJB-MJHT',
            'verification_uri' => 'https://vask.dev/device',
            'verification_uri_complete' => 'https://vask.dev/device/WDJB-MJHT',
            'expires_in' => 900,
            'interval' => 5,
        ]),
    ]);

    $code = $this->flow->requestDeviceCode();

    expect($code['device_code'])->toBe('dev-abc');
    expect($code['user_code'])->toBe('WDJB-MJHT');
    expect($code['verification_uri_complete'])->toBe('https://vask.dev/device/WDJB-MJHT');
    expect($code['interval'])->toBe(5);
    expect($code['expires_in'])->toBe(900);

    Http::assertSent(fn ($r): bool => str_ends_with((string) $r->url(), '/oauth/device/code')
        && $r['client_id'] === DeviceFlow::CLIENT_ID
        && ! isset($r->data()['device_name'])
        && $r->header('User-Agent')[0] === DeviceFlow::USER_AGENT
    );
});

it('sends device_name in the body when provided', function (): void {
    Http::fake([
        '*/oauth/device/code' => Http::response([
            'device_code' => 'd',
            'user_code' => 'c',
            'verification_uri' => 'https://vask.dev/device',
            'expires_in' => 60,
            'interval' => 1,
        ]),
    ]);

    $this->flow->requestDeviceCode('vask:install on test-host');

    Http::assertSent(fn ($r): bool => ($r->data()['device_name'] ?? null) === 'vask:install on test-host');
});

it('does not send device_name when an empty string is provided', function (): void {
    Http::fake([
        '*/oauth/device/code' => Http::response([
            'device_code' => 'd',
            'user_code' => 'c',
            'verification_uri' => 'https://vask.dev/device',
            'expires_in' => 60,
            'interval' => 1,
        ]),
    ]);

    $this->flow->requestDeviceCode('');

    Http::assertSent(fn ($r): bool => ! isset($r->data()['device_name']));
});

it('sends the package User-Agent on token polls too', function (): void {
    Http::fake([
        '*/oauth/token' => Http::response([
            'access_token' => 't', 'app_key' => 'k', 'app_secret' => 's',
        ]),
    ]);

    $this->flow->pollForToken('dev-abc');

    Http::assertSent(fn ($r): bool => $r->header('User-Agent')[0] === DeviceFlow::USER_AGENT);
});

it('returns SUCCESS when the token endpoint returns a token', function (): void {
    Http::fake([
        '*/oauth/token' => Http::response([
            'access_token' => 'tok-xyz',
            'token_type' => 'Bearer',
            'app_id' => 'app-1',
            'app_key' => 'key-1',
            'app_secret' => 'secret-1',
        ]),
    ]);

    $result = $this->flow->pollForToken('dev-abc');

    expect($result->status)->toBe(DeviceFlowResult::STATUS_SUCCESS);
    expect($result->token['access_token'])->toBe('tok-xyz');
    expect($result->token['app_key'])->toBe('key-1');
});

it('maps RFC 8628 errors to the right status', function (string $oauthError, string $status): void {
    Http::fake([
        '*/oauth/token' => Http::response(['error' => $oauthError], 400),
    ]);

    $result = $this->flow->pollForToken('dev-abc');

    expect($result->status)->toBe($status);
    expect($result->error)->toBe($oauthError);
})->with([
    ['authorization_pending', DeviceFlowResult::STATUS_PENDING],
    ['slow_down', DeviceFlowResult::STATUS_SLOW_DOWN],
    ['access_denied', DeviceFlowResult::STATUS_DENIED],
    ['expired_token', DeviceFlowResult::STATUS_EXPIRED],
    ['some_unknown_error', DeviceFlowResult::STATUS_ERROR],
]);

it('polls until the token is granted', function (): void {
    Http::fakeSequence()
        ->push(['error' => 'authorization_pending'], 400)
        ->push(['error' => 'authorization_pending'], 400)
        ->push([
            'access_token' => 'tok',
            'app_key' => 'k',
            'app_secret' => 's',
            'app_id' => 'i',
        ]);

    $result = $this->flow->awaitToken([
        'device_code' => 'dev-abc',
        'expires_in' => 60,
        'interval' => 2,
    ]);

    expect($result->status)->toBe(DeviceFlowResult::STATUS_SUCCESS);
    expect($this->slept)->toBe([2, 2, 2]);
});

it('backs off on slow_down', function (): void {
    Http::fakeSequence()
        ->push(['error' => 'slow_down'], 400)
        ->push(['access_token' => 't', 'app_key' => 'k', 'app_secret' => 's']);

    $this->flow->awaitToken([
        'device_code' => 'd',
        'expires_in' => 60,
        'interval' => 5,
    ]);

    // first poll at interval 5, then bumped to 10 after slow_down
    expect($this->slept)->toBe([5, 10]);
});

it('returns DENIED terminally when access is denied', function (): void {
    Http::fake([
        '*/oauth/token' => Http::response(['error' => 'access_denied'], 400),
    ]);

    $result = $this->flow->awaitToken([
        'device_code' => 'd',
        'expires_in' => 60,
        'interval' => 1,
    ]);

    expect($result->status)->toBe(DeviceFlowResult::STATUS_DENIED);
    expect($this->slept)->toBe([1]); // bailed after the first response
});

it('returns EXPIRED when the device code has expired', function (): void {
    Http::fake([
        '*/oauth/token' => Http::response(['error' => 'expired_token'], 400),
    ]);

    $result = $this->flow->awaitToken([
        'device_code' => 'd',
        'expires_in' => 60,
        'interval' => 1,
    ]);

    expect($result->status)->toBe(DeviceFlowResult::STATUS_EXPIRED);
});

it('uses VASK_API_URL env override when set', function (): void {
    putenv('VASK_API_URL=https://staging.vask.dev');

    try {
        expect((new DeviceFlow())->baseUrl())->toBe('https://staging.vask.dev');
    } finally {
        putenv('VASK_API_URL');
    }
});

it('falls back to the default API URL when env is not set', function (): void {
    putenv('VASK_API_URL');

    expect((new DeviceFlow())->baseUrl())->toBe(DeviceFlow::DEFAULT_API_URL);
});

it('throws when the device code response is malformed', function (): void {
    Http::fake([
        '*/oauth/device/code' => Http::response(['not' => 'a real response']),
    ]);

    $this->flow->requestDeviceCode();
})->throws(RuntimeException::class, 'Malformed device code response');

it('describes the HTTP response when the token endpoint returns no `error` field', function (): void {
    Http::fake([
        '*/oauth/token' => Http::response(['message' => 'Validation failed'], 422),
    ]);

    $result = $this->flow->pollForToken('dev-abc');

    expect($result->status)->toBe(DeviceFlowResult::STATUS_ERROR);
    expect($result->error)->toBe('unknown_error');
    expect($result->errorDescription)->toContain('HTTP 422');
    expect($result->errorDescription)->toContain('Validation failed');
});

it('describes an empty body when the token endpoint returns no JSON', function (): void {
    Http::fake([
        '*/oauth/token' => Http::response('', 500),
    ]);

    $result = $this->flow->pollForToken('dev-abc');

    expect($result->status)->toBe(DeviceFlowResult::STATUS_ERROR);
    expect($result->errorDescription)->toContain('HTTP 500');
    expect($result->errorDescription)->toContain('(empty body)');
});
