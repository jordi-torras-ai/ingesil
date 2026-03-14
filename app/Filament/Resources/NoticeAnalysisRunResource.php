<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NoticeAnalysisRunResource\Pages;
use App\Models\NoticeAnalysisRun;
use App\Models\Scope;
use App\Models\User;
use App\Services\CompanyNoticeAnalysisRunner;
use App\Services\NoticeAnalysisRunner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NoticeAnalysisRunResource extends Resource
{
    protected static ?string $model = NoticeAnalysisRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.groups.regulatory_analysis');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
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
        return __('app.notice_analysis_runs.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.notice_analysis_runs.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.notice_analysis_runs.model_plural');
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
                    ->label(__('app.notice_analysis_runs.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('issue_date')
                    ->label(__('app.notice_analysis_runs.fields.issue_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('scope.code')
                    ->label(__('app.notice_analysis_runs.fields.scope'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state, NoticeAnalysisRun $record): string => $record->scope?->name(app()->getLocale()) ?? (string) $state)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('locale')
                    ->label(__('app.notice_analysis_runs.fields.locale'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => User::localeOptions()[$state] ?? strtoupper($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('app.notice_analysis_runs.fields.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        NoticeAnalysisRun::STATUS_COMPLETED => 'success',
                        NoticeAnalysisRun::STATUS_COMPLETED_WITH_ERRORS => 'warning',
                        NoticeAnalysisRun::STATUS_PROCESSING => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_notices')
                    ->label(__('app.notice_analysis_runs.fields.total_notices'))
                    ->badge()
                    ->color('primary')
                    ->url(fn (NoticeAnalysisRun $record): ?string => $record->total_notices > 0
                        ? NoticeAnalysisResource::getUrl('index', [
                            'tableFilters' => [
                                'notice_analysis_run_id' => [
                                    'value' => (string) $record->id,
                                ],
                            ],
                        ])
                        : null)
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('processed_notices')
                    ->label(__('app.notice_analysis_runs.fields.processed_notices'))
                    ->badge()
                    ->color('info')
                    ->url(fn (NoticeAnalysisRun $record): ?string => $record->processed_notices > 0
                        ? NoticeAnalysisResource::getUrl('index', [
                            'tableFilters' => [
                                'notice_analysis_run_id' => [
                                    'value' => (string) $record->id,
                                ],
                                'processed_only' => [
                                    'isActive' => true,
                                ],
                            ],
                        ])
                        : null)
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('sent_count')
                    ->label(__('app.notice_analysis_runs.fields.sent_count'))
                    ->badge()
                    ->color('success')
                    ->url(fn (NoticeAnalysisRun $record): ?string => $record->sent_count > 0
                        ? NoticeAnalysisResource::getUrl('index', [
                            'tableFilters' => [
                                'notice_analysis_run_id' => [
                                    'value' => (string) $record->id,
                                ],
                                'decision' => [
                                    'value' => 'send',
                                ],
                            ],
                        ])
                        : null)
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('ignored_count')
                    ->label(__('app.notice_analysis_runs.fields.ignored_count'))
                    ->badge()
                    ->color('gray')
                    ->url(fn (NoticeAnalysisRun $record): ?string => $record->ignored_count > 0
                        ? NoticeAnalysisResource::getUrl('index', [
                            'tableFilters' => [
                                'notice_analysis_run_id' => [
                                    'value' => (string) $record->id,
                                ],
                                'decision' => [
                                    'value' => 'ignore',
                                ],
                            ],
                        ])
                        : null)
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('failed_count')
                    ->label(__('app.notice_analysis_runs.fields.failed_count'))
                    ->badge()
                    ->color('danger')
                    ->url(fn (NoticeAnalysisRun $record): ?string => $record->failed_count > 0
                        ? NoticeAnalysisResource::getUrl('index', [
                            'tableFilters' => [
                                'notice_analysis_run_id' => [
                                    'value' => (string) $record->id,
                                ],
                                'status' => [
                                    'value' => 'failed',
                                ],
                            ],
                        ])
                        : null)
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('requestedByUser.email')
                    ->label(__('app.notice_analysis_runs.fields.requested_by'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('app.notice_analysis_runs.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('app.notice_analysis_runs.filters.status'))
                    ->options([
                        NoticeAnalysisRun::STATUS_QUEUED => NoticeAnalysisRun::STATUS_QUEUED,
                        NoticeAnalysisRun::STATUS_PROCESSING => NoticeAnalysisRun::STATUS_PROCESSING,
                        NoticeAnalysisRun::STATUS_COMPLETED => NoticeAnalysisRun::STATUS_COMPLETED,
                        NoticeAnalysisRun::STATUS_COMPLETED_WITH_ERRORS => NoticeAnalysisRun::STATUS_COMPLETED_WITH_ERRORS,
                    ]),
                Tables\Filters\Filter::make('issue_date')
                    ->label(__('app.notice_analysis_runs.filters.issue_date'))
                    ->form([
                        Forms\Components\DatePicker::make('issue_date')
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['issue_date'] ?? null,
                        fn (Builder $query, string $issueDate): Builder => $query->whereDate('issue_date', $issueDate)
                    )),
                Tables\Filters\SelectFilter::make('locale')
                    ->label(__('app.notice_analysis_runs.filters.locale'))
                    ->options(User::localeOptions()),
                Tables\Filters\SelectFilter::make('scope_id')
                    ->label(__('app.notice_analysis_runs.filters.scope'))
                    ->options(fn (): array => Scope::query()
                        ->with('translations')
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->get()
                        ->mapWithKeys(fn (Scope $scope): array => [
                            (string) $scope->id => $scope->name(app()->getLocale()),
                        ])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('run_analysis')
                        ->label(__('app.notice_analysis_runs.actions.run_analysis'))
                        ->icon('heroicon-o-play')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (NoticeAnalysisRun $record): void {
                            try {
                                $run = app(NoticeAnalysisRunner::class)->dispatchRun($record);

                                Notification::make()
                                    ->success()
                                    ->title(__('app.notice_analysis_runs.actions.run_analysis_success_title'))
                                    ->body(__('app.notice_analysis_runs.actions.run_analysis_success_body', [
                                        'run' => $run->id,
                                        'date' => (string) $run->issue_date?->format('Y-m-d'),
                                        'total' => $run->total_notices,
                                    ]))
                                    ->send();
                            } catch (\Throwable $exc) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('app.notice_analysis_runs.actions.run_analysis_error_title'))
                                    ->body($exc->getMessage())
                                    ->send();
                            }
                        }),
                    Tables\Actions\Action::make('run_company_analysis')
                        ->label(__('app.notice_analysis_runs.actions.run_company_analysis'))
                        ->icon('heroicon-o-building-office-2')
                        ->color('info')
                        ->requiresConfirmation()
                        ->visible(fn (NoticeAnalysisRun $record): bool => in_array($record->status, [
                            NoticeAnalysisRun::STATUS_COMPLETED,
                            NoticeAnalysisRun::STATUS_COMPLETED_WITH_ERRORS,
                        ], true))
                        ->action(function (NoticeAnalysisRun $record): void {
                            try {
                                $count = app(CompanyNoticeAnalysisRunner::class)->dispatchForNoticeAnalysisRun($record);

                                Notification::make()
                                    ->success()
                                    ->title(__('app.notice_analysis_runs.actions.run_company_analysis_success_title'))
                                    ->body(__('app.notice_analysis_runs.actions.run_company_analysis_success_body', [
                                        'run' => $record->id,
                                        'count' => $count,
                                    ]))
                                    ->send();
                            } catch (\Throwable $exc) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('app.notice_analysis_runs.actions.run_company_analysis_error_title'))
                                    ->body($exc->getMessage())
                                    ->send();
                            }
                        }),
                    Tables\Actions\Action::make('view_results')
                        ->label(__('app.notice_analysis_runs.actions.view_results'))
                        ->icon('heroicon-o-list-bullet')
                        ->url(fn (NoticeAnalysisRun $record): string => NoticeAnalysisResource::getUrl('index', [
                            'tableFilters' => [
                                'notice_analysis_run_id' => [
                                    'value' => (string) $record->id,
                                ],
                            ],
                        ])),
                    Tables\Actions\DeleteAction::make(),
                ])->tooltip(__('app.common.actions')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('create_run')
                    ->label(__('app.notice_analysis_runs.actions.create_run'))
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form([
                        Forms\Components\DatePicker::make('issue_date')
                            ->label(__('app.notice_analysis_runs.actions.issue_date'))
                            ->native(false)
                            ->required(),
                        Forms\Components\Select::make('scope_id')
                            ->label(__('app.notice_analysis_runs.actions.scope'))
                            ->options(fn (): array => Scope::query()
                                ->with('translations')
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('id')
                                ->get()
                                ->filter(fn (Scope $scope): bool => $scope->hasAnalysisPrompt())
                                ->mapWithKeys(fn (Scope $scope): array => [
                                    (string) $scope->id => $scope->name(app()->getLocale()),
                                ])
                                ->all())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $issueDate = (string) ($data['issue_date'] ?? '');
                            $scopeId = (int) ($data['scope_id'] ?? 0);
                            $scope = Scope::query()->with('translations')->findOrFail($scopeId);
                            $run = app(NoticeAnalysisRunner::class)->createRunForIssueDate($issueDate, $scope, auth()->id(), User::LOCALE_EN);

                            Notification::make()
                                ->success()
                                ->title(__('app.notice_analysis_runs.actions.create_run_success_title'))
                                ->body(__('app.notice_analysis_runs.actions.create_run_success_body', [
                                    'run' => $run->id,
                                    'date' => $issueDate,
                                    'scope' => $scope->name(app()->getLocale()),
                                    'language' => User::localeOptions()[User::LOCALE_EN] ?? User::LOCALE_EN,
                                ]))
                                ->send();
                        } catch (\Throwable $exc) {
                            Notification::make()
                                ->danger()
                                ->title(__('app.notice_analysis_runs.actions.create_run_error_title'))
                                ->body($exc->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['requestedByUser', 'scope.translations']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNoticeAnalysisRuns::route('/'),
        ];
    }
}
