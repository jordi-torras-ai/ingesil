<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CrawlerRunResource\Pages;
use App\Models\CrawlerRun;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CrawlerRunResource extends Resource
{
    protected static ?string $model = CrawlerRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 7;

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
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('app.crawler_runs.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.crawler_runs.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.crawler_runs.model_plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('app.crawler_runs.sections.run'))
                    ->schema([
                        Forms\Components\Placeholder::make('source')
                            ->label(__('app.crawler_runs.fields.source'))
                            ->content(fn (?CrawlerRun $record): string => $record?->source?->name ?? $record?->source_slug ?? '—'),
                        Forms\Components\TextInput::make('source_slug')
                            ->label(__('app.crawler_runs.fields.source_slug'))
                            ->disabled(),
                        Forms\Components\DatePicker::make('issue_date')
                            ->label(__('app.crawler_runs.fields.issue_date'))
                            ->disabled(),
                        Forms\Components\TextInput::make('status')
                            ->label(__('app.crawler_runs.fields.status'))
                            ->disabled(),
                        Forms\Components\TextInput::make('mode')
                            ->label(__('app.crawler_runs.fields.mode'))
                            ->disabled(),
                        Forms\Components\TextInput::make('triggered_by')
                            ->label(__('app.crawler_runs.fields.triggered_by'))
                            ->disabled(),
                        Forms\Components\TextInput::make('exit_code')
                            ->label(__('app.crawler_runs.fields.exit_code'))
                            ->disabled(),
                        Forms\Components\DateTimePicker::make('started_at')
                            ->label(__('app.crawler_runs.fields.started_at'))
                            ->disabled(),
                        Forms\Components\DateTimePicker::make('finished_at')
                            ->label(__('app.crawler_runs.fields.finished_at'))
                            ->disabled(),
                        Forms\Components\Placeholder::make('duration')
                            ->label(__('app.crawler_runs.fields.duration'))
                            ->content(fn (?CrawlerRun $record): string => $record?->durationLabel() ?? '—'),
                    ])
                    ->columns(3),
                Forms\Components\Section::make(__('app.crawler_runs.sections.files'))
                    ->schema([
                        Forms\Components\TextInput::make('run_id')
                            ->label(__('app.crawler_runs.fields.run_id'))
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('run_directory')
                            ->label(__('app.crawler_runs.fields.run_directory'))
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('log_path')
                            ->label(__('app.crawler_runs.fields.log_path'))
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('error_message')
                            ->label(__('app.crawler_runs.fields.error_message'))
                            ->disabled()
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make(__('app.crawler_runs.sections.log'))
                    ->schema([
                        Forms\Components\View::make('filament.forms.components.log-preview')
                            ->viewData(fn (?CrawlerRun $record): array => [
                                'content' => $record?->readLogPreview() ?? '',
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('app.crawler_runs.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('source.name')
                    ->label(__('app.crawler_runs.fields.source'))
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('issue_date')
                    ->label(__('app.crawler_runs.fields.issue_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('app.crawler_runs.fields.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CrawlerRun::STATUS_SUCCEEDED => 'success',
                        CrawlerRun::STATUS_FAILED => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => __('app.crawler_runs.status.'.$state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('mode')
                    ->label(__('app.crawler_runs.fields.mode'))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('triggered_by')
                    ->label(__('app.crawler_runs.fields.triggered_by'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => __('app.crawler_runs.triggered_by.'.$state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('exit_code')
                    ->label(__('app.crawler_runs.fields.exit_code'))
                    ->badge()
                    ->color(fn (?string $state): string => (string) $state === '0' ? 'success' : 'gray')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('started_at')
                    ->label(__('app.crawler_runs.fields.started_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('finished_at')
                    ->label(__('app.crawler_runs.fields.finished_at'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('duration')
                    ->label(__('app.crawler_runs.fields.duration'))
                    ->state(fn (CrawlerRun $record): string => $record->durationLabel() ?? '—'),
                Tables\Columns\TextColumn::make('run_id')
                    ->label(__('app.crawler_runs.fields.run_id'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source_id')
                    ->label(__('app.crawler_runs.filters.source'))
                    ->relationship('source', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('app.crawler_runs.filters.status'))
                    ->options([
                        CrawlerRun::STATUS_RUNNING => __('app.crawler_runs.status.running'),
                        CrawlerRun::STATUS_SUCCEEDED => __('app.crawler_runs.status.succeeded'),
                        CrawlerRun::STATUS_FAILED => __('app.crawler_runs.status.failed'),
                    ]),
                Tables\Filters\SelectFilter::make('triggered_by')
                    ->label(__('app.crawler_runs.filters.triggered_by'))
                    ->options([
                        'manual' => __('app.crawler_runs.triggered_by.manual'),
                        'pipeline' => __('app.crawler_runs.triggered_by.pipeline'),
                    ]),
                Tables\Filters\Filter::make('issue_date')
                    ->label(__('app.crawler_runs.fields.issue_date'))
                    ->form([
                        Forms\Components\DatePicker::make('issue_date')
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['issue_date'] ?? null,
                        fn (Builder $query, string $issueDate): Builder => $query->whereDate('issue_date', $issueDate)
                    ))
                    ->indicateUsing(function (array $data): array {
                        if (empty($data['issue_date'])) {
                            return [];
                        }

                        return [
                            'issue_date' => __('app.crawler_runs.fields.issue_date').': '.Carbon::parse((string) $data['issue_date'])->toFormattedDateString(),
                        ];
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('download_log')
                        ->label(__('app.crawler_runs.actions.download_log'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (CrawlerRun $record): string => route('crawler-runs.log.download', $record))
                        ->visible(fn (CrawlerRun $record): bool => $record->hasLogFile()),
                ])->tooltip(__('app.common.actions')),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('source');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCrawlerRuns::route('/'),
            'view' => Pages\ViewCrawlerRun::route('/{record}'),
        ];
    }
}
