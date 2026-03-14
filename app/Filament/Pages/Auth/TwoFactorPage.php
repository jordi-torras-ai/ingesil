<?php

namespace App\Filament\Pages\Auth;

use App\Support\BreezyTranslation;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Jeffgreco13\FilamentBreezy\Pages\TwoFactorPage as BaseTwoFactorPage;

class TwoFactorPage extends BaseTwoFactorPage
{
    protected static string $view = 'filament.pages.auth.two-factor';

    public function getTitle(): string
    {
        return BreezyTranslation::get('two_factor.heading');
    }

    public function getSubheading(): string
    {
        return BreezyTranslation::get('two_factor.description');
    }

    public function logoutAndReset(): void
    {
        Filament::auth()->logout();

        $this->redirect(Filament::getLoginUrl(), navigate: true);
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()
            ->label(__('app.auth.confirm_sign_in'));
    }

    public function authenticate()
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->addError('code', __('filament::login.messages.throttled', [
                'seconds' => $exception->secondsUntilAvailable,
                'minutes' => ceil($exception->secondsUntilAvailable / 60),
            ]));

            return null;
        }

        if (! $this->hasValidCode()) {
            $this->addError('code', BreezyTranslation::get('profile.2fa.confirmation.invalid_code'));

            return null;
        }

        if ($this->usingRecoveryCode) {
            filament('filament-breezy')->auth()->user()->destroyRecoveryCode($this->code);
        }

        filament('filament-breezy')->auth()->user()->setTwoFactorSession();

        return redirect()->to($this->next ?? Filament::getHomeUrl());
    }
}
