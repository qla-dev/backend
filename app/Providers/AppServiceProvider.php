<?php

namespace App\Providers;

use App\Services\AisVesselStreamClient;
use App\Services\Contracts\VesselSnapshotClient;
use App\Services\Contracts\VesselStreamClient;
use App\Services\OpenWatersVesselClient;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VesselStreamClient::class, AisVesselStreamClient::class);
        $this->app->bind(VesselSnapshotClient::class, OpenWatersVesselClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
    }
}
