<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Vask\Laravel\Support\DeviceFlow;

beforeEach(function (): void {
    // Inject a DeviceFlow with a no-op sleeper so tests don't actually pause.
    $this->app->instance(DeviceFlow::class, new DeviceFlow(fn () => null));

    // Each test gets its own isolated temp dir so .env / .env.example
    // fixtures from one test can't leak into another.
    $this->envDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vask-install-'.bin2hex(random_bytes(4));
    mkdir($this->envDir, 0777, true);
    $this->envPath = $this->envDir.DIRECTORY_SEPARATOR.'.env';

    // Pre-seed valid broadcasting config so the chained vask:doctor passes.
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher.options.host', 'wss.vask.dev');
    config()->set('broadcasting.connections.pusher.options.port', 443);
    config()->set('broadcasting.connections.pusher.options.scheme', 'https');

    // Pretend broadcasting is wired up in the testbench app — the chained
    // vask:doctor checks for this. Individual tests can rm the files to
    // exercise the failure path.
    $basePath = app()->basePath();
    @mkdir($basePath.'/routes', 0777, true);
    @mkdir($basePath.'/bootstrap', 0777, true);
    file_put_contents($basePath.'/routes/channels.php', "<?php\n");
    file_put_contents($basePath.'/bootstrap/app.php', "<?php\n->withRouting(channels: __DIR__.'/../routes/channels.php')\n");
});

afterEach(function (): void {
    $basePath = app()->basePath();
    @unlink($basePath.'/routes/channels.php');
    @unlink($basePath.'/bootstrap/app.php');

    if (! is_dir($this->envDir)) {
        return;
    }
    foreach (glob($this->envDir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->envDir);
});

it('runs the full device flow end to end and writes credentials to .env', function (): void {
    Http::fake([
        '*/oauth/device/code' => Http::response([
            'device_code' => 'dev-abc',
            'user_code' => 'WDJB-MJHT',
            'verification_uri' => 'https://vask.dev/device',
            'verification_uri_complete' => 'https://vask.dev/device/WDJB-MJHT',
            'expires_in' => 60,
            'interval' => 1,
        ]),
    ]);

    Http::fakeSequence('*/oauth/token')
        ->push(['error' => 'authorization_pending'], 400)
        ->push([
            'access_token' => 'tok-xyz',
            'token_type' => 'Bearer',
            'app_id' => 'app-id-42',
            'app_key' => 'app-key-42',
            'app_secret' => 'app-secret-42',
        ]);

    test()->artisan('vask:install', [
        '--env' => $this->envPath,
        '--skip-broadcasting-install' => true,
        '--no-ping' => true,       // chained vask:doctor would otherwise try real HTTP
        '--no-broadcast' => true,
    ])
        ->expectsOutputToContain('https://vask.dev/device/WDJB-MJHT')
        ->expectsOutputToContain('WDJB-MJHT')
        ->expectsOutputToContain('Approved')
        ->assertSuccessful();

    $contents = file_get_contents($this->envPath);
    expect($contents)->toContain('BROADCAST_CONNECTION=pusher');
    expect($contents)->toContain('PUSHER_APP_KEY=app-key-42');
    expect($contents)->toContain('PUSHER_APP_SECRET=app-secret-42');
    // PUSHER_APP_ID is always set to the app_key value — Vask routes by
    // app_key, so any `app_id` in the token response is intentionally ignored.
    expect($contents)->toContain('PUSHER_APP_ID=app-key-42');
    expect($contents)->toContain('PUSHER_HOST=wss.vask.dev');
    expect($contents)->toContain('PUSHER_PORT=443');
    expect($contents)->toContain('PUSHER_SCHEME=https');
});

it('fails gracefully when the user denies approval in the browser', function (): void {
    Http::fake([
        '*/oauth/device/code' => Http::response([
            'device_code' => 'dev-abc',
            'user_code' => 'WDJB-MJHT',
            'verification_uri' => 'https://vask.dev/device',
            'expires_in' => 60,
            'interval' => 1,
        ]),
        '*/oauth/token' => Http::response(['error' => 'access_denied'], 400),
    ]);

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->expectsOutputToContain('Approval was denied')
        ->assertFailed();
});

it('fails when the device code expires before approval', function (): void {
    Http::fake([
        '*/oauth/device/code' => Http::response([
            'device_code' => 'dev-abc',
            'user_code' => 'WDJB-MJHT',
            'verification_uri' => 'https://vask.dev/device',
            'expires_in' => 60,
            'interval' => 1,
        ]),
        '*/oauth/token' => Http::response(['error' => 'expired_token'], 400),
    ]);

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->expectsOutputToContain('Device code expired')
        ->assertFailed();
});

it('preserves existing .env contents when writing credentials', function (): void {
    file_put_contents($this->envPath, "APP_NAME=Laravel\nDB_DATABASE=mydb\n");

    Http::fake([
        '*/oauth/device/code' => Http::response([
            'device_code' => 'd',
            'user_code' => 'C',
            'verification_uri' => 'https://vask.dev/device',
            'expires_in' => 60,
            'interval' => 1,
        ]),
        '*/oauth/token' => Http::response([
            'access_token' => 't',
            'app_id' => 'i',
            'app_key' => 'k',
            'app_secret' => 's',
        ]),
    ]);

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->assertSuccessful();

    $contents = file_get_contents($this->envPath);
    expect($contents)->toContain('APP_NAME=Laravel');
    expect($contents)->toContain('DB_DATABASE=mydb');
    expect($contents)->toContain('PUSHER_APP_KEY=k');
});

it('fails when Vask is unreachable', function (): void {
    Http::fake([
        '*/oauth/device/code' => fn () => throw new ConnectionException('Connection refused'),
    ]);

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->expectsOutputToContain('Could not contact Vask')
        ->assertFailed();
});

it('fails when the token response is missing required app credentials', function (): void {
    Http::fake([
        '*/oauth/device/code' => Http::response([
            'device_code' => 'd',
            'user_code' => 'C',
            'verification_uri' => 'https://vask.dev/device',
            'expires_in' => 60,
            'interval' => 1,
        ]),
        '*/oauth/token' => Http::response([
            'access_token' => 't',
            // missing app_key/app_secret
        ]),
    ]);

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->expectsOutputToContain('app_key was missing')
        ->assertFailed();
});

function fakeSuccessfulInstall(): void
{
    Http::fake([
        '*/oauth/device/code' => Http::response([
            'device_code' => 'd',
            'user_code' => 'WDJB-MJHT',
            'verification_uri' => 'https://vask.dev/device',
            'expires_in' => 60,
            'interval' => 1,
        ]),
        '*/oauth/token' => Http::response([
            'access_token' => 't',
            'app_id' => 'new-id',
            'app_key' => 'new-key',
            'app_secret' => 'new-secret',
        ]),
    ]);
}

it('prompts before overwriting existing PUSHER_* values and aborts if the user declines', function (): void {
    file_put_contents($this->envPath, "PUSHER_APP_KEY=existing-key\nPUSHER_APP_SECRET=existing-secret\n");
    fakeSuccessfulInstall();

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->expectsOutputToContain('would be overwritten')
        ->expectsOutputToContain('PUSHER_APP_KEY')
        ->expectsConfirmation('  Overwrite these values?', 'no')
        ->expectsOutputToContain('Aborted')
        ->assertFailed();

    // .env was NOT modified.
    $contents = file_get_contents($this->envPath);
    expect($contents)->toContain('PUSHER_APP_KEY=existing-key');
    expect($contents)->not->toContain('new-key');
});

it('proceeds when the user confirms the overwrite prompt', function (): void {
    file_put_contents($this->envPath, "PUSHER_APP_KEY=existing-key\nPUSHER_APP_SECRET=existing-secret\n");
    fakeSuccessfulInstall();

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->expectsOutputToContain('would be overwritten')
        ->expectsConfirmation('  Overwrite these values?', 'yes')
        ->assertSuccessful();

    expect(file_get_contents($this->envPath))->toContain('PUSHER_APP_KEY=new-key');
});

it('skips the prompt when --force is given', function (): void {
    file_put_contents($this->envPath, "PUSHER_APP_KEY=existing-key\nPUSHER_APP_SECRET=existing-secret\n");
    fakeSuccessfulInstall();

    test()->artisan('vask:install', ['--env' => $this->envPath, '--force' => true, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->doesntExpectOutput('Overwrite these values?')
        ->expectsOutputToContain('--force given; overwriting')
        ->assertSuccessful();

    expect(file_get_contents($this->envPath))->toContain('PUSHER_APP_KEY=new-key');
});

it('does not prompt when there are no overwrites to confirm', function (): void {
    // No existing PUSHER_* keys → no overwrite, no prompt.
    file_put_contents($this->envPath, "APP_NAME=Laravel\n");
    fakeSuccessfulInstall();

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->doesntExpectOutput('would be overwritten')
        ->assertSuccessful();

    expect(file_get_contents($this->envPath))->toContain('PUSHER_APP_KEY=new-key');
});

it('does not prompt when existing values already match the proposed values', function (): void {
    // .env already has the exact values vask:install is about to write. Note
    // PUSHER_APP_ID is always set to app_key (Vask routes by app_key), so we
    // use 'new-key' here for both, not the 'new-id' the fake returns.
    file_put_contents(
        $this->envPath,
        "PUSHER_APP_KEY=new-key\nPUSHER_APP_SECRET=new-secret\nPUSHER_APP_ID=new-key\n",
    );
    fakeSuccessfulInstall();

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->doesntExpectOutput('would be overwritten')
        ->assertSuccessful();
});

it('sends a hostname-based device_name by default', function (): void {
    fakeSuccessfulInstall();

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->assertSuccessful();

    $hostname = gethostname() ?: 'unknown-host';
    Http::assertSent(fn ($r): bool => str_ends_with((string) $r->url(), '/oauth/device/code')
        && ($r->data()['device_name'] ?? null) === 'vask:install on '.$hostname
    );
});

it('seeds .env.example with Vask placeholders when present', function (): void {
    $exampleDir = dirname($this->envPath);
    $examplePath = $exampleDir.DIRECTORY_SEPARATOR.'.env.example';
    file_put_contents($examplePath, "APP_NAME=Laravel\n");

    fakeSuccessfulInstall();

    try {
        test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
            ->expectsOutputToContain('placeholder key(s) to .env.example')
            ->assertSuccessful();

        $example = file_get_contents($examplePath);

        // Existing line preserved.
        expect($example)->toContain('APP_NAME=Laravel');
        // Non-secret config values written as real placeholders.
        expect($example)->toContain('PUSHER_HOST=wss.vask.dev');
        expect($example)->toContain('PUSHER_PORT=443');
        expect($example)->toContain('PUSHER_SCHEME=https');
        expect($example)->toContain('BROADCAST_CONNECTION=pusher');
        // Credential values appear as empty placeholders — never the real secret.
        expect($example)->toContain("PUSHER_APP_KEY=\n");
        expect($example)->toContain("PUSHER_APP_SECRET=\n");
        expect($example)->not->toContain('new-key');
        expect($example)->not->toContain('new-secret');
    } finally {
        @unlink($examplePath);
    }
});

it('does not modify existing .env.example keys', function (): void {
    $exampleDir = dirname($this->envPath);
    $examplePath = $exampleDir.DIRECTORY_SEPARATOR.'.env.example';
    // Team member already had a custom note in here.
    file_put_contents($examplePath, "PUSHER_HOST=custom-team-placeholder\n");

    // Pre-create .env so the install doesn't fall back to copying .env.example
    // (which would inadvertently bring the custom PUSHER_HOST in and trigger
    // an overwrite prompt). The test is about .env.example preservation, not
    // .env initialization behavior.
    file_put_contents($this->envPath, '');

    fakeSuccessfulInstall();

    try {
        test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
            ->assertSuccessful();

        $example = file_get_contents($examplePath);
        expect($example)->toContain('PUSHER_HOST=custom-team-placeholder');
        expect($example)->not->toContain('wss.vask.dev');
        // Other Vask keys still added.
        expect($example)->toContain('PUSHER_SCHEME=https');
    } finally {
        @unlink($examplePath);
    }
});

it('does not error when .env.example is missing', function (): void {
    // No .env.example next to $this->envPath — vask:install should still succeed silently.
    fakeSuccessfulInstall();

    test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true, '--skip-broadcasting-install' => true])
        ->doesntExpectOutput('Added')
        ->assertSuccessful();
});

it('warns and continues when --skip-broadcasting-install is passed without wiring', function (): void {
    fakeSuccessfulInstall();

    // Make sure broadcasting wiring is absent at the testbench base path.
    @unlink(app()->basePath().'/routes/channels.php');

    test()->artisan('vask:install', [
        '--env' => $this->envPath,
        '--no-doctor' => true,
        '--skip-broadcasting-install' => true,
    ])
        ->expectsOutputToContain('Laravel broadcasting is not wired')
        ->expectsOutputToContain('--skip-broadcasting-install given')
        ->assertSuccessful();
});

it('skips the broadcasting-install step entirely when wiring is already in place', function (): void {
    fakeSuccessfulInstall();

    // Create the wiring fixtures.
    $basePath = app()->basePath();
    @mkdir($basePath.'/routes', 0777, true);
    @mkdir($basePath.'/bootstrap', 0777, true);
    file_put_contents($basePath.'/routes/channels.php', "<?php\n");
    file_put_contents($basePath.'/bootstrap/app.php', "<?php\n->withRouting(channels: __DIR__.'/../routes/channels.php')\n");

    try {
        // No --skip-broadcasting-install — the wiring check should pass and
        // we should NOT see install:broadcasting being attempted.
        test()->artisan('vask:install', ['--env' => $this->envPath, '--no-doctor' => true])
            ->doesntExpectOutput('Running php artisan install:broadcasting')
            ->doesntExpectOutput('Laravel broadcasting is not wired')
            ->assertSuccessful();
    } finally {
        @unlink($basePath.'/routes/channels.php');
        @unlink($basePath.'/bootstrap/app.php');
    }
});

it('uses the --device-name flag as an explicit override', function (): void {
    fakeSuccessfulInstall();

    test()->artisan('vask:install', [
        '--env' => $this->envPath,
        '--no-doctor' => true,
        '--skip-broadcasting-install' => true,
        '--device-name' => 'CI: production smoketest',
    ])->assertSuccessful();

    Http::assertSent(fn ($r): bool => str_ends_with((string) $r->url(), '/oauth/device/code')
        && ($r->data()['device_name'] ?? null) === 'CI: production smoketest'
    );
});
