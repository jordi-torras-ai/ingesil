<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NoticeAnalysisResource\Pages;
use App\Models\NoticeAnalysis;
use App\Models\NoticeAnalysisRun;
use App\Models\Source;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class NoticeAnalysisResource extends Resource
{
    protected static ?string $model = NoticeAnalysis::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 10;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('app.notice_analyses.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.notice_analyses.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.notice_analyses.model_plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('app.notice_analyses.sections.result'))
                    ->schema([
                        Forms\Components\TextInput::make('status')
                            ->label(__('app.notice_analyses.fields.status'))
                            ->disabled(),
                        Forms\Components\TextInput::make('decision')
                            ->label(__('app.notice_analyses.fields.decision'))
                            ->disabled(),
                        Forms\Components\TextInput::make('vector')
                            ->label(__('app.notice_analyses.fields.vector'))
                            ->disabled(),
                        Forms\Components\TextInput::make('scope')
                            ->label(__('app.notice_analyses.fields.scope'))
                            ->disabled(),
                        Forms\Components\Textarea::make('reason')
                            ->label(__('app.notice_analyses.fields.reason'))
                            ->rows(3)
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('summary')
                            ->label(__('app.notice_analyses.fields.summary'))
                            ->rows(5)
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('repealed_provisions')
                            ->label(__('app.notice_analyses.fields.repealed_provisions'))
                            ->rows(4)
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('link')
                            ->label(__('app.notice_analyses.fields.link'))
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('error_message')
                            ->label(__('app.notice_analyses.fields.error_message'))
                            ->rows(4)
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('app.notice_analyses.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('noticeAnalysisRun.id')
                    ->label(__('app.notice_analyses.fields.run'))
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('noticeAnalysisRun.issue_date')
                    ->label(__('app.notice_analyses.fields.issue_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('notice.title')
                    ->label(__('app.notice_analyses.fields.notice'))
                    ->searchable()
                    ->limit(80)
                    ->tooltip(fn (NoticeAnalysis $record): string => (string) $record->notice?->title),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('app.notice_analyses.fields.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        NoticeAnalysis::STATUS_DONE => 'success',
                        NoticeAnalysis::STATUS_FAILED => 'danger',
                        NoticeAnalysis::STATUS_PROCESSING => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('decision')
                    ->label(__('app.notice_analyses.fields.decision'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'send' => 'success',
                        'ignore' => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('vector')
                    ->label(__('app.notice_analyses.fields.vector'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('scope')
                    ->label(__('app.notice_analyses.fields.scope'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('processed_at')
                    ->label(__('app.notice_analyses.fields.processed_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('app.notice_analyses.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('app.notice_analyses.filters.status'))
                    ->options([
                        NoticeAnalysis::STATUS_QUEUED => NoticeAnalysis::STATUS_QUEUED,
                        NoticeAnalysis::STATUS_PROCESSING => NoticeAnalysis::STATUS_PROCESSING,
                        NoticeAnalysis::STATUS_DONE => NoticeAnalysis::STATUS_DONE,
                        NoticeAnalysis::STATUS_FAILED => NoticeAnalysis::STATUS_FAILED,
                    ]),
                Tables\Filters\SelectFilter::make('decision')
                    ->label(__('app.notice_analyses.filters.decision'))
                    ->options([
                        'send' => 'send',
                        'ignore' => 'ignore',
                    ]),
                Tables\Filters\SelectFilter::make('vector')
                    ->label(__('app.notice_analyses.filters.vector'))
                    ->options(function (): array {
                        return NoticeAnalysis::query()
                            ->select('vector')
                            ->selectRaw('COUNT(*) as aggregate')
                            ->whereNotNull('vector')
                            ->where('vector', '<>', '')
                            ->groupBy('vector')
                            ->orderBy('vector')
                            ->get()
                            ->mapWithKeys(fn (NoticeAnalysis $analysis): array => [
                                (string) $analysis->vector => sprintf('%s (%d)', $analysis->vector, $analysis->aggregate),
                            ])
                            ->all();
                    })
                    ->searchable(),
                Tables\Filters\SelectFilter::make('scope')
                    ->label(__('app.notice_analyses.filters.scope'))
                    ->options(function (): array {
                        return NoticeAnalysis::query()
                            ->select('scope')
                            ->selectRaw('COUNT(*) as aggregate')
                            ->whereNotNull('scope')
                            ->where('scope', '<>', '')
                            ->groupBy('scope')
                            ->orderBy('scope')
                            ->get()
                            ->mapWithKeys(fn (NoticeAnalysis $analysis): array => [
                                (string) $analysis->scope => sprintf('%s (%d)', $analysis->scope, $analysis->aggregate),
                            ])
                            ->all();
                    })
                    ->searchable(),
                Tables\Filters\SelectFilter::make('notice_analysis_run_id')
                    ->label(__('app.notice_analyses.filters.run'))
                    ->options(function (): array {
                        return NoticeAnalysisRun::query()
                            ->orderByDesc('id')
                            ->limit(300)
                            ->get(['id', 'status', 'processed_notices', 'total_notices'])
                            ->mapWithKeys(fn (NoticeAnalysisRun $run): array => [
                                (string) $run->id => sprintf(
                                    '#%d %s (%d/%d)',
                                    $run->id,
                                    $run->status,
                                    $run->processed_notices,
                                    $run->total_notices
                                ),
                            ])
                            ->all();
                    })
                    ->searchable(),
                Tables\Filters\SelectFilter::make('source_id')
                    ->label(__('app.notice_analyses.filters.source'))
                    ->options(function (): array {
                        return Source::query()
                            ->select('sources.id', 'sources.name')
                            ->selectRaw('COUNT(notice_analyses.id) as aggregate')
                            ->join('daily_journals', 'daily_journals.source_id', '=', 'sources.id')
                            ->join('notices', 'notices.daily_journal_id', '=', 'daily_journals.id')
                            ->join('notice_analyses', 'notice_analyses.notice_id', '=', 'notices.id')
                            ->groupBy('sources.id', 'sources.name')
                            ->orderBy('sources.name')
                            ->get()
                            ->mapWithKeys(fn (Source $source): array => [
                                (string) $source->id => sprintf('%s (%d)', $source->name, $source->aggregate),
                            ])
                            ->all();
                    })
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $sourceId): Builder => $query->whereHas(
                            'notice.dailyJournal',
                            fn (Builder $dailyJournalQuery): Builder => $dailyJournalQuery->where('source_id', $sourceId)
                        )
                    ))
                    ->searchable(),
                Tables\Filters\Filter::make('has_errors')
                    ->label(__('app.notice_analyses.filters.has_errors'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('error_message')
                        ->where('error_message', '<>', '')),
                Tables\Filters\Filter::make('processed_only')
                    ->label(__('app.notice_analyses.filters.processed_only'))
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                        NoticeAnalysis::STATUS_DONE,
                        NoticeAnalysis::STATUS_FAILED,
                    ])),
                Tables\Filters\Filter::make('issue_date')
                    ->label(__('app.notice_analyses.fields.issue_date'))
                    ->form([
                        Forms\Components\DatePicker::make('issue_date')
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['issue_date'] ?? null,
                        fn (Builder $query, string $issueDate): Builder => $query->whereHas(
                            'noticeAnalysisRun',
                            fn (Builder $runQuery): Builder => $runQuery->whereDate('issue_date', $issueDate)
                        )
                    ))
                    ->indicateUsing(function (array $data): array {
                        if (empty($data['issue_date'])) {
                            return [];
                        }

                        return [
                            'issue_date' => __('app.notice_analyses.fields.issue_date').': '.Carbon::parse((string) $data['issue_date'])->toFormattedDateString(),
                        ];
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])->tooltip(__('app.common.actions')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['noticeAnalysisRun', 'notice']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNoticeAnalyses::route('/'),
            'view' => Pages\ViewNoticeAnalysis::route('/{record}'),
        ];
    }
}
