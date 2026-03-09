<?php

namespace App\Notifications\Auth;

use Filament\Notifications\Auth\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

class ResetPassword extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $locale = method_exists($notifiable, 'preferredLocaleForNotifications')
            ? $notifiable->preferredLocaleForNotifications()
            : app()->getLocale();

        return (new MailMessage)
            ->subject(__('app.password_reset.email.subject', locale: $locale))
            ->line(__('app.password_reset.email.intro', locale: $locale))
            ->action(__('app.password_reset.email.action', locale: $locale), $this->resetUrl($notifiable))
            ->line(__('app.password_reset.email.expiration', [
                'count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ], locale: $locale))
            ->line(__('app.password_reset.email.outro', locale: $locale))
            ->salutation(Lang::get('app.password_reset.email.salutation', locale: $locale));
    }
}
