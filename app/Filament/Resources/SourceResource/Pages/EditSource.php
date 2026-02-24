<?php

namespace App\Filament\Resources\SourceResource\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\SourceResource;
use Filament\Actions;

class EditSource extends EditRecord
{
    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
