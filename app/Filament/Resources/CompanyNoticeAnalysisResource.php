<?php

namespace App\Filament\Resources;

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

    protected static ?int $navigationSort = 12;

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
        $user = auth()->user();

        if (! $user || ! $record instanceof CompanyNoticeAnalysis) {
            return false;
        }

        return $user->isAdmin() || $record->companyNoticeAnalysisRun?->company?->users()->whereKey($user->id)->exists();
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
        return __('app.company_notice_analyses.navigation');
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
                        Forms\Components\Radio::make('confirmed_relevant')
                            ->label(__('app.company_notice_analyses.fields.confirmed_relevant'))
                            ->options([
                                1 => __('app.common.yes'),
                                0 => __('app.common.no'),
                            ]),
                        Forms\Components\Radio::make('compliance')
                            ->label(__('app.company_notice_analyses.fields.compliance'))
                            ->options([
                                1 => __('app.common.yes'),
                                0 => __('app.common.no'),
                            ])
                            ->live(),
                        Forms\Components\DatePicker::make('compliance_date')
                            ->label(__('app.company_notice_analyses.fields.compliance_date'))
                            ->visible(fn (Forms\Get $get): bool => $get('compliance') === true || $get('compliance') === 1),
                        Forms\Components\Textarea::make('compliance_evaluation')
                            ->label(__('app.company_notice_analyses.fields.compliance_evaluation'))
                            ->rows(4)
                            ->visible(fn (Forms\Get $get): bool => $get('compliance') === true || $get('compliance') === 1)
                            ->required(fn (Forms\Get $get): bool => $get('compliance') === true || $get('compliance') === 1)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('action_plan')
                            ->label(__('app.company_notice_analyses.fields.action_plan'))
                            ->rows(4)
                            ->visible(fn (Forms\Get $get): bool => $get('compliance') === false || $get('compliance') === 0)
                            ->required(fn (Forms\Get $get): bool => $get('compliance') === false || $get('compliance') === 0)
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
                Tables\Columns\IconColumn::make('confirmed_relevant')
                    ->label(__('app.company_notice_analyses.fields.confirmed_relevant'))
                    ->boolean()
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
                Tables\Filters\SelectFilter::make('confirmed_relevant')
                    ->label(__('app.company_notice_analyses.filters.confirmed_relevant'))
                    ->options([
                        '1' => __('app.common.yes'),
                        '0' => __('app.common.no'),
                    ]),
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'companyNoticeAnalysisRun.company.users',
                'noticeAnalysis.notice.dailyJournal.source',
                'noticeAnalysis.noticeAnalysisRun.scope.translations',
            ])
            ->where('is_applicable', true);

        if (auth()->user()?->isAdmin() ?? false) {
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

    /**
     * @return array<string, string>
     */
    private static function visibleRunOptions(): array
    {
        $query = CompanyNoticeAnalysisRun::query()
            ->with(['company', 'noticeAnalysisRun.scope.translations'])
            ->orderByDesc('id')
            ->limit(200);

        if (! (auth()->user()?->isAdmin() ?? false)) {
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
