<?php

declare(strict_types=1);

namespace Vask\Laravel;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vask\Laravel\Commands\DoctorCommand;
use Vask\Laravel\Commands\InstallCommand;

class VaskServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('vask')
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
        });
    }
}
