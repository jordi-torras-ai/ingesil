<?php

namespace App\Filament\Resources\NoticeAnalysisResource\Pages;

use App\Filament\Resources\NoticeAnalysisResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewNoticeAnalysis extends ViewRecord
{
    protected static string $resource = NoticeAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
