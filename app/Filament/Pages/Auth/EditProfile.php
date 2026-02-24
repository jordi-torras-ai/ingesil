<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Support\Facades\App;

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

    /**
     * @return array<int | string, string | Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getLocaleFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->operation('edit')
                    ->model($this->getUser())
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
}
