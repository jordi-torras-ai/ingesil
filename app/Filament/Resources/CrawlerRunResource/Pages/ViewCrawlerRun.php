<?php

namespace App\Filament\Resources\CrawlerRunResource\Pages;

use App\Filament\Resources\CrawlerRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCrawlerRun extends ViewRecord
{
    protected static string $resource = CrawlerRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('download_log')
                ->label(__('app.crawler_runs.actions.download_log'))
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn (): string => route('crawler-runs.log.download', $this->record))
                ->visible(fn (): bool => $this->record->hasLogFile()),
        ];
    }
}
