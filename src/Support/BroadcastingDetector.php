<?php

declare(strict_types=1);

namespace Vask\Laravel\Support;

class BroadcastingDetector
{
    /**
     * Return true if Laravel broadcasting is wired up enough for private and
     * presence channels to authorize correctly:
     *
     * - `routes/channels.php` exists, AND
     * - Laravel 11+ — `bootstrap/app.php` references the channels file
     *   (via `withRouting(channels: ...)` or `->withBroadcasting(...)`), OR
     * - Laravel 10 — `App\Providers\BroadcastServiceProvider::class` is
     *   uncommented in `config/app.php`.
     *
     * Returns false if the file/wiring is missing — meaning the user has not
     * run `php artisan install:broadcasting` (or done the equivalent by hand).
     */
    public static function isWired(string $basePath): bool
    {
        $channelsPath = $basePath.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'channels.php';

        if (! file_exists($channelsPath)) {
            return false;
        }

        $bootstrapPath = $basePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';

        if (file_exists($bootstrapPath)) {
            $bootstrap = (string) file_get_contents($bootstrapPath);

            // Laravel 11+ has bootstrap/app.php. Either the channels: arg is
            // present in withRouting, or the legacy withBroadcasting() call.
            // bootstrap/app.php exists but no channels wiring — not set up.
            return str_contains($bootstrap, "channels: __DIR__.'/../routes/channels.php'")
                || str_contains($bootstrap, '->withBroadcasting(');
        }

        // Laravel 10 fallback: look for BroadcastServiceProvider in config/app.php.
        $appConfigPath = $basePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
        if (file_exists($appConfigPath)) {
            $appConfig = (string) file_get_contents($appConfigPath);
            // Must be uncommented (`//` would mean disabled).
            if (preg_match('/^\s*App\\\\Providers\\\\BroadcastServiceProvider::class/m', $appConfig) === 1) {
                return true;
            }
        }

        return false;
    }
}
