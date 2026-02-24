<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyJournalResource\Pages;
use App\Models\DailyJournal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DailyJournalResource extends Resource
{
    protected static ?string $model = DailyJournal::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?int $navigationSort = 6;

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
        return __('app.daily_journals.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.daily_journals.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.daily_journals.model_plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('app.daily_journals.sections.reference'))
                    ->schema([
                        Forms\Components\Select::make('source_id')
                            ->label(__('app.daily_journals.fields.source'))
                            ->relationship(
                                name: 'source',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->orderBy('name')
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),
                        Forms\Components\DatePicker::make('issue_date')
                            ->label(__('app.daily_journals.fields.issue_date'))
                            ->required()
                            ->native(false),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.daily_journals.sections.content'))
                    ->schema([
                        Forms\Components\TextInput::make('url')
                            ->label(__('app.daily_journals.fields.url'))
                            ->url()
                            ->required()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('description')
                            ->label(__('app.daily_journals.fields.description'))
                            ->required()
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
                    ->label(__('app.daily_journals.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('source.name')
                    ->label(__('app.daily_journals.fields.source'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('issue_date')
                    ->label(__('app.daily_journals.fields.issue_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('notices_count')
                    ->label(__('app.daily_journals.fields.notices'))
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->alignCenter()
                    ->url(fn (DailyJournal $record): string => NoticeResource::getUrl('index', [
                        'tableFilters' => [
                            'daily_journal_id' => [
                                'value' => (string) $record->id,
                            ],
                        ],
                    ])),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('app.daily_journals.fields.description'))
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\IconColumn::make('url')
                    ->label(__('app.daily_journals.fields.url'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (DailyJournal $record): string => $record->url)
                    ->openUrlInNewTab()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('app.daily_journals.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('issue_date_range')
                    ->label(__('app.daily_journals.filters.issue_date_range'))
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('app.daily_journals.filters.from'))
                            ->native(false),
                        Forms\Components\DatePicker::make('to')
                            ->label(__('app.daily_journals.filters.to'))
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('issue_date', '>=', $date)
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('issue_date', '<=', $date)
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (! empty($data['from'])) {
                            $indicators['from'] = __('app.daily_journals.filters.from_indicator', [
                                'date' => Carbon::parse($data['from'])->toFormattedDateString(),
                            ]);
                        }

                        if (! empty($data['to'])) {
                            $indicators['to'] = __('app.daily_journals.filters.to_indicator', [
                                'date' => Carbon::parse($data['to'])->toFormattedDateString(),
                            ]);
                        }

                        return $indicators;
                    }),
                Tables\Filters\SelectFilter::make('source_id')
                    ->label(__('app.daily_journals.filters.source'))
                    ->relationship('source', 'name')
                    ->searchable()
                    ->preload(),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('notices');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyJournals::route('/'),
            'create' => Pages\CreateDailyJournal::route('/create'),
            'view' => Pages\ViewDailyJournal::route('/{record}'),
            'edit' => Pages\EditDailyJournal::route('/{record}/edit'),
        ];
    }
}
