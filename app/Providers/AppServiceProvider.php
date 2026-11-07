<?php

namespace App\Providers;

use App\Models\Status;
use App\Observers\StatusObserver;
use App\Support\WorkspaceContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkspaceContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Status::observe(StatusObserver::class);
    }
}
