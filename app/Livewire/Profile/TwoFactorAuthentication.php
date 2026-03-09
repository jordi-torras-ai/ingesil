<?php

namespace App\Livewire\Profile;

use App\Support\BreezyTranslation;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Jeffgreco13\FilamentBreezy\Actions\PasswordButtonAction;
use Jeffgreco13\FilamentBreezy\Livewire\TwoFactorAuthentication as BreezyTwoFactorAuthentication;

class TwoFactorAuthentication extends BreezyTwoFactorAuthentication
{
    protected string $view = 'filament-breezy::livewire.two-factor-authentication';

    public function showRequiresTwoFactorAlert()
    {
        return (bool) filament('filament-breezy')->getForceTwoFactorAuthentication() && ! $this->user->hasConfirmedTwoFactor();
    }

    public function shouldAllowDisable(): bool
    {
        return ! ((bool) filament('filament-breezy')->getForceTwoFactorAuthentication());
    }

    public function enableAction(): Action
    {
        return PasswordButtonAction::make('enable')
            ->label(BreezyTranslation::get('profile.2fa.actions.enable'))
            ->action(function () {
                $this->user->enableTwoFactorAuthentication();

                Notification::make()
                    ->success()
                    ->title(BreezyTranslation::get('profile.2fa.enabled.notify'))
                    ->send();
            });
    }

    public function disableAction(): Action
    {
        return PasswordButtonAction::make('disable')
            ->label(BreezyTranslation::get('profile.2fa.actions.disable'))
            ->color('primary')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->shouldAllowDisable())
            ->action(function () {
                $this->user->disableTwoFactorAuthentication();

                Notification::make()
                    ->warning()
                    ->title(BreezyTranslation::get('profile.2fa.disabling.notify'))
                    ->send();
            });
    }

    public function confirmAction(): Action
    {
        return Action::make('confirm')
            ->color('success')
            ->label(BreezyTranslation::get('profile.2fa.actions.confirm_finish'))
            ->modalWidth('sm')
            ->form([
                Forms\Components\TextInput::make('code')
                    ->label(BreezyTranslation::get('fields.2fa_code'))
                    ->placeholder('###-###')
                    ->required(),
            ])
            ->action(function ($data, $action, $livewire) {
                if (! filament('filament-breezy')->verify(code: $data['code'])) {
                    $livewire->addError('mountedActionsData.0.code', BreezyTranslation::get('profile.2fa.confirmation.invalid_code'));
                    $action->halt();
                }

                $this->user->confirmTwoFactorAuthentication();
                $this->user->setTwoFactorSession();

                Notification::make()
                    ->success()
                    ->title(BreezyTranslation::get('profile.2fa.confirmation.success_notification'))
                    ->send();
            });
    }

    public function regenerateCodesAction(): Action
    {
        return PasswordButtonAction::make('regenerateCodes')
            ->label(BreezyTranslation::get('profile.2fa.actions.regenerate_codes'))
            ->requiresConfirmation()
            ->action(function () {
                $this->user->reGenerateRecoveryCodes();
                $this->showRecoveryCodes = true;

                Notification::make()
                    ->success()
                    ->title(BreezyTranslation::get('profile.2fa.regenerate_codes.notify'))
                    ->send();
            });
    }
}
