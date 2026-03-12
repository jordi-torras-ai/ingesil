<?php

namespace App\Filament\Resources\FeatureResource\Pages;

use App\Filament\Resources\FeatureResource;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Actions;

class EditFeature extends EditRecord
{
    protected static string $resource = FeatureResource::class;

    protected array $translationPayload = [];

    protected array $optionsPayload = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['translations'] = FeatureResource::extractTranslations($this->record);
        $data['options_payload'] = FeatureResource::extractOptions($this->record);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->translationPayload = $data['translations'] ?? [];
        $this->optionsPayload = $data['options_payload'] ?? [];

        return FeatureResource::mutateDataBeforeSave($data);
    }

    protected function afterSave(): void
    {
        FeatureResource::syncTranslations($this->record, $this->translationPayload);
        FeatureResource::syncOptions($this->record, $this->record->data_type === 'single_choice' ? $this->optionsPayload : []);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
