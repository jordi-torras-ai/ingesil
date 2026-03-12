<?php

namespace App\Filament\Resources\ScopeResource\Pages;

use App\Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\ScopeResource;

class CreateScope extends CreateRecord
{
    protected static string $resource = ScopeResource::class;

    protected array $translationPayload = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->translationPayload = $data['translations'] ?? [];

        return ScopeResource::mutateDataBeforeSave($data);
    }

    protected function afterCreate(): void
    {
        ScopeResource::syncTranslations($this->record, $this->translationPayload);
    }
}
