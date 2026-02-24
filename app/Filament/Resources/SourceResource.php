<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SourceResource\Pages;
use App\Models\Source;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = 5;

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
        return __('app.sources.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.sources.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.sources.model_plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('app.sources.sections.general'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('app.sources.fields.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('slug')
                            ->label(__('app.sources.fields.slug'))
                            ->required()
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('start_at')
                            ->label(__('app.sources.fields.start_at'))
                            ->native(false),
                        Forms\Components\Textarea::make('description')
                            ->label(__('app.sources.fields.description'))
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.sources.sections.endpoints'))
                    ->schema([
                        Forms\Components\TextInput::make('base_url')
                            ->label(__('app.sources.fields.base_url'))
                            ->url()
                            ->required()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.sources.sections.notes'))
                    ->schema([
                        Forms\Components\Textarea::make('comments')
                            ->label(__('app.sources.fields.comments'))
                            ->rows(5)
                            ->nullable()
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
                    ->label(__('app.sources.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('app.sources.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('daily_journals_count')
                    ->label(__('app.sources.fields.daily_journals'))
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable()
                    ->url(fn (Source $record): string => DailyJournalResource::getUrl('index', [
                        'tableFilters' => [
                            'source_id' => [
                                'value' => (string) $record->id,
                            ],
                        ],
                    ])),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('app.sources.fields.description'))
                    ->limit(100)
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('app.sources.fields.slug'))
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('base_url')
                    ->label(__('app.sources.fields.base_url'))
                    ->url(fn (Source $record): string => $record->base_url)
                    ->openUrlInNewTab()
                    ->searchable()
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('start_at')
                    ->label(__('app.sources.fields.start_at'))
                    ->date()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('app.sources.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
                    ->tooltip(__('app.common.actions')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withCount('dailyJournals');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSources::route('/'),
            'create' => Pages\CreateSource::route('/create'),
            'view' => Pages\ViewSource::route('/{record}'),
            'edit' => Pages\EditSource::route('/{record}/edit'),
        ];
    }
}
