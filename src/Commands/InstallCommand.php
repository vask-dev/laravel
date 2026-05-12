<?php

declare(strict_types=1);

namespace Vask\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Throwable;
use Vask\Laravel\Support\BroadcastingDetector;
use Vask\Laravel\Support\DeviceFlow;
use Vask\Laravel\Support\DeviceFlowResult;
use Vask\Laravel\Support\EnvWriter;
use Vask\Laravel\VaskServiceProvider;

class InstallCommand extends Command
{
    public $signature = 'vask:install
        {--env= : Path to the .env file to update (defaults to the application .env)}
        {--force : Overwrite existing PUSHER_* values without prompting}
        {--device-name= : Label shown in the Vask dashboard for this device (defaults to "vask:install on <hostname>")}
        {--skip-broadcasting-install : Do not auto-run install:broadcasting when Laravel broadcasting is not wired up}
        {--no-ping : Skip the network reachability ping in the chained vask:doctor}
        {--no-broadcast : Skip the live test broadcast in the chained vask:doctor}
        {--no-doctor : Skip the vask:doctor verification step at the end}';

    public $description = 'Sign up or sign in to Vask and write credentials to .env via OAuth device flow.';

    public function handle(DeviceFlow $flow): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Setting up Vask</>');
        $this->line('  <fg=gray>https://vask.dev — Pusher-compatible WebSockets on Cloudflare</>');
        $this->newLine();

        if (! $this->ensureBroadcastingWired()) {
            return self::FAILURE;
        }

        $deviceName = $this->resolveDeviceName();

        try {
            $code = $flow->requestDeviceCode($deviceName);
        } catch (ConnectionException $e) {
            return $this->bail(sprintf('Could not contact Vask at %s: %s', $flow->baseUrl(), $e->getMessage()));
        } catch (Throwable $e) {
            return $this->bail('Failed to start device flow: '.$e->getMessage());
        }

        $this->displayVerification($code);

        $result = $flow->awaitToken($code, fn (DeviceFlowResult $r) => $this->renderTick($r));

        $this->newLine(2);

        return match ($result->status) {
            DeviceFlowResult::STATUS_SUCCESS => $this->onApproved($result),
            DeviceFlowResult::STATUS_DENIED => $this->bail('Approval was denied in the browser.'),
            DeviceFlowResult::STATUS_EXPIRED => $this->bail('Device code expired before approval. Re-run vask:install.'),
            default => $this->bail(sprintf('Device flow failed: %s (%s)', $result->errorDescription, $result->error)),
        };
    }

    /**
     * @param  array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string|null, expires_in: int, interval: int}  $code
     */
    protected function displayVerification(array $code): void
    {
        $url = $code['verification_uri_complete'] ?? $code['verification_uri'];

        $this->line('  Open this URL to approve in your browser:');
        $this->line(sprintf('    <fg=green;options=bold>%s</>', $url));
        $this->newLine();
        $this->line('  Your verification code:');
        $this->line(sprintf('    <fg=yellow;options=bold>%s</>', $code['user_code']));
        $this->newLine();
        $this->line('  <fg=gray>Waiting for approval (press Ctrl+C to cancel)...</>');
    }

    protected function ensureBroadcastingWired(): bool
    {
        $basePath = $this->laravel->basePath();

        if (BroadcastingDetector::isWired($basePath)) {
            return true;
        }

        if ($this->option('skip-broadcasting-install')) {
            $this->warn('  ⚠ Laravel broadcasting is not wired up (no routes/channels.php). Private/presence channels will fail.');
            $this->line('  <fg=gray>--skip-broadcasting-install given; continuing anyway.</>');
            $this->newLine();

            return true;
        }

        // install:broadcasting (Laravel 11+) is the supported path. Bail
        // early if the command isn't registered — caller is on Laravel 10
        // or a non-standard app skeleton.
        $registeredCommands = $this->laravel->make(Kernel::class)->all();
        if (! array_key_exists('install:broadcasting', $registeredCommands)) {
            $this->bail(
                'Laravel broadcasting is not wired up and `install:broadcasting` is not available '.
                '(likely Laravel 10). Set up broadcasting manually: create routes/channels.php and '.
                'enable App\\Providers\\BroadcastServiceProvider in config/app.php. Then re-run.',
            );

            return false;
        }

        $this->line('  <fg=cyan>Setting up Laravel broadcasting (install:broadcasting --pusher)…</>');

        if ($this->tryInstallBroadcasting($basePath)) {
            $this->info('  ✓ Broadcasting wired up automatically.');
            $this->newLine();

            return true;
        }

        $this->error('  ✗ install:broadcasting did not wire up broadcasting cleanly. Re-running with output:');
        $this->newLine();
        $this->call('install:broadcasting', [
            '--pusher' => true,
            '--without-node' => true,
            '--without-reverb' => true,
        ]);
        $this->bail('Aborting. Resolve the issue above and re-run vask:install.');

        return false;
    }

    /**
     * Run install:broadcasting silently with --no-interaction and report
     * whether broadcasting ended up wired afterwards. Extracted into its own
     * method so phpstan doesn't see the BroadcastingDetector check as
     * provably-false (it narrowed `isWired($basePath) === false` from the
     * caller's earlier guard).
     */
    protected function tryInstallBroadcasting(string $basePath): bool
    {
        // install:broadcasting prompts for Pusher App ID/Key/Secret/Cluster.
        // The cluster select() has no default, so under --no-interaction
        // Laravel Prompts throws NonInteractiveValidationException — but
        // only AFTER every file we need has been created (channels.php,
        // bootstrap wiring, broadcasting config). We swallow the expected
        // exception and judge success/failure by the filesystem state,
        // not by the command's exit code. Any Pusher .env values it would
        // have written (which the cluster failure prevents) get
        // overwritten by us in the next step anyway.
        try {
            $this->callSilent('install:broadcasting', [
                '--pusher' => true,
                '--without-node' => true,
                '--without-reverb' => true,
                '--no-interaction' => true,
            ]);
        } catch (Throwable) {
            // Expected when the cluster prompt fires under --no-interaction.
        }

        return BroadcastingDetector::isWired($basePath);
    }

    protected function resolveDeviceName(): string
    {
        $override = $this->option('device-name');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $host = gethostname();
        if (! is_string($host) || $host === '') {
            $host = 'unknown-host';
        }

        return 'vask:install on '.$host;
    }

    protected function renderTick(DeviceFlowResult $result): void
    {
        // Print a single character per poll so the user sees progress without
        // flooding the terminal. Errors break out via the awaitToken return.
        $glyph = match ($result->status) {
            DeviceFlowResult::STATUS_PENDING => '.',
            DeviceFlowResult::STATUS_SLOW_DOWN => '~',
            default => '',
        };

        if ($glyph !== '') {
            $this->output->write($glyph);
        }
    }

    protected function onApproved(DeviceFlowResult $result): int
    {
        $token = $result->token ?? [];

        foreach (['app_key', 'app_secret'] as $required) {
            if (empty($token[$required])) {
                return $this->bail(sprintf('Vask returned a token but %s was missing.', $required));
            }
        }

        // Vask routes the Pusher HTTP API by app_key (not by an internal
        // app_id), so PUSHER_APP_ID and PUSHER_APP_KEY must both be the
        // app_key. Any `app_id` in the token response is intentionally
        // ignored — using it makes /apps/{app_id}/events return 404.
        $appKey = is_string($token['app_key']) ? $token['app_key'] : '';
        $appId = $appKey;
        $appSecret = is_string($token['app_secret']) ? $token['app_secret'] : '';

        $envOption = $this->option('env');
        $envPath = is_string($envOption) && $envOption !== ''
            ? $envOption
            : $this->laravel->environmentFilePath();

        $this->ensureEnvFileExists($envPath);

        $proposed = [
            'BROADCAST_CONNECTION' => 'pusher',
            'PUSHER_APP_ID' => $appId,
            'PUSHER_APP_KEY' => $appKey,
            'PUSHER_APP_SECRET' => $appSecret,
            'PUSHER_HOST' => 'wss.vask.dev',
            'PUSHER_PORT' => '443',
            'PUSHER_SCHEME' => 'https',
            'PUSHER_APP_CLUSTER' => 'mt1',
            'VITE_PUSHER_APP_KEY' => '${PUSHER_APP_KEY}',
        ];

        $this->info('  ✓ Approved.');

        if (! $this->confirmOverwrites($envPath, $proposed)) {
            $this->newLine();

            return $this->bail('Aborted — existing .env values were not modified.');
        }

        $changed = EnvWriter::setKeys($envPath, $proposed);

        // The chained vask:doctor runs in the same process, so writing to
        // .env isn't enough — Laravel resolves config from env at boot, and
        // we're past that. Mirror the values into config() so the doctor
        // sees the fresh broadcasting connection.
        $this->applyToConfig($proposed);

        $this->info('  ✓ Wrote Vask credentials to '.$this->relativeEnvPath($envPath).($changed ? '' : ' (already current)'));

        $exampleAdded = $this->seedEnvExample($envPath);
        if ($exampleAdded > 0) {
            $this->info(sprintf('  ✓ Added %d Vask placeholder key(s) to .env.example.', $exampleAdded));
        }

        $this->showDemoHint();

        $this->newLine();

        if (! $this->option('no-doctor')) {
            $passthrough = [];
            if ($this->option('no-ping')) {
                $passthrough['--no-ping'] = true;
            }
            if ($this->option('no-broadcast')) {
                $passthrough['--no-broadcast'] = true;
            }

            return $this->call('vask:doctor', $passthrough);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $proposed
     */
    protected function confirmOverwrites(string $envPath, array $proposed): bool
    {
        $overwrites = EnvWriter::wouldOverwrite($envPath, $proposed);

        if ($overwrites === []) {
            return true;
        }

        if ($this->option('force')) {
            $this->line('  <fg=gray>--force given; overwriting '.count($overwrites).' existing value(s).</>');

            return true;
        }

        $this->newLine();
        $this->warn('  '.count($overwrites).' existing value(s) in '.$this->relativeEnvPath($envPath).' would be overwritten:');
        $this->newLine();

        $secretLike = ['PUSHER_APP_SECRET'];

        foreach ($overwrites as $key => $pair) {
            $current = in_array($key, $secretLike, true) ? $this->maskSecret($pair['current']) : $pair['current'];
            $proposedValue = in_array($key, $secretLike, true) ? $this->maskSecret($pair['proposed']) : $pair['proposed'];
            $this->line(sprintf('    %s: <fg=red>%s</>  →  <fg=green>%s</>', $key, $current, $proposedValue));
        }

        $this->newLine();

        if (! $this->input->isInteractive()) {
            $this->error('  ✗ Cannot prompt in non-interactive mode. Re-run with --force to overwrite.');

            return false;
        }

        return $this->confirm('  Overwrite these values?', true);
    }

    /**
     * Append Vask-related keys to .env.example with safe placeholder values.
     * Existing keys are NEVER touched (they may be intentional placeholders
     * a team member left in for collaboration). Credential values are
     * intentionally blank — .env.example is committed to git.
     *
     * @return int number of keys added
     */
    protected function seedEnvExample(string $envPath): int
    {
        $examplePath = dirname($envPath).DIRECTORY_SEPARATOR.'.env.example';

        if (! file_exists($examplePath)) {
            return 0;
        }

        $placeholders = [
            'BROADCAST_CONNECTION' => 'pusher',
            'PUSHER_APP_ID' => '',
            'PUSHER_APP_KEY' => '',
            'PUSHER_APP_SECRET' => '',
            'PUSHER_HOST' => 'wss.vask.dev',
            'PUSHER_PORT' => '443',
            'PUSHER_SCHEME' => 'https',
            'PUSHER_APP_CLUSTER' => 'mt1',
            'VITE_PUSHER_APP_KEY' => '${PUSHER_APP_KEY}',
        ];

        $before = $this->countKeysPresentIn($examplePath, array_keys($placeholders));
        EnvWriter::setMissingKeys($examplePath, $placeholders);
        $after = $this->countKeysPresentIn($examplePath, array_keys($placeholders));

        return $after - $before;
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function countKeysPresentIn(string $path, array $keys): int
    {
        $count = 0;
        foreach ($keys as $key) {
            if (EnvWriter::get($path, $key) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Print a "Try it" hint pointing at the local-only demo route. We can't
     * know the dev server port from a CLI command, so we show the path and
     * let the user paste it after their `php artisan serve` URL.
     */
    protected function showDemoHint(): void
    {
        if (VaskServiceProvider::demoDisabledByEnv()) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Try it out</>');
        $this->line('  Start your dev server and visit <fg=green;options=bold>'.VaskServiceProvider::DEMO_PATH.'</> in your browser');
        $this->line('  <fg=gray>(local environment only — set VASK_NO_DEMO=true to disable)</>');
    }

    /**
     * @param  array<string, string>  $env
     */
    protected function applyToConfig(array $env): void
    {
        config([
            'broadcasting.default' => $env['BROADCAST_CONNECTION'],
            'broadcasting.connections.pusher.key' => $env['PUSHER_APP_KEY'],
            'broadcasting.connections.pusher.secret' => $env['PUSHER_APP_SECRET'],
            'broadcasting.connections.pusher.app_id' => $env['PUSHER_APP_ID'],
            'broadcasting.connections.pusher.options.host' => $env['PUSHER_HOST'],
            'broadcasting.connections.pusher.options.port' => (int) $env['PUSHER_PORT'],
            'broadcasting.connections.pusher.options.scheme' => $env['PUSHER_SCHEME'],
            'broadcasting.connections.pusher.options.cluster' => $env['PUSHER_APP_CLUSTER'],
        ]);
    }

    protected function maskSecret(string $value): string
    {
        $length = mb_strlen($value);
        if ($length <= 4) {
            return str_repeat('*', max($length, 1));
        }

        return str_repeat('*', max(0, $length - 4)).mb_substr($value, -4);
    }

    protected function ensureEnvFileExists(string $envPath): void
    {
        if (file_exists($envPath)) {
            return;
        }

        $example = dirname($envPath).DIRECTORY_SEPARATOR.'.env.example';

        if (file_exists($example)) {
            @copy($example, $envPath);

            return;
        }

        @file_put_contents($envPath, '');
    }

    protected function relativeEnvPath(string $path): string
    {
        $base = base_path();

        if (str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return mb_substr($path, mb_strlen($base) + 1);
        }

        return $path;
    }

    protected function bail(string $message): int
    {
        $this->error('  ✗ '.$message);

        return self::FAILURE;
    }
}
