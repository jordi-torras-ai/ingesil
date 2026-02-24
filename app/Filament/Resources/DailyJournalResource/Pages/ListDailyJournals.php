<?php

namespace App\Filament\Resources\DailyJournalResource\Pages;

use App\Filament\Resources\DailyJournalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDailyJournals extends ListRecords
{
    protected static string $resource = DailyJournalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
