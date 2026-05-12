<?php

declare(strict_types=1);

use Vask\Laravel\Support\BroadcastingDetector;

beforeEach(function (): void {
    $this->basePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vask-bcast-'.bin2hex(random_bytes(4));
    mkdir($this->basePath.'/routes', 0777, true);
    mkdir($this->basePath.'/bootstrap', 0777, true);
    mkdir($this->basePath.'/config', 0777, true);
});

afterEach(function (): void {
    // Recursive cleanup.
    if (! is_dir($this->basePath)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }

    rmdir($this->basePath);
});

it('returns false when routes/channels.php is missing', function (): void {
    expect(BroadcastingDetector::isWired($this->basePath))->toBeFalse();
});

it('returns false on Laravel 11+ when channels.php exists but bootstrap/app.php has no channels wiring', function (): void {
    file_put_contents($this->basePath.'/routes/channels.php', "<?php\n");
    file_put_contents($this->basePath.'/bootstrap/app.php', "<?php\nreturn Application::configure(basePath: dirname(__DIR__))\n    ->withRouting(web: __DIR__.'/../routes/web.php')\n    ->create();\n");

    expect(BroadcastingDetector::isWired($this->basePath))->toBeFalse();
});

it('returns true on Laravel 11+ when bootstrap/app.php references channels.php via withRouting', function (): void {
    file_put_contents($this->basePath.'/routes/channels.php', "<?php\n");
    file_put_contents($this->basePath.'/bootstrap/app.php', "<?php\n->withRouting(\n    web: __DIR__.'/../routes/web.php',\n    channels: __DIR__.'/../routes/channels.php',\n)\n");

    expect(BroadcastingDetector::isWired($this->basePath))->toBeTrue();
});

it('returns true on Laravel 11+ when bootstrap/app.php uses ->withBroadcasting(...)', function (): void {
    file_put_contents($this->basePath.'/routes/channels.php', "<?php\n");
    file_put_contents($this->basePath.'/bootstrap/app.php', "<?php\n->withBroadcasting(channels: __DIR__.'/../routes/channels.php')\n");

    expect(BroadcastingDetector::isWired($this->basePath))->toBeTrue();
});

it('returns true on Laravel 10 when BroadcastServiceProvider is uncommented in config/app.php', function (): void {
    file_put_contents($this->basePath.'/routes/channels.php', "<?php\n");
    // No bootstrap/app.php (simulating Laravel 10).
    rmdir($this->basePath.'/bootstrap');
    file_put_contents($this->basePath.'/config/app.php', "<?php\nreturn ['providers' => [\n    App\\Providers\\BroadcastServiceProvider::class,\n]];\n");

    expect(BroadcastingDetector::isWired($this->basePath))->toBeTrue();
});

it('returns false on Laravel 10 when BroadcastServiceProvider is commented out', function (): void {
    file_put_contents($this->basePath.'/routes/channels.php', "<?php\n");
    rmdir($this->basePath.'/bootstrap');
    file_put_contents($this->basePath.'/config/app.php', "<?php\nreturn ['providers' => [\n    // App\\Providers\\BroadcastServiceProvider::class,\n]];\n");

    expect(BroadcastingDetector::isWired($this->basePath))->toBeFalse();
});
