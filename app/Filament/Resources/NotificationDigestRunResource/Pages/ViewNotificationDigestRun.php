<?php

namespace App\Filament\Resources\NotificationDigestRunResource\Pages;

use App\Filament\Resources\NotificationDigestRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewNotificationDigestRun extends ViewRecord
{
    protected static string $resource = NotificationDigestRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('app.notice_digests.actions.back'))
                ->url(NotificationDigestRunResource::getUrl('index')),
        ];
    }
}
