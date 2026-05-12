<?php

declare(strict_types=1);

namespace Vask\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vask\Laravel\Commands\DoctorCommand;
use Vask\Laravel\Commands\InstallCommand;
use Vask\Laravel\Http\Controllers\VaskDemoController;

class VaskServiceProvider extends PackageServiceProvider
{
    public const DEMO_PATH = '/_vask/demo';

    public const DEMO_ROUTE_NAME = 'vask.demo';

    public const DEMO_BROADCAST_ROUTE_NAME = 'vask.demo.broadcast';

    public const DEMO_AUTH_ROUTE_NAME = 'vask.demo.auth';

    /**
     * Register the demo routes unconditionally. Public so tests can drive
     * route registration without needing to flip the app environment.
     */
    public static function registerDemoRoutes(): void
    {
        Route::get(self::DEMO_PATH, [VaskDemoController::class, 'show'])
            ->name(self::DEMO_ROUTE_NAME);

        Route::post(self::DEMO_PATH.'/broadcast', [VaskDemoController::class, 'broadcast'])
            ->name(self::DEMO_BROADCAST_ROUTE_NAME);

        Route::post(self::DEMO_PATH.'/auth', [VaskDemoController::class, 'auth'])
            ->name(self::DEMO_AUTH_ROUTE_NAME);

        Route::getRoutes()->refreshNameLookups();
    }

    public static function demoDisabledByEnv(): bool
    {
        // Use Env::get() rather than env() so we can read at runtime without
        // tripping larastan's "no env() outside config" rule. The flag is for
        // a local-only feature, so config caching isn't a concern here.
        $value = Env::get('VASK_NO_DEMO');

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(mb_strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('vask')
            ->hasViews()
            ->hasCommands([
                DoctorCommand::class,
                InstallCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Vask::class);
    }

    public function packageBooted(): void
    {
        // Defer route registration until every other service provider has
        // booted, so handlers registered in the user's AppServiceProvider
        // (which boots after this one) are visible when we decide whether
        // to register the webhook route.
        $this->app->booted(function (): void {
            $this->app->make(Vask::class)->registerWebhookRouteIfNeeded();
            $this->registerDemoRoutesIfEnabled($this->app);
        });
    }

    /**
     * Register the local-only demo routes when:
     *   - app is in the 'local' environment, AND
     *   - the VASK_NO_DEMO env var is not truthy.
     *
     * Reads env() directly (no publishable config file) — see README.
     */
    protected function registerDemoRoutesIfEnabled(Application $app): void
    {
        if (! $app->environment('local')) {
            return;
        }

        if (self::demoDisabledByEnv()) {
            return;
        }

        self::registerDemoRoutes();
    }
}
