<?php

namespace App\Providers;

use App\Chat\BroadcastChatPublisher;
use App\Chat\ChatPublisher;
use App\Mcp\Support\MemberRoles;
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
        $this->app->scoped(MemberRoles::class);

        // The assistant's streaming publications: tests swap a recording
        // fake, production goes through the Mercure broadcaster.
        $this->app->singleton(ChatPublisher::class, BroadcastChatPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Status::observe(StatusObserver::class);
    }
}
