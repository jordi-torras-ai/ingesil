<?php

namespace App\Filament\Resources\ScopeResource\Pages;

use App\Filament\Resources\ScopeResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewScope extends ViewRecord
{
    protected static string $resource = ScopeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['translations'] = ScopeResource::extractTranslations($this->record);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
