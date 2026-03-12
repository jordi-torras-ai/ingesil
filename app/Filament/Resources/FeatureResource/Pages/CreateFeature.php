<?php

namespace App\Filament\Resources\FeatureResource\Pages;

use App\Filament\Resources\FeatureResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateFeature extends CreateRecord
{
    protected static string $resource = FeatureResource::class;

    protected array $translationPayload = [];

    protected array $optionsPayload = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->translationPayload = $data['translations'] ?? [];
        $this->optionsPayload = $data['options_payload'] ?? [];

        return FeatureResource::mutateDataBeforeSave($data);
    }

    protected function afterCreate(): void
    {
        FeatureResource::syncTranslations($this->record, $this->translationPayload);
        FeatureResource::syncOptions($this->record, $this->record->data_type === 'single_choice' ? $this->optionsPayload : []);
    }
}
