<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\NoticeResource;
use App\Models\Notice;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentNoticesTable extends TableWidget
{
    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    protected function getTableHeading(): string
    {
        return __('app.dashboard.latest_notices.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Notice::query()
                    ->with(['dailyJournal.source'])
                    ->latest('id')
            )
            ->defaultPaginationPageOption(8)
            ->paginated([8])
            ->columns([
                Tables\Columns\TextColumn::make('dailyJournal.issue_date')
                    ->label(__('app.dashboard.latest_notices.columns.issue_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dailyJournal.source.name')
                    ->label(__('app.dashboard.latest_notices.columns.source'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('app.dashboard.latest_notices.columns.title'))
                    ->limit(90)
                    ->tooltip(fn (Notice $record): string => $record->title),
                Tables\Columns\IconColumn::make('url')
                    ->label(__('app.dashboard.latest_notices.columns.url'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Notice $record): ?string => $record->url)
                    ->openUrlInNewTab()
                    ->alignCenter(),
            ])
            ->recordUrl(fn (Notice $record): ?string => auth()->user()?->isAdmin() ? NoticeResource::getUrl('view', ['record' => $record]) : null)
            ->emptyStateHeading(__('app.dashboard.latest_notices.empty_heading'))
            ->emptyStateDescription(__('app.dashboard.latest_notices.empty_description'));
    }
}
