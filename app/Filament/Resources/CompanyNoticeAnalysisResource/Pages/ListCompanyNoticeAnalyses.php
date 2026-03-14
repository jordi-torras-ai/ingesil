<?php

namespace App\Filament\Resources\CompanyNoticeAnalysisResource\Pages;

use App\Filament\Resources\CompanyNoticeAnalysisResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCompanyNoticeAnalyses extends ListRecords
{
    protected static string $resource = CompanyNoticeAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pending_review')
                ->label(__('app.company_notice_analyses.actions.pending_review'))
                ->icon('heroicon-o-clipboard-document-list')
                ->color('warning')
                ->url(CompanyNoticeAnalysisResource::pendingReviewUrl()),
        ];
    }
}
