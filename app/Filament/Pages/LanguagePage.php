<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\App;

class LanguagePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static string $view = 'filament.pages.language';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function getSlug(): string
    {
        return 'language';
    }

    public static function getNavigationLabel(): string
    {
        return __('app.language_page.navigation');
    }

    public function getTitle(): string
    {
        return __('app.language_page.title');
    }

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 403);

        $locale = in_array($user->locale, User::supportedLocales(), true)
            ? $user->locale
            : config('app.locale', User::LOCALE_EN);

        session(['locale' => $locale]);
        App::setLocale($locale);

        $this->form->fill([
            'locale' => $locale,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make(__('app.language_page.sections.preferences'))
                    ->description(__('app.language_page.sections.preferences_description'))
                    ->schema([
                        Forms\Components\Radio::make('locale')
                            ->label(__('app.users.fields.locale'))
                            ->required()
                            ->options(User::localeOptions())
                            ->inline()
                            ->inlineLabel(false),
                    ]),
            ]);
    }

    public function save(): void
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 403);

        $locale = (string) ($this->form->getState()['locale'] ?? '');

        if (! in_array($locale, User::supportedLocales(), true)) {
            return;
        }

        $user->forceFill([
            'locale' => $locale,
        ])->save();

        $user->refresh();

        session(['locale' => $locale]);
        App::setLocale($locale);
        Filament::auth()->setUser($user);

        Notification::make()
            ->success()
            ->title(__('app.language_page.messages.saved'))
            ->send();

        $this->redirect(static::getUrl());
    }
}
