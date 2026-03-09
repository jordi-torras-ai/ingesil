<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\BreezyTranslation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Jeffgreco13\FilamentBreezy\Pages\MyProfilePage as BaseMyProfilePage;

class MyProfilePage extends BaseMyProfilePage
{
    public function mount(): void
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && in_array($user->locale, User::supportedLocales(), true)) {
            session(['locale' => $user->locale]);
            App::setLocale($user->locale);
        }
    }

    public function getTitle(): string
    {
        return BreezyTranslation::get('profile.my_profile');
    }

    public function getHeading(): string
    {
        return BreezyTranslation::get('profile.my_profile');
    }

    public function getSubheading(): ?string
    {
        return BreezyTranslation::get('profile.subheading');
    }

    public static function getNavigationLabel(): string
    {
        return BreezyTranslation::get('profile.profile');
    }
}
