<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\UserResource;
use App\Services\Auth\PasswordResetLinkSender;
use Filament\Actions;
use Filament\Notifications\Notification;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('force_reset_password')
                ->label(__('app.users.actions.force_reset_password'))
                ->icon('heroicon-o-key')
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
            Actions\DeleteAction::make(),
        ];
    }
}
