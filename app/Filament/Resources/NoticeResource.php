<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NoticeResource\Pages;
use App\Models\DailyJournal;
use App\Models\Notice;
use App\Models\Source;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class NoticeResource extends Resource
{
    protected static ?string $model = Notice::class;

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
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
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
        return __('app.notices.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.notices.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.notices.model_plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('app.notices.sections.reference'))
                    ->schema([
                        Forms\Components\Select::make('daily_journal_id')
                            ->label(__('app.notices.fields.daily_journal'))
                            ->relationship(
                                name: 'dailyJournal',
                                titleAttribute: 'description',
                                modifyQueryUsing: fn (Builder $query) => $query->orderByDesc('id')
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),
                        Forms\Components\Textarea::make('title')
                            ->label(__('app.notices.fields.title'))
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('category')
                            ->label(__('app.notices.fields.category')),
                        Forms\Components\TextInput::make('department')
                            ->label(__('app.notices.fields.department')),
                        Forms\Components\TextInput::make('url')
                            ->label(__('app.notices.fields.url'))
                            ->url()
                            ->maxLength(2048),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.notices.sections.content'))
                    ->schema([
                        Forms\Components\Textarea::make('content')
                            ->label(__('app.notices.fields.content'))
                            ->rows(8)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('extra_info')
                            ->label(__('app.notices.fields.extra_info'))
                            ->rows(5)
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
                    ->label(__('app.notices.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('dailyJournal.issue_date')
                    ->label(__('app.notices.fields.daily_journal'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('app.notices.fields.title'))
                    ->searchable()
                    ->sortable()
                    ->limit(80)
                    ->tooltip(fn (Notice $record): string => $record->title),
                Tables\Columns\TextColumn::make('category')
                    ->label(__('app.notices.fields.category'))
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('department')
                    ->label(__('app.notices.fields.department'))
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\IconColumn::make('url')
                    ->label(__('app.notices.fields.url'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Notice $record): ?string => $record->url)
                    ->openUrlInNewTab()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('app.notices.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('daily_journal_id')
                    ->label(__('app.notices.filters.daily_journal'))
                    ->options(function (): array {
                        return DailyJournal::query()
                            ->orderByDesc('issue_date')
                            ->orderByDesc('id')
                            ->get(['id', 'issue_date'])
                            ->mapWithKeys(fn (DailyJournal $dailyJournal): array => [
                                (string) $dailyJournal->id => sprintf(
                                    '#%d - %s',
                                    $dailyJournal->id,
                                    optional($dailyJournal->issue_date)?->format('Y-m-d') ?? ''
                                ),
                            ])
                            ->all();
                    })
                    ->searchable(),
                Tables\Filters\Filter::make('issue_date_range')
                    ->label(__('app.notices.filters.issue_date_range'))
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('app.notices.filters.from'))
                            ->native(false),
                        Forms\Components\DatePicker::make('to')
                            ->label(__('app.notices.filters.to'))
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereHas(
                                    'dailyJournal',
                                    fn (Builder $dailyJournalQuery): Builder => $dailyJournalQuery->whereDate('issue_date', '>=', $date)
                                )
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereHas(
                                    'dailyJournal',
                                    fn (Builder $dailyJournalQuery): Builder => $dailyJournalQuery->whereDate('issue_date', '<=', $date)
                                )
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (! empty($data['from'])) {
                            $indicators['from'] = __('app.notices.filters.from_indicator', [
                                'date' => Carbon::parse($data['from'])->toFormattedDateString(),
                            ]);
                        }

                        if (! empty($data['to'])) {
                            $indicators['to'] = __('app.notices.filters.to_indicator', [
                                'date' => Carbon::parse($data['to'])->toFormattedDateString(),
                            ]);
                        }

                        return $indicators;
                    }),
                Tables\Filters\SelectFilter::make('source_id')
                    ->label(__('app.notices.filters.source'))
                    ->options(function (): array {
                        return Source::query()
                            ->select('sources.id', 'sources.name')
                            ->selectRaw('COUNT(notices.id) as aggregate')
                            ->join('daily_journals', 'daily_journals.source_id', '=', 'sources.id')
                            ->join('notices', 'notices.daily_journal_id', '=', 'daily_journals.id')
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
                            'dailyJournal',
                            fn (Builder $dailyJournalQuery): Builder => $dailyJournalQuery->where('source_id', $sourceId)
                        )
                    ))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('category')
                    ->label(__('app.notices.filters.category'))
                    ->options(function (): array {
                        return Notice::query()
                            ->select('category')
                            ->selectRaw('COUNT(*) as aggregate')
                            ->whereNotNull('category')
                            ->where('category', '<>', '')
                            ->groupBy('category')
                            ->orderBy('category')
                            ->get()
                            ->mapWithKeys(fn (Notice $notice): array => [
                                (string) $notice->category => sprintf('%s (%d)', $notice->category, $notice->aggregate),
                            ])
                            ->all();
                    })
                    ->searchable(),
                Tables\Filters\SelectFilter::make('department')
                    ->label(__('app.notices.filters.department'))
                    ->options(function (): array {
                        return Notice::query()
                            ->select('department')
                            ->selectRaw('COUNT(*) as aggregate')
                            ->whereNotNull('department')
                            ->where('department', '<>', '')
                            ->groupBy('department')
                            ->orderBy('department')
                            ->get()
                            ->mapWithKeys(fn (Notice $notice): array => [
                                (string) $notice->department => sprintf('%s (%d)', $notice->department, $notice->aggregate),
                            ])
                            ->all();
                    })
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])->tooltip(__('app.common.actions')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotices::route('/'),
            'create' => Pages\CreateNotice::route('/create'),
            'view' => Pages\ViewNotice::route('/{record}'),
            'edit' => Pages\EditNotice::route('/{record}/edit'),
        ];
    }
}
