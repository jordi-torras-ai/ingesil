<?php

namespace App\Services\Auth;

use App\Notifications\Auth\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordFacade;
use Illuminate\Support\Str;

class PasswordResetLinkSender
{
    /**
     * @param  CanResetPassword&Model  $user
     */
    public function send(CanResetPassword $user, ?string $token = null): void
    {
        if (! method_exists($user, 'notify')) {
            $userClass = $user::class;

            throw new \RuntimeException("Model [{$userClass}] does not have a [notify()] method.");
        }

        $token ??= PasswordFacade::broker(Filament::getAuthPasswordBroker())->createToken($user);

        $notification = app(ResetPassword::class, ['token' => $token]);
        $notification->url = Filament::getResetPasswordUrl($token, $user);
        $notification->locale(method_exists($user, 'preferredLocaleForNotifications') ? $user->preferredLocaleForNotifications() : app()->getLocale());

        $user->notify($notification);

        if (class_exists(PasswordResetLinkSent::class)) {
            event(new PasswordResetLinkSent($user));
        }
    }

    /**
     * @param  CanResetPassword&Model  $user
     */
    public function invalidateAndSend(CanResetPassword $user): void
    {
        $user->forceFill([
            'password' => Hash::make(Str::password(40)),
            'remember_token' => Str::random(60),
        ])->save();

        $this->send($user);
    }
}
