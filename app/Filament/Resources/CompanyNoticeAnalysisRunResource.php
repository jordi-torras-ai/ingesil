<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyNoticeAnalysisRunResource\Pages;
use App\Models\Company;
use App\Models\CompanyNoticeAnalysisRun;
use App\Models\Scope;
use App\Services\CompanyNoticeAnalysisRunner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CompanyNoticeAnalysisRunResource extends Resource
{
    protected static ?string $model = CompanyNoticeAnalysisRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 11;

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->isAdmin() || $user->companies()->exists()));
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
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
        $user = auth()->user();

        if (! $user || ! $record instanceof CompanyNoticeAnalysisRun) {
            return false;
        }

        return $user->isAdmin() || $record->company?->users()->whereKey($user->id)->exists();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('app.company_notice_analysis_runs.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.company_notice_analysis_runs.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.company_notice_analysis_runs.model_plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('app.company_notice_analysis_runs.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('app.company_notice_analysis_runs.fields.company'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('noticeAnalysisRun.issue_date')
                    ->label(__('app.company_notice_analysis_runs.fields.issue_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('noticeAnalysisRun.scope.code')
                    ->label(__('app.company_notice_analysis_runs.fields.scope'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state, CompanyNoticeAnalysisRun $record): string => $record->noticeAnalysisRun?->scope?->name(app()->getLocale()) ?? (string) $state)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('noticeAnalysisRun.locale')
                    ->label(__('app.company_notice_analysis_runs.fields.locale'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('app.company_notice_analysis_runs.fields.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CompanyNoticeAnalysisRun::STATUS_COMPLETED => 'success',
                        CompanyNoticeAnalysisRun::STATUS_COMPLETED_WITH_ERRORS => 'warning',
                        CompanyNoticeAnalysisRun::STATUS_PROCESSING => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_notices')
                    ->label(__('app.company_notice_analysis_runs.fields.total_notices'))
                    ->badge()
                    ->color('primary')
                    ->url(fn (CompanyNoticeAnalysisRun $record): ?string => $record->total_notices > 0
                        ? CompanyNoticeAnalysisResource::getUrl('index', [
                            'tableFilters' => [
                                'company_notice_analysis_run_id' => [
                                    'value' => (string) $record->id,
                                ],
                            ],
                        ])
                        : null),
                Tables\Columns\TextColumn::make('processed_notices')
                    ->label(__('app.company_notice_analysis_runs.fields.processed_notices'))
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('relevant_count')
                    ->label(__('app.company_notice_analysis_runs.fields.relevant_count'))
                    ->badge()
                    ->color('success')
                    ->url(fn (CompanyNoticeAnalysisRun $record): ?string => $record->relevant_count > 0
                        ? CompanyNoticeAnalysisResource::getUrl('index', [
                            'tableFilters' => [
                                'company_notice_analysis_run_id' => [
                                    'value' => (string) $record->id,
                                ],
                                'decision' => [
                                    'value' => 'relevant',
                                ],
                            ],
                        ])
                        : null),
                Tables\Columns\TextColumn::make('not_relevant_count')
                    ->label(__('app.company_notice_analysis_runs.fields.not_relevant_count'))
                    ->badge()
                    ->color('gray')
                    ->url(fn (CompanyNoticeAnalysisRun $record): ?string => $record->not_relevant_count > 0
                        ? CompanyNoticeAnalysisResource::getUrl('index', [
                            'tableFilters' => [
                                'company_notice_analysis_run_id' => [
                                    'value' => (string) $record->id,
                                ],
                                'decision' => [
                                    'value' => 'not_relevant',
                                ],
                            ],
                        ])
                        : null),
                Tables\Columns\TextColumn::make('failed_count')
                    ->label(__('app.company_notice_analysis_runs.fields.failed_count'))
                    ->badge()
                    ->color('danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('company_id')
                    ->label(__('app.company_notice_analysis_runs.filters.company'))
                    ->options(fn (): array => static::visibleCompanyOptions()),
                Tables\Filters\SelectFilter::make('scope_id')
                    ->label(__('app.company_notice_analysis_runs.filters.scope'))
                    ->options(fn (): array => Scope::query()
                        ->with('translations')
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn (Scope $scope): array => [
                            (string) $scope->id => $scope->name(app()->getLocale()),
                        ])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $scopeId): Builder => $query->whereHas(
                            'noticeAnalysisRun',
                            fn (Builder $runQuery): Builder => $runQuery->where('scope_id', $scopeId)
                        )
                    )),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('app.company_notice_analysis_runs.filters.status'))
                    ->options([
                        CompanyNoticeAnalysisRun::STATUS_QUEUED => CompanyNoticeAnalysisRun::STATUS_QUEUED,
                        CompanyNoticeAnalysisRun::STATUS_PROCESSING => CompanyNoticeAnalysisRun::STATUS_PROCESSING,
                        CompanyNoticeAnalysisRun::STATUS_COMPLETED => CompanyNoticeAnalysisRun::STATUS_COMPLETED,
                        CompanyNoticeAnalysisRun::STATUS_COMPLETED_WITH_ERRORS => CompanyNoticeAnalysisRun::STATUS_COMPLETED_WITH_ERRORS,
                    ]),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('rerun_company_analysis')
                        ->label(__('app.company_notice_analysis_runs.actions.rerun'))
                        ->icon('heroicon-o-play')
                        ->color('warning')
                        ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                        ->requiresConfirmation()
                        ->action(function (CompanyNoticeAnalysisRun $record): void {
                            try {
                                app(CompanyNoticeAnalysisRunner::class)->dispatchCompanyRun(
                                    $record->noticeAnalysisRun()->with('scope.translations')->firstOrFail(),
                                    $record->company()->firstOrFail(),
                                );

                                Notification::make()
                                    ->success()
                                    ->title(__('app.company_notice_analysis_runs.actions.rerun_success_title'))
                                    ->body(__('app.company_notice_analysis_runs.actions.rerun_success_body', [
                                        'run' => $record->id,
                                        'company' => $record->company?->name ?? '',
                                    ]))
                                    ->send();
                            } catch (\Throwable $exc) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('app.company_notice_analysis_runs.actions.rerun_error_title'))
                                    ->body($exc->getMessage())
                                    ->send();
                            }
                        }),
                ])
                    ->iconButton()
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip(__('app.common.actions')),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'company.users',
            'noticeAnalysisRun.scope.translations',
        ]);

        if (auth()->user()?->isAdmin() ?? false) {
            return $query;
        }

        return $query->whereHas('company.users', fn (Builder $builder): Builder => $builder->whereKey(auth()->id()));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanyNoticeAnalysisRuns::route('/'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function visibleCompanyOptions(): array
    {
        $query = Company::query()->orderBy('name');

        if (! (auth()->user()?->isAdmin() ?? false)) {
            $query->whereHas('users', fn (Builder $builder): Builder => $builder->whereKey(auth()->id()));
        }

        return $query->pluck('name', 'id')->all();
    }
}
