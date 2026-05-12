<?php

declare(strict_types=1);

namespace Vask\Laravel\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Throwable;
use Vask\Laravel\Support\BroadcastingDetector;
use Vask\Laravel\Vask;

class DoctorCommand extends Command
{
    protected const EXPECTED_HOST = 'wss.vask.dev';

    public $signature = 'vask:doctor
        {--no-ping : Skip the network reachability ping to wss.vask.dev (the ping runs by default)}
        {--no-broadcast : Skip the test event broadcast (the broadcast check runs by default)}';

    public $description = 'Verify Vask is configured correctly for this Laravel application.';

    public function handle(): int
    {
        $this->line('');
        $this->line('Checking Vask configuration...');
        $this->line('');

        $failures = 0;

        $failures += $this->checkPusherSdk();
        $failures += $this->checkBroadcastingWired();
        $failures += $this->checkBroadcastDriver();
        $failures += $this->checkHost();
        $failures += $this->checkPort();
        $failures += $this->checkScheme();
        $failures += $this->checkCredentials();

        $this->reportWebhookStatus();

        if (! $this->option('no-ping')) {
            $failures += $this->checkReachability();
        }

        if (! $this->option('no-broadcast')) {
            $failures += $this->checkBroadcastDelivery();
        }

        $this->line('');

        if ($failures > 0) {
            $this->error(sprintf('✗ %d check(s) failed. Fix the issues above and re-run.', $failures));

            return self::FAILURE;
        }

        $this->info('✓ All checks passed.');

        return self::SUCCESS;
    }

    protected function checkPusherSdk(): int
    {
        if (! class_exists(Pusher::class)) {
            $this->error('  ✗ pusher/pusher-php-server is not installed. Run: composer require pusher/pusher-php-server');

            return 1;
        }

        $this->info('  ✓ pusher/pusher-php-server is installed.');

        return 0;
    }

    protected function checkBroadcastingWired(): int
    {
        if (BroadcastingDetector::isWired($this->laravel->basePath())) {
            $this->info('  ✓ Laravel broadcasting is wired (routes/channels.php + bootstrap).');

            return 0;
        }

        $this->error('  ✗ Laravel broadcasting is not wired up (missing routes/channels.php or bootstrap wiring). Run: php artisan install:broadcasting --pusher --without-node');

        return 1;
    }

    protected function checkBroadcastDriver(): int
    {
        $driver = $this->configString('broadcasting.default');

        if ($driver !== 'pusher') {
            $this->error(sprintf("  ✗ Broadcast driver is '%s', expected 'pusher'. Set BROADCAST_CONNECTION=pusher (Laravel 11+) or BROADCAST_DRIVER=pusher (Laravel 10).", $driver));

            return 1;
        }

        $this->info("  ✓ Broadcast driver is 'pusher'.");

        return 0;
    }

    protected function checkHost(): int
    {
        $host = $this->configString('broadcasting.connections.pusher.options.host');

        if ($host !== self::EXPECTED_HOST) {
            $this->error("  ✗ Host is '".$host."', expected '".self::EXPECTED_HOST."'. Set PUSHER_HOST=".self::EXPECTED_HOST.'.');

            return 1;
        }

        $this->info("  ✓ Host is '".self::EXPECTED_HOST."'.");

        return 0;
    }

    protected function checkPort(): int
    {
        $port = $this->configInt('broadcasting.connections.pusher.options.port');

        if ($port !== 443) {
            $this->error(sprintf("  ✗ Port is '%d', expected '443'. Set PUSHER_PORT=443.", $port));

            return 1;
        }

        $this->info('  ✓ Port is 443.');

        return 0;
    }

    protected function checkScheme(): int
    {
        $scheme = $this->configString('broadcasting.connections.pusher.options.scheme');

        if ($scheme !== 'https') {
            $this->error(sprintf("  ✗ Scheme is '%s', expected 'https'. Set PUSHER_SCHEME=https.", $scheme));

            return 1;
        }

        $this->info('  ✓ Scheme is https.');

        return 0;
    }

    protected function checkCredentials(): int
    {
        $failures = 0;

        foreach (['key', 'secret', 'app_id'] as $field) {
            $value = $this->configString('broadcasting.connections.pusher.'.$field);
            if ($value === '') {
                $env = 'PUSHER_'.mb_strtoupper($field === 'app_id' ? 'APP_ID' : ($field === 'key' ? 'APP_KEY' : 'APP_SECRET'));
                $this->error(sprintf("  ✗ Missing credential '%s'. Set %s in .env.", $field, $env));
                $failures++;
            }
        }

        if ($failures === 0) {
            $this->info('  ✓ Credentials are set (key, secret, app_id).');
        }

        return $failures;
    }

    /**
     * Read a config value as a string. `env()` returns string for any non-empty
     * env var (including numeric ones like PUSHER_PORT="443"), so config()->string()
     * works here. But for keys backed by `env('FOO', 443)` with a non-string default,
     * we want to coerce, not throw.
     */
    protected function configString(string $key): string
    {
        $value = config()->get($key);

        return is_string($value) ? $value : '';
    }

    /**
     * Read a config value as an int, accepting both real ints and numeric strings
     * (which is what env-backed ports/timeouts will be).
     */
    protected function configInt(string $key): int
    {
        $value = config()->get($key);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1))))) {
            return (int) $value;
        }

        return 0;
    }

    protected function reportWebhookStatus(): void
    {
        $vask = $this->laravel->make(Vask::class);
        $handlerCount = count($vask->handlers());

        if ($handlerCount === 0) {
            $this->line('  • No webhook handlers registered — webhook route is not active.');

            return;
        }

        if (Route::has(Vask::ROUTE_NAME)) {
            $path = $vask->webhookPath();
            $this->info(sprintf('  ✓ Webhook route active: POST %s (%d handler(s))', $path, $handlerCount));

            return;
        }

        $this->line(sprintf('  • %d webhook handler(s) registered but no route found — did you call Vask::disableAutoWebhookRoute()?', $handlerCount));
    }

    protected function checkBroadcastDelivery(): int
    {
        if (! class_exists(Pusher::class)) {
            $this->error('  ✗ Cannot send test broadcast: pusher/pusher-php-server is not installed.');

            return 1;
        }

        $pusher = $this->buildPusherClient();

        $channel = 'vask-doctor';
        $event = 'doctor.ping';
        $payload = [
            'sent_at' => date(DateTimeInterface::ATOM),
            'host' => gethostname() ?: 'unknown-host',
        ];

        $this->line(sprintf("  → Broadcasting test event '%s' to public channel '%s'…", $event, $channel));

        try {
            $pusher->trigger($channel, $event, $payload);

            $this->info('  ✓ Test broadcast accepted by Vask.');

            return 0;
        } catch (ApiErrorException $apiErrorException) {
            $this->error('  ✗ Vask rejected the broadcast (HTTP '.$apiErrorException->getCode().'): '.$apiErrorException->getMessage());

            return 1;
        } catch (Throwable $throwable) {
            $this->error('  ✗ Broadcast failed: '.$throwable->getMessage());

            return 1;
        }
    }

    protected function buildPusherClient(): Pusher
    {
        $scheme = $this->configString('broadcasting.connections.pusher.options.scheme');
        if ($scheme === '') {
            $scheme = 'https';
        }

        $host = $this->configString('broadcasting.connections.pusher.options.host');
        if ($host === '') {
            $host = self::EXPECTED_HOST;
        }

        $cluster = $this->configString('broadcasting.connections.pusher.options.cluster');
        if ($cluster === '') {
            $cluster = 'mt1';
        }

        $port = $this->configInt('broadcasting.connections.pusher.options.port');
        if ($port === 0) {
            $port = 443;
        }

        return new Pusher(
            $this->configString('broadcasting.connections.pusher.key'),
            $this->configString('broadcasting.connections.pusher.secret'),
            $this->configString('broadcasting.connections.pusher.app_id'),
            [
                'host' => $host,
                'port' => $port,
                'scheme' => $scheme,
                'cluster' => $cluster,
                'useTLS' => $scheme === 'https',
                'timeout' => 5,
            ],
        );
    }

    protected function checkReachability(): int
    {
        $url = 'https://'.self::EXPECTED_HOST;
        $this->line(sprintf('  → Pinging %s ...', $url));

        try {
            $response = Http::timeout(5)->get($url);
            $status = $response->status();

            if ($status > 0) {
                $this->info(sprintf('  ✓ %s responded (%d).', $url, $status));

                return 0;
            }
        } catch (Throwable $throwable) {
            $this->error(sprintf('  ✗ Could not reach %s: %s', $url, $throwable->getMessage()));

            return 1;
        }

        $this->error(sprintf('  ✗ No response from %s.', $url));

        return 1;
    }
}
