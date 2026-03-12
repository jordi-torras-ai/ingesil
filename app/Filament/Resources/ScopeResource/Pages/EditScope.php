<?php

namespace App\Filament\Resources\ScopeResource\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\ScopeResource;
use Filament\Actions;

class EditScope extends EditRecord
{
    protected static string $resource = ScopeResource::class;

    protected array $translationPayload = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['translations'] = ScopeResource::extractTranslations($this->record);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->translationPayload = $data['translations'] ?? [];

        return ScopeResource::mutateDataBeforeSave($data);
    }

    protected function afterSave(): void
    {
        ScopeResource::syncTranslations($this->record, $this->translationPayload);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
