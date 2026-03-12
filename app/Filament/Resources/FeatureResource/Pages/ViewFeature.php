<?php

namespace App\Filament\Resources\FeatureResource\Pages;

use App\Filament\Resources\FeatureResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFeature extends ViewRecord
{
    protected static string $resource = FeatureResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['translations'] = FeatureResource::extractTranslations($this->record);
        $data['options_payload'] = FeatureResource::extractOptions($this->record);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
