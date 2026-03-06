<?php

namespace App\Providers;

use App\Models\Notice;
use App\Observers\NoticeObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Notice::observe(NoticeObserver::class);
    }
}
