<?php

declare(strict_types=1);

namespace Vask\Laravel\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds the User-Agent string sent on every outbound HTTP call this
 * package makes — the device-flow exchanges during `vask:install`, and
 * (when wired up by the service provider) the Pusher API calls every
 * `broadcast()` triggers at runtime.
 *
 * Format: "{app-name-slug}/{env} vask-laravel/{version}"
 *   e.g.  "vask-web/local vask-laravel/0.0.12"
 *
 * The slug lets the server identify which Laravel app a request is
 * coming from at a glance; the package tag lets the server distinguish
 * vask-laravel traffic from generic Pusher SDK traffic.
 */
class UserAgent
{
    public const PACKAGE_NAME = 'vask/laravel';

    public const FALLBACK_APP_SLUG = 'laravel';

    public const FALLBACK_ENVIRONMENT = 'production';

    public const LARAVEL_DEFAULT_APP_NAME = 'Laravel';

    public static function build(): string
    {
        $slug = self::appSlug();
        $env = self::environment();
        $version = self::packageVersion();

        $ua = $slug.'/'.$env.' vask-laravel';

        if ($version !== null) {
            $ua .= '/'.$version;
        }

        return $ua;
    }

    protected static function appSlug(): string
    {
        $rawName = config('app.name');

        if (is_string($rawName)
            && mb_trim($rawName) !== ''
            && $rawName !== self::LARAVEL_DEFAULT_APP_NAME
        ) {
            $slug = Str::slug($rawName);
            if ($slug !== '') {
                return $slug;
            }
        }

        // APP_NAME is still the Laravel default (or missing) — fall back to
        // the project folder name. Most apps never customise APP_NAME, so
        // basename(base_path()) is far more identifying for server-side
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

    protected static function packageVersion(): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
        } catch (Throwable) {
            return null;
        }

        return is_string($version) && $version !== '' ? $version : null;
    }
}
