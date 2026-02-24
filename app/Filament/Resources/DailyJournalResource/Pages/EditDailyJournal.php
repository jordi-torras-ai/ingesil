<?php

namespace App\Filament\Resources\DailyJournalResource\Pages;

use App\Filament\Resources\DailyJournalResource;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Actions;

class EditDailyJournal extends EditRecord
{
    protected static string $resource = DailyJournalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
