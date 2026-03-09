<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\Auth\PasswordResetLinkSender;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;

class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword $user, string $token): void {
                if (! $user instanceof User) {
                    return;
                }

                if (! $user->canAccessPanel(Filament::getCurrentPanel())) {
                    return;
                }

                app(PasswordResetLinkSender::class)->send($user, $token);
            },
        );

        if ($status !== Password::RESET_LINK_SENT) {
            $this->getFailureNotification($status)?->send();

            return;
        }

        $this->getSentNotification($status)?->send();

        $this->form->fill();
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return Notification::make()
            ->title(__('app.password_reset.notifications.sent.title'))
            ->body(__('app.password_reset.notifications.sent.body'))
            ->success();
    }
}
