<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CompanyNoticeAnalysisResource;
use App\Models\CompanyNoticeAnalysis;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class CustomerRelevantNoticesTable extends TableWidget
{
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ! $user->isPlatformAdmin() && $user->companies()->exists());
    }

    protected function getTableHeading(): string
    {
        return __('app.dashboard.customer_notices.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CompanyNoticeAnalysis::query()
                    ->with([
                        'companyNoticeAnalysisRun.company',
                        'companyNoticeAnalysisRun.noticeAnalysisRun.scope.translations',
                        'noticeAnalysis.notice.dailyJournal.source',
                    ])
                    ->where('decision', CompanyNoticeAnalysis::DECISION_RELEVANT)
                    ->whereHas(
                        'companyNoticeAnalysisRun',
                        fn (Builder $query): Builder => $query->whereIn('company_id', $this->companyIds())
                    )
                    ->latest('id')
            )
            ->defaultPaginationPageOption(8)
            ->paginated([8])
            ->columns([
                Tables\Columns\TextColumn::make('companyNoticeAnalysisRun.company.name')
                    ->label(__('app.dashboard.customer_notices.columns.company'))
                    ->badge(),
                Tables\Columns\TextColumn::make('noticeAnalysis.notice.dailyJournal.issue_date')
                    ->label(__('app.dashboard.customer_notices.columns.issue_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('companyNoticeAnalysisRun.noticeAnalysisRun.scope.code')
                    ->label(__('app.dashboard.customer_notices.columns.scope'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state, CompanyNoticeAnalysis $record): string => $record->companyNoticeAnalysisRun?->noticeAnalysisRun?->scope?->name(app()->getLocale()) ?? (string) $state),
                Tables\Columns\TextColumn::make('noticeAnalysis.notice.title')
                    ->label(__('app.dashboard.customer_notices.columns.notice'))
                    ->limit(90)
                    ->tooltip(fn (CompanyNoticeAnalysis $record): string => $record->noticeAnalysis?->notice?->title ?? ''),
                Tables\Columns\TextColumn::make('compliance_due_at')
                    ->label(__('app.dashboard.customer_notices.columns.compliance_due_at'))
                    ->date()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('compliance')
                    ->label(__('app.dashboard.customer_notices.columns.compliance'))
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => match ($state) {
                        true => __('app.common.yes'),
                        false => __('app.common.no'),
                        default => '—',
                    })
                    ->color(fn (?bool $state): string => match ($state) {
                        true => 'success',
                        false => 'warning',
                        default => 'gray',
                    }),
            ])
            ->recordUrl(fn (CompanyNoticeAnalysis $record): string => CompanyNoticeAnalysisResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading(__('app.dashboard.customer_notices.empty_heading'))
            ->emptyStateDescription(__('app.dashboard.customer_notices.empty_description'));
    }

    /**
     * @return list<int>
     */
    private function companyIds(): array
    {
        return auth()->user()?->companies()
            ->orderBy('companies.id')
            ->pluck('companies.id')
            ->map(fn (int $id): int => (int) $id)
            ->all() ?? [];
    }
}
