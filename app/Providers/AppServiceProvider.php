<?php

namespace App\Providers;

use App\Models\Notice;
use App\Observers\NoticeObserver;
use App\Notifications\Auth\ResetPassword as LocalizedResetPassword;
use App\Livewire\Profile\PersonalInfo as ProfilePersonalInfo;
use App\Livewire\Profile\TwoFactorAuthentication as ProfileTwoFactorAuthentication;
use App\Livewire\Profile\UpdatePassword as ProfileUpdatePassword;
use Filament\Notifications\Auth\ResetPassword as FilamentResetPassword;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FilamentResetPassword::class, LocalizedResetPassword::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(
            base_path('vendor/jeffgreco13/filament-breezy/resources/lang'),
            'filament-breezy',
        );

        app('translator')->addNamespace(
            'filament_breezy',
            base_path('vendor/jeffgreco13/filament-breezy/resources/lang'),
        );

        Livewire::component('personal_info', ProfilePersonalInfo::class);
        Livewire::component('update_password', ProfileUpdatePassword::class);
        Livewire::component('two_factor_authentication', ProfileTwoFactorAuthentication::class);

        Notice::observe(NoticeObserver::class);
    }
}
