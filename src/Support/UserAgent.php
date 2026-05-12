<?php

declare(strict_types=1);

namespace Vask\Laravel\Support;

use Illuminate\Support\Str;

/**
 * Builds the User-Agent string sent on every outbound HTTP call this
 * package makes: the device-flow exchanges during `vask:install`, and
 * (when wired up by the service provider) the Pusher API calls every
 * `broadcast()` triggers at runtime.
 *
 * Format: "{app-name-slug}/{env}", e.g. "vask-web/local".
 *
 * Kept deliberately minimal so operators can identify which calling
 * app is talking to the server at a glance, without noisy package
 * metadata.
 */
class UserAgent
{
    public const FALLBACK_APP_SLUG = 'laravel';

    public const FALLBACK_ENVIRONMENT = 'production';

    public const LARAVEL_DEFAULT_APP_NAME = 'Laravel';

    public static function build(): string
    {
        return self::appSlug().'/'.self::environment();
    }

    protected static function appSlug(): string
    {
        $rawName = config('app.name');

        if (is_string($rawName)
            && trim($rawName) !== ''
            && $rawName !== self::LARAVEL_DEFAULT_APP_NAME
        ) {
            $slug = Str::slug($rawName);
            if ($slug !== '') {
                return $slug;
            }
        }

        // APP_NAME is still the Laravel default (or missing) so fall back
        // to the project folder name. Most apps never customise APP_NAME,
        // so basename(base_path()) is far more identifying for server-side
        // observability than the literal string "laravel".
        $folderSlug = Str::slug(basename(base_path()));

        return $folderSlug === '' ? self::FALLBACK_APP_SLUG : $folderSlug;
    }

    protected static function environment(): string
    {
        $env = app()->environment();

        if ($env === '') {
            return self::FALLBACK_ENVIRONMENT;
        }

        // Slugify too so an env like "Production CI" doesn't break the UA.
        $slug = Str::slug($env);

        return $slug === '' ? self::FALLBACK_ENVIRONMENT : $slug;
    }
}
