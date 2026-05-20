<?php

namespace App\Providers;

use App\Services\VieFund\Contracts\VieFundRemoteRepositoryInterface;
use App\Services\VieFund\Repositories\SqlServerVieFundRemoteRepository;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(VieFundRemoteRepositoryInterface::class, SqlServerVieFundRemoteRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
