<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\TwoFactorPage;
use App\Filament\Pages\LanguagePage;
use App\Filament\Pages\MyProfilePage;
use App\Livewire\Profile\TwoFactorAuthentication;
use App\Livewire\Profile\UpdatePassword;
use App\Support\BreezyTranslation;
use App\Filament\Widgets\AnalysisHealthStats;
use App\Filament\Widgets\AnalysisOutcomeChart;
use App\Filament\Widgets\DailyPublicationActivityChart;
use App\Filament\Widgets\LibraryOverviewStats;
use App\Filament\Widgets\RecentNoticesTable;
use App\Http\Middleware\SetUserLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Jeffgreco13\FilamentBreezy\BreezyCore;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(
                BreezyCore::make()
                    ->myProfile(
                        shouldRegisterUserMenu: false,
                        userMenuLabel: BreezyTranslation::get('profile.profile'),
                    )
                    ->customMyProfilePage(MyProfilePage::class)
                    ->myProfileComponents([
                        'personal_info' => \App\Livewire\Profile\PersonalInfo::class,
                        'update_password' => UpdatePassword::class,
                        'two_factor_authentication' => TwoFactorAuthentication::class,
                    ])
                    ->passwordUpdateRules(
                        rules: [Password::default()],
                        requiresCurrentPassword: true,
                    )
                    ->enableTwoFactorAuthentication(
                        force: true,
                        action: TwoFactorPage::class,
                    )
            )
            ->login()
            ->passwordReset(RequestPasswordReset::class)
            ->userMenuItems([
                'account' => MenuItem::make()
                    ->label(fn (): string => BreezyTranslation::get('profile.profile'))
                    ->url(fn (): string => MyProfilePage::getUrl()),
                'language' => MenuItem::make()
                    ->label(fn (): string => __('app.language_page.navigation'))
                    ->icon('heroicon-o-language')
                    ->url(fn (): string => LanguagePage::getUrl()),
            ])
            ->brandName('Ingesil')
            ->brandLogo(asset('images/branding/ingesil-logo-horizontal-light.svg'))
            ->darkModeBrandLogo(asset('images/branding/ingesil-logo-horizontal-dark.svg'))
            ->brandLogoHeight('3.6rem')
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn (): string => view('filament.components.panel-footer')->render(),
            )
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->maxContentWidth(MaxWidth::Full)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                LibraryOverviewStats::class,
                AnalysisHealthStats::class,
                DailyPublicationActivityChart::class,
                AnalysisOutcomeChart::class,
                RecentNoticesTable::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                SetUserLocale::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SetUserLocale::class,
            ]);
    }
}
