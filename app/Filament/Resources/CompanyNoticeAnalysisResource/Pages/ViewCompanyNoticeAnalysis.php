<?php

namespace App\Filament\Resources\CompanyNoticeAnalysisResource\Pages;

use App\Filament\Resources\CompanyNoticeAnalysisResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCompanyNoticeAnalysis extends ViewRecord
{
    protected static string $resource = CompanyNoticeAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
