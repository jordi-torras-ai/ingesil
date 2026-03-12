<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyResource\RelationManagers\FeatureAnswersRelationManager;
use App\Filament\Resources\CompanyResource\Pages;
use App\Models\CnaeCode;
use App\Models\Company;
use App\Models\Scope;
use App\Models\SpanishLegalForm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isAdmin() || $user->companies()->exists();
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
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
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isAdmin() || $record->users()->whereKey($user->id)->exists();
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('app.companies.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.companies.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.companies.model_plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('app.companies.sections.general'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('app.companies.fields.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('country')
                            ->label(__('app.companies.fields.country'))
                            ->options(Company::countryOptions())
                            ->default(Company::COUNTRY_SPAIN)
                            ->searchable()
                            ->native(false)
                            ->required(),
                        Forms\Components\TextInput::make('currency')
                            ->label(__('app.companies.fields.currency'))
                            ->default(Company::DEFAULT_CURRENCY)
                            ->required()
                            ->minLength(3)
                            ->maxLength(3)
                            ->datalist([
                                'EUR',
                                'USD',
                                'GBP',
                                'CHF',
                            ]),
                        Forms\Components\Textarea::make('address')
                            ->label(__('app.companies.fields.address'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.companies.sections.spain'))
                    ->schema([
                        Forms\Components\Select::make('spanish_legal_form_id')
                            ->label(__('app.companies.fields.spanish_legal_form'))
                            ->relationship('spanishLegalForm', 'name', fn (Builder $query): Builder => $query->orderBy('sort_order'))
                            ->getOptionLabelFromRecordUsing(fn (SpanishLegalForm $record): string => "{$record->code} — {$record->name}")
                            ->searchable(['code', 'name'])
                            ->native(false)
                            ->visible(fn (Forms\Get $get): bool => $get('country') === Company::COUNTRY_SPAIN),
                        Forms\Components\Select::make('cnae_code_id')
                            ->label(__('app.companies.fields.cnae_code'))
                            ->relationship('cnaeCode', 'title', fn (Builder $query): Builder => $query->orderBy('code'))
                            ->getOptionLabelFromRecordUsing(fn (CnaeCode $record): string => "{$record->code} — {$record->title}")
                            ->searchable(['code', 'title'])
                            ->native(false)
                            ->visible(fn (Forms\Get $get): bool => $get('country') === Company::COUNTRY_SPAIN),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.companies.sections.financials'))
                    ->schema([
                        Forms\Components\TextInput::make('yearly_revenue')
                            ->label(__('app.companies.fields.yearly_revenue'))
                            ->numeric()
                            ->prefix(fn (Forms\Get $get): string => (string) ($get('currency') ?: Company::DEFAULT_CURRENCY)),
                        Forms\Components\TextInput::make('total_assets')
                            ->label(__('app.companies.fields.total_assets'))
                            ->numeric()
                            ->prefix(fn (Forms\Get $get): string => (string) ($get('currency') ?: Company::DEFAULT_CURRENCY)),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.companies.sections.regulatory_scopes'))
                    ->schema([
                        Forms\Components\Select::make('scopes')
                            ->label(__('app.companies.fields.scopes'))
                            ->relationship('scopes', 'code', fn (Builder $query): Builder => $query->with('translations')->orderBy('sort_order'))
                            ->getOptionLabelFromRecordUsing(fn (Scope $record): string => $record->name())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make(__('app.companies.sections.assignments'))
                    ->schema([
                        Forms\Components\Select::make('users')
                            ->label(__('app.companies.fields.users'))
                            ->relationship('users', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ])
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('app.companies.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('app.companies.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->label(__('app.companies.fields.country'))
                    ->formatStateUsing(fn (string $state): string => Company::countryOptions()[$state] ?? strtoupper($state))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('spanishLegalForm.code')
                    ->label(__('app.companies.fields.spanish_legal_form'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('cnaeCode.code')
                    ->label(__('app.companies.fields.cnae_code'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('currency')
                    ->label(__('app.companies.fields.currency'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('yearly_revenue')
                    ->label(__('app.companies.fields.yearly_revenue'))
                    ->money(fn (Company $record): string => strtolower($record->currency))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total_assets')
                    ->label(__('app.companies.fields.total_assets'))
                    ->money(fn (Company $record): string => strtolower($record->currency))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->label(__('app.companies.fields.users'))
                    ->badge()
                    ->color('primary')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('scopes_count')
                    ->label(__('app.companies.fields.scopes'))
                    ->badge()
                    ->color('info')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('country')
                    ->label(__('app.companies.fields.country'))
                    ->options(Company::countryOptions()),
                Tables\Filters\SelectFilter::make('spanish_legal_form_id')
                    ->label(__('app.companies.fields.spanish_legal_form'))
                    ->relationship('spanishLegalForm', 'name'),
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount(['users', 'scopes']);

        if (auth()->user()?->isAdmin() ?? false) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $builder): Builder => $builder->whereKey(auth()->id()));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'view' => Pages\ViewCompany::route('/{record}'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int, class-string<RelationManager>>
     */
    public static function getRelations(): array
    {
        return [
            FeatureAnswersRelationManager::class,
        ];
    }
}
