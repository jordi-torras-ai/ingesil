<?php

namespace App\Filament\Pages\Auth;

use App\Support\BreezyTranslation;
use Filament\Forms;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Jeffgreco13\FilamentBreezy\Pages\TwoFactorPage as BaseTwoFactorPage;

class TwoFactorPage extends BaseTwoFactorPage
{
    public function getTitle(): string
    {
        return BreezyTranslation::get('two_factor.heading');
    }

    public function getSubheading(): string
    {
        return BreezyTranslation::get('two_factor.description');
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('code')
                ->label($this->usingRecoveryCode ? BreezyTranslation::get('fields.2fa_recovery_code') : BreezyTranslation::get('fields.2fa_code'))
                ->placeholder($this->usingRecoveryCode ? BreezyTranslation::get('two_factor.recovery_code_placeholder') : BreezyTranslation::get('two_factor.code_placeholder'))
                ->hint(new HtmlString(Blade::render('
                    <x-filament::link href="#" wire:click="toggleRecoveryCode()">'.($this->usingRecoveryCode ? \App\Support\BreezyTranslation::get('cancel') : \App\Support\BreezyTranslation::get('two_factor.recovery_code_link')).'
                    </x-filament::link>')))
                ->required()
                ->extraInputAttributes(['class' => 'text-center', 'autocomplete' => $this->usingRecoveryCode ? 'off' : 'one-time-code'])
                ->autofocus()
                ->suffixAction(
                    Forms\Components\Actions\Action::make('cancel')
                        ->toolTip(BreezyTranslation::get('cancel'))
                        ->icon('heroicon-o-x-circle')
                        ->action(function () {
                            \Filament\Facades\Filament::auth()->logout();
                            $this->mount();
                        })
                ),
        ];
    }

    public function authenticate()
    {
        if (! $this->hasValidCode()) {
            $this->addError('code', BreezyTranslation::get('profile.2fa.confirmation.invalid_code'));

            return null;
        }

        return parent::authenticate();
    }
}
