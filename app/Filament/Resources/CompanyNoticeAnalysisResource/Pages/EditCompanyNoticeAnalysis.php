<?php

namespace App\Filament\Resources\CompanyNoticeAnalysisResource\Pages;

use App\Filament\Resources\CompanyNoticeAnalysisResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCompanyNoticeAnalysis extends EditRecord
{
    protected static string $resource = CompanyNoticeAnalysisResource::class;

    protected function getRedirectUrl(): string
    {
        return CompanyNoticeAnalysisResource::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }
}
