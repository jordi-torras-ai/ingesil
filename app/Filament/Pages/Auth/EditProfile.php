<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rules\Password;

class EditProfile extends BaseEditProfile
{
    protected function getLocaleFormComponent(): Component
    {
        return Select::make('locale')
            ->label(__('app.users.fields.locale'))
            ->required()
            ->native(false)
            ->options(User::localeOptions())
            ->in(User::supportedLocales());
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('current_password')
            ->label(__('app.users.fields.current_password'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->rule('nullable')
            ->rule('required_with:password')
            ->currentPassword()
            ->dehydrated(false);
    }

    protected function getNewPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('app.users.fields.new_password'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->rule(Password::default())
            ->autocomplete('new-password')
            ->dehydrated(fn ($state): bool => filled($state))
            ->dehydrateStateUsing(fn ($state): string => bcrypt($state))
            ->live(debounce: 500)
            ->same('passwordConfirmation');
    }

    protected function getNewPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label(__('app.users.fields.new_password_confirmation'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->visible(fn (Get $get): bool => filled($get('password')))
            ->dehydrated(false);
    }

    /**
     * @return array<int | string, string | Form>
     */
    protected function getForms(): array
    {
        $user = $this->getUser();

        $schema = [
            $this->getLocaleFormComponent(),
            $this->getCurrentPasswordFormComponent(),
            $this->getNewPasswordFormComponent(),
            $this->getNewPasswordConfirmationFormComponent(),
        ];

        if ($user->isAdmin()) {
            $schema = [
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getLocaleFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                $this->getNewPasswordFormComponent(),
                $this->getNewPasswordConfirmationFormComponent(),
            ];
        }

        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema($schema)
                    ->operation('edit')
                    ->model($user)
                    ->statePath('data')
                    ->inlineLabel(! static::isSimple()),
            ),
        ];
    }

    protected function afterSave(): void
    {
        $locale = $this->data['locale'] ?? null;

        if (in_array($locale, User::supportedLocales(), true)) {
            session(['locale' => $locale]);
            App::setLocale($locale);
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['current_password']);

        return $data;
    }
}
