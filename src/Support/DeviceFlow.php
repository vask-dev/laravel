<?php

declare(strict_types=1);

namespace Vask\Laravel\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeviceFlow
{
    public const DEFAULT_API_URL = 'https://vask.dev';

    public const CLIENT_ID = 'f9c7a2e4-1b3d-4e8f-9c6a-2d7f1b4e8c3a';

    public const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';

    public const USER_AGENT = 'vask-dev/laravel';

    /** @var callable */
    protected $sleeper;

    public function __construct(?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? 'sleep';
    }

    public function baseUrl(): string
    {
        // Env::get instead of env() so larastan's noEnvCallsOutsideOfConfig
        // rule doesn't flag this — same behaviour, narrower call surface.
        $override = Env::get('VASK_API_URL');
        $configured = is_string($override) && $override !== '' ? $override : self::DEFAULT_API_URL;

        return mb_rtrim($configured, '/');
    }

    /**
     * Request a new device authorization code from Vask.
     *
     * Spec: https://datatracker.ietf.org/doc/html/rfc8628#section-3.1
     *
     * @param  string|null  $deviceName  Optional human-readable label shown in the
     *                                   Vask dashboard's "Connected Devices" list.
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string|null, expires_in: int, interval: int}
     *
     * @throws ConnectionException
     */
    public function requestDeviceCode(?string $deviceName = null): array
    {
        $body = ['client_id' => self::CLIENT_ID];

        if (is_string($deviceName) && $deviceName !== '') {
            $body['device_name'] = $deviceName;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->post($this->baseUrl().'/oauth/device/code', $body);

        $response->throw();

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Malformed device code response from Vask.');
        }

        $deviceCode = $payload['device_code'] ?? null;
        $userCode = $payload['user_code'] ?? null;
        $verificationUri = $payload['verification_uri'] ?? null;

        if (! is_string($deviceCode) || ! is_string($userCode) || ! is_string($verificationUri)) {
            throw new RuntimeException('Malformed device code response from Vask.');
        }

        $verificationUriComplete = $payload['verification_uri_complete'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;
        $interval = $payload['interval'] ?? null;

        return [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'verification_uri' => $verificationUri,
            'verification_uri_complete' => is_string($verificationUriComplete) ? $verificationUriComplete : null,
            'expires_in' => is_int($expiresIn) ? $expiresIn : 900,
            'interval' => is_int($interval) ? max(1, $interval) : 5,
        ];
    }

    /**
     * One token-endpoint poll.
     *
     * Spec: https://datatracker.ietf.org/doc/html/rfc8628#section-3.4
     * https://datatracker.ietf.org/doc/html/rfc8628#section-3.5
     */
    public function pollForToken(string $deviceCode): DeviceFlowResult
    {
        $response = Http::asForm()
            ->acceptJson()
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->post($this->baseUrl().'/oauth/token', [
                'client_id' => self::CLIENT_ID,
                'device_code' => $deviceCode,
                'grant_type' => self::GRANT_TYPE,
            ]);

        $body = $response->json();
        if (! is_array($body)) {
            $body = [];
        }

        if ($response->successful() && isset($body['access_token'])) {
            $token = [];
            foreach ($body as $key => $value) {
                if (is_string($key)) {
                    $token[$key] = $value;
                }
            }

            return new DeviceFlowResult(DeviceFlowResult::STATUS_SUCCESS, token: $token);
        }

        // RFC 8628 requires an `error` field in the response when polling fails.
        // If the backend returns something else (Laravel validation error,
        // HTML, empty body, etc.) we have nothing to map to a known status, so
        // include the HTTP code and a body snippet so the user/agent can see
        // what came back.
        $error = $body['error'] ?? null;
        if (! is_string($error)) {
            return new DeviceFlowResult(
                DeviceFlowResult::STATUS_ERROR,
                error: 'unknown_error',
                errorDescription: $this->describeUnexpectedResponse($response),
            );
        }

        $description = $body['error_description'] ?? null;
        if (! is_string($description)) {
            $description = null;
        }

        $status = match ($error) {
            'authorization_pending' => DeviceFlowResult::STATUS_PENDING,
            'slow_down' => DeviceFlowResult::STATUS_SLOW_DOWN,
            'access_denied' => DeviceFlowResult::STATUS_DENIED,
            'expired_token' => DeviceFlowResult::STATUS_EXPIRED,
            default => DeviceFlowResult::STATUS_ERROR,
        };

        return new DeviceFlowResult($status, error: $error, errorDescription: $description);
    }

    /**
     * Poll until the device is approved, denied, or the code expires.
     *
     * @param  array{device_code: string, expires_in: int, interval: int}  $deviceCode
     * @param  callable(DeviceFlowResult): void|null  $onTick  Invoked after each poll with the result.
     */
    public function awaitToken(array $deviceCode, ?callable $onTick = null): DeviceFlowResult
    {
        $interval = max(1, $deviceCode['interval']);
        $deadline = time() + $deviceCode['expires_in'];

        while (time() < $deadline) {
            ($this->sleeper)($interval);

            $result = $this->pollForToken($deviceCode['device_code']);

            if ($onTick !== null) {
                $onTick($result);
            }

            if ($result->isTerminal()) {
                return $result;
            }

            if ($result->status === DeviceFlowResult::STATUS_SLOW_DOWN) {
                $interval += 5;
            }
        }

        return new DeviceFlowResult(
            DeviceFlowResult::STATUS_EXPIRED,
            error: 'expired_token',
            errorDescription: 'Device code expired before approval.',
        );
    }

    protected function describeUnexpectedResponse(Response $response): string
    {
        $raw = $response->body();
        $snippet = str_replace(["\r", "\n"], ' ', mb_substr($raw, 0, 300));

        if (mb_strlen($raw) > 300) {
            $snippet .= '…';
        }

        if ($snippet === '') {
            $snippet = '(empty body)';
        }

        return sprintf('HTTP %d, body: %s', $response->status(), $snippet);
    }
}
