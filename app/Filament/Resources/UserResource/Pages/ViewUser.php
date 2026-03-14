<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\Auth\PasswordResetLinkSender;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('force_reset_password')
                ->label(__('app.users.actions.force_reset_password'))
                ->icon('heroicon-o-key')
                ->visible(fn (): bool => static::getResource()::canEdit($this->record))
                ->requiresConfirmation()
                ->action(function (PasswordResetLinkSender $sender): void {
                    $sender->invalidateAndSend($this->record);

                    Notification::make()
                        ->success()
                        ->title(__('app.users.actions.force_reset_password_success_title'))
                        ->body(__('app.users.actions.force_reset_password_success_body', [
                            'email' => $this->record->email,
                        ]))
                        ->send();
                }),
            Actions\EditAction::make()
                ->visible(fn (): bool => static::getResource()::canEdit($this->record)),
        ];
    }
}
