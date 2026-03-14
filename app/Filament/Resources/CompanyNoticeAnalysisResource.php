<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyNoticeAnalysisResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\CompanyNoticeAnalysisResource\Pages;
use App\Models\Company;
use App\Models\CompanyNoticeAnalysis;
use App\Models\CompanyNoticeAnalysisRun;
use App\Models\Scope;
use App\Models\Source;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CompanyNoticeAnalysisResource extends Resource
{
    protected static ?string $model = CompanyNoticeAnalysis::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->isPlatformAdmin() || $user->companies()->exists()));
    }

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->isPlatformAdmin()
            ? __('app.navigation.groups.customer_operations')
            : __('app.navigation.groups.workspace');
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
        $user = auth()->user();

        if (! $user || ! $record instanceof CompanyNoticeAnalysis) {
            return false;
        }

        return $user->isPlatformAdmin() || $record->companyNoticeAnalysisRun?->company?->users()->whereKey($user->id)->exists();
    }

    public static function canView(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->isPlatformAdmin()
            ? __('app.company_notice_analyses.navigation')
            : __('app.company_notice_analyses.navigation_customer');
    }

    public static function getModelLabel(): string
    {
        return __('app.company_notice_analyses.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.company_notice_analyses.model_plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('app.company_notice_analyses.sections.analysis'))
                    ->schema([
                        Forms\Components\TextInput::make('companyNoticeAnalysisRun.company.name')
                            ->label(__('app.company_notice_analyses.fields.company'))
                            ->disabled(),
                        Forms\Components\TextInput::make('noticeAnalysis.noticeAnalysisRun.scope.code')
                            ->label(__('app.company_notice_analyses.fields.scope'))
                            ->formatStateUsing(fn (?string $state, CompanyNoticeAnalysis $record): string => $record->noticeAnalysis?->noticeAnalysisRun?->scope?->name(app()->getLocale()) ?? (string) $state)
                            ->disabled(),
                        Forms\Components\TextInput::make('companyNoticeAnalysisRun.locale')
                            ->label(__('app.company_notice_analyses.fields.locale'))
                            ->formatStateUsing(fn (?string $state): string => \App\Models\User::localeOptions()[$state ?? ''] ?? strtoupper((string) $state))
                            ->disabled(),
                        Forms\Components\TextInput::make('noticeAnalysis.notice.dailyJournal.issue_date')
                            ->label(__('app.company_notice_analyses.fields.issue_date'))
                            ->formatStateUsing(fn ($state): string => $state ? \Illuminate\Support\Carbon::parse((string) $state)->toDateString() : '—')
                            ->disabled(),
                        Forms\Components\TextInput::make('noticeAnalysis.notice.dailyJournal.source.name')
                            ->label(__('app.company_notice_analyses.fields.source'))
                            ->disabled(),
                        Forms\Components\TextInput::make('noticeAnalysis.notice.title')
                            ->label(__('app.company_notice_analyses.fields.notice'))
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('decision')
                            ->label(__('app.company_notice_analyses.fields.decision'))
                            ->disabled(),
                        Forms\Components\DatePicker::make('compliance_due_at')
                            ->label(__('app.company_notice_analyses.fields.compliance_due_at'))
                            ->native(false)
                            ->disabled(),
                        Forms\Components\Textarea::make('reason')
                            ->label(__('app.company_notice_analyses.fields.reason'))
                            ->rows(3)
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('requirements')
                            ->label(__('app.company_notice_analyses.fields.requirements'))
                            ->rows(5)
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.company_notice_analyses.sections.company_review'))
                    ->schema([
                        Forms\Components\Select::make('confirmed_relevant')
                            ->label(__('app.company_notice_analyses.fields.confirmed_relevant'))
                            ->placeholder(__('app.company_notice_analyses.review_statuses.pending_review'))
                            ->options([
                                '1' => __('app.common.yes'),
                                '0' => __('app.common.no'),
                            ])
                            ->native(false)
                            ->selectablePlaceholder()
                            ->formatStateUsing(fn (?bool $state): ?string => match ($state) {
                                true => '1',
                                false => '0',
                                default => null,
                            })
                            ->dehydrateStateUsing(fn (mixed $state): ?bool => match ((string) $state) {
                                '1' => true,
                                '0' => false,
                                default => null,
                            })
                            ->live(),
                        Forms\Components\Radio::make('compliance')
                            ->label(__('app.company_notice_analyses.fields.compliance'))
                            ->options([
                                1 => __('app.common.yes'),
                                0 => __('app.common.no'),
                            ])
                            ->live(),
                        Forms\Components\DatePicker::make('compliance_date')
                            ->label(__('app.company_notice_analyses.fields.compliance_date'))
                            ->native(false)
                            ->visible(fn (Forms\Get $get): bool => static::isTruthy($get('confirmed_relevant')) && static::isTruthy($get('compliance'))),
                        Forms\Components\Textarea::make('compliance_evaluation')
                            ->label(__('app.company_notice_analyses.fields.compliance_evaluation'))
                            ->rows(4)
                            ->visible(fn (Forms\Get $get): bool => static::isTruthy($get('confirmed_relevant')) && static::isTruthy($get('compliance')))
                            ->required(fn (Forms\Get $get): bool => static::isTruthy($get('confirmed_relevant')) && static::isTruthy($get('compliance')))
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('action_plan')
                            ->label(__('app.company_notice_analyses.fields.action_plan'))
                            ->rows(4)
                            ->visible(fn (Forms\Get $get): bool => static::isTruthy($get('confirmed_relevant')) && static::isFalsy($get('compliance')))
                            ->required(fn (Forms\Get $get): bool => static::isTruthy($get('confirmed_relevant')) && static::isFalsy($get('compliance')))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    private static function isTruthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    private static function isFalsy(mixed $value): bool
    {
        return in_array($value, [false, 0, '0', 'false'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('app.company_notice_analyses.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('companyNoticeAnalysisRun.company.name')
                    ->label(__('app.company_notice_analyses.fields.company'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('noticeAnalysis.noticeAnalysisRun.issue_date')
                    ->label(__('app.company_notice_analyses.fields.issue_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('noticeAnalysis.noticeAnalysisRun.scope.code')
                    ->label(__('app.company_notice_analyses.fields.scope'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state, CompanyNoticeAnalysis $record): string => $record->noticeAnalysis?->noticeAnalysisRun?->scope?->name(app()->getLocale()) ?? (string) $state)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('companyNoticeAnalysisRun.locale')
                    ->label(__('app.company_notice_analyses.fields.locale'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => \App\Models\User::localeOptions()[$state ?? ''] ?? strtoupper((string) $state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('noticeAnalysis.notice.dailyJournal.source.name')
                    ->label(__('app.company_notice_analyses.fields.source'))
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('noticeAnalysis.notice.title')
                    ->label(__('app.company_notice_analyses.fields.notice'))
                    ->searchable()
                    ->wrap()
                    ->limit(90),
                Tables\Columns\TextColumn::make('decision')
                    ->label(__('app.company_notice_analyses.fields.decision'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        CompanyNoticeAnalysis::DECISION_RELEVANT => 'success',
                        CompanyNoticeAnalysis::DECISION_NOT_RELEVANT => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('compliance_due_at')
                    ->label(__('app.company_notice_analyses.fields.compliance_due_at'))
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('confirmed_relevant')
                    ->label(__('app.company_notice_analyses.fields.confirmed_relevant'))
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => match ($state) {
                        true => __('app.common.yes'),
                        false => __('app.common.no'),
                        default => __('app.company_notice_analyses.review_statuses.pending_review'),
                    })
                    ->color(fn (?bool $state): string => match ($state) {
                        true => 'success',
                        false => 'gray',
                        default => 'warning',
                    })
                    ->toggleable(),
                Tables\Columns\IconColumn::make('compliance')
                    ->label(__('app.company_notice_analyses.fields.compliance'))
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('app.company_notice_analyses.fields.updated_at'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('company_id')
                    ->label(__('app.company_notice_analyses.filters.company'))
                    ->options(fn (): array => static::visibleCompanyOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $companyId): Builder => $query->whereHas(
                            'companyNoticeAnalysisRun',
                            fn (Builder $runQuery): Builder => $runQuery->where('company_id', $companyId)
                        )
                    )),
                Tables\Filters\SelectFilter::make('scope_id')
                    ->label(__('app.company_notice_analyses.filters.scope'))
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
                            'noticeAnalysis.noticeAnalysisRun',
                            fn (Builder $runQuery): Builder => $runQuery->where('scope_id', $scopeId)
                        )
                    )),
                Tables\Filters\SelectFilter::make('source_id')
                    ->label(__('app.company_notice_analyses.filters.source'))
                    ->options(fn (): array => Source::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $sourceId): Builder => $query->whereHas(
                            'noticeAnalysis.notice.dailyJournal',
                            fn (Builder $journalQuery): Builder => $journalQuery->where('source_id', $sourceId)
                        )
                    )),
                Tables\Filters\SelectFilter::make('decision')
                    ->label(__('app.company_notice_analyses.filters.decision'))
                    ->options([
                        CompanyNoticeAnalysis::DECISION_RELEVANT => __('app.company_notice_analyses.decisions.relevant'),
                        CompanyNoticeAnalysis::DECISION_NOT_RELEVANT => __('app.company_notice_analyses.decisions.not_relevant'),
                    ]),
                Tables\Filters\SelectFilter::make('locale')
                    ->label(__('app.company_notice_analyses.filters.locale'))
                    ->options(\App\Models\User::localeOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $locale): Builder => $query->whereHas(
                            'companyNoticeAnalysisRun',
                            fn (Builder $runQuery): Builder => $runQuery->where('locale', $locale)
                        )
                    )),
                Tables\Filters\SelectFilter::make('confirmed_relevant')
                    ->label(__('app.company_notice_analyses.filters.confirmed_relevant'))
                    ->options([
                        'pending' => __('app.company_notice_analyses.review_statuses.pending_review'),
                        '1' => __('app.common.yes'),
                        '0' => __('app.common.no'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $value): Builder => match ($value) {
                            'pending' => $query
                                ->where('decision', CompanyNoticeAnalysis::DECISION_RELEVANT)
                                ->whereNull('confirmed_relevant'),
                            '1' => $query->where('confirmed_relevant', true),
                            '0' => $query->where('confirmed_relevant', false),
                            default => $query,
                        }
                    )),
                Tables\Filters\SelectFilter::make('compliance')
                    ->label(__('app.company_notice_analyses.filters.compliance'))
                    ->options([
                        '1' => __('app.common.yes'),
                        '0' => __('app.common.no'),
                    ]),
                Tables\Filters\SelectFilter::make('company_notice_analysis_run_id')
                    ->label(__('app.company_notice_analyses.filters.run'))
                    ->options(fn (): array => static::visibleRunOptions())
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                ])
                    ->iconButton()
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip(__('app.common.actions')),
            ]);
    }

    public static function pendingReviewUrl(): string
    {
        return static::getUrl('index', [
            'tableFilters' => [
                'decision' => [
                    'value' => CompanyNoticeAnalysis::DECISION_RELEVANT,
                ],
                'confirmed_relevant' => [
                    'value' => 'pending',
                ],
            ],
        ]);
    }

    public static function getNavigationBadge(): ?string
    {
        if (auth()->user()?->isPlatformAdmin()) {
            return null;
        }

        $count = static::pendingReviewCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::pendingReviewCount() > 0 ? 'warning' : 'gray';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'companyNoticeAnalysisRun.company.users',
                'noticeAnalysis.notice.dailyJournal.source',
                'noticeAnalysis.noticeAnalysisRun.scope.translations',
            ])
            ->where('is_applicable', true);

        if (auth()->user()?->isPlatformAdmin() ?? false) {
            return $query;
        }

        return $query->whereHas(
            'companyNoticeAnalysisRun.company.users',
            fn (Builder $builder): Builder => $builder->whereKey(auth()->id())
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanyNoticeAnalyses::route('/'),
            'view' => Pages\ViewCompanyNoticeAnalysis::route('/{record}'),
            'edit' => Pages\EditCompanyNoticeAnalysis::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function visibleCompanyOptions(): array
    {
        $query = Company::query()->orderBy('name');

        if (! (auth()->user()?->isPlatformAdmin() ?? false)) {
            $query->whereHas('users', fn (Builder $builder): Builder => $builder->whereKey(auth()->id()));
        }

        return $query->pluck('name', 'id')->all();
    }

    private static function pendingReviewCount(): int
    {
        return (clone static::getEloquentQuery())
            ->where('decision', CompanyNoticeAnalysis::DECISION_RELEVANT)
            ->whereNull('confirmed_relevant')
            ->count();
    }

    /**
     * @return array<string, string>
     */
    private static function visibleRunOptions(): array
    {
        $query = CompanyNoticeAnalysisRun::query()
            ->with(['company', 'noticeAnalysisRun.scope.translations'])
            ->orderByDesc('id')
            ->limit(200);

        if (! (auth()->user()?->isPlatformAdmin() ?? false)) {
            $query->whereHas('company.users', fn (Builder $builder): Builder => $builder->whereKey(auth()->id()));
        }

        return $query->get()
            ->mapWithKeys(fn (CompanyNoticeAnalysisRun $run): array => [
                (string) $run->id => sprintf(
                    '#%d — %s — %s — %s',
                    $run->id,
                    $run->company?->name ?? '—',
                    $run->noticeAnalysisRun?->scope?->name(app()->getLocale()) ?? '—',
                    $run->noticeAnalysisRun?->issue_date?->format('Y-m-d') ?? '—'
                ),
            ])
            ->all();
    }
}
