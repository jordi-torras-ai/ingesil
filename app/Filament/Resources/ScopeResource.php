<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\ScopeResource\Pages;
use App\Models\Scope;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

class ScopeResource extends Resource
{
    protected static ?string $model = Scope::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 11;

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
        return __('app.scope_catalog.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.scope_catalog.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.scope_catalog.model_plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('app.scope_catalog.sections.general'))
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label(__('app.scope_catalog.fields.code'))
                            ->required()
                            ->alphaDash()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('sort_order')
                            ->label(__('app.scope_catalog.fields.sort_order'))
                            ->numeric()
                            ->default(0)
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label(__('app.scope_catalog.fields.is_active'))
                            ->helperText(__('app.scope_catalog.fields.is_active_help'))
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.scope_catalog.sections.translations'))
                    ->schema(static::translationFields())
                    ->columns(1),
                Forms\Components\Section::make(__('app.scope_catalog.sections.analysis_prompt'))
                    ->schema([
                        Forms\Components\Placeholder::make('analysis_prompt_status')
                            ->label(__('app.scope_catalog.fields.analysis_prompt_status'))
                            ->content(fn (?Scope $record): string => $record?->hasAnalysisPrompt()
                                ? __('app.scope_catalog.prompt_status.ready')
                                : __('app.scope_catalog.prompt_status.missing'))
                            ->visible(fn (?Scope $record): bool => $record !== null),
                        Forms\Components\Placeholder::make('analysis_prompt_paths')
                            ->label(__('app.scope_catalog.fields.analysis_prompt_paths'))
                            ->content(function (?Scope $record): HtmlString {
                                if (! $record) {
                                    return new HtmlString('');
                                }

                                $paths = $record->analysisPromptPaths();

                                return new HtmlString(sprintf(
                                    '<div>%s <code>%s</code></div><div>%s <code>%s</code></div>',
                                    e(__('app.scope_catalog.fields.analysis_prompt_system')),
                                    e($paths['system']),
                                    e(__('app.scope_catalog.fields.analysis_prompt_user')),
                                    e($paths['user']),
                                ));
                            })
                            ->visible(fn (?Scope $record): bool => $record !== null)
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('company_analysis_prompt_status')
                            ->label(__('app.scope_catalog.fields.company_analysis_prompt_status'))
                            ->content(fn (?Scope $record): string => $record?->hasCompanyAnalysisPrompt()
                                ? __('app.scope_catalog.prompt_status.ready')
                                : __('app.scope_catalog.prompt_status.missing'))
                            ->visible(fn (?Scope $record): bool => $record !== null),
                        Forms\Components\Placeholder::make('company_analysis_prompt_paths')
                            ->label(__('app.scope_catalog.fields.company_analysis_prompt_paths'))
                            ->content(function (?Scope $record): HtmlString {
                                if (! $record) {
                                    return new HtmlString('');
                                }

                                $paths = $record->companyAnalysisPromptPaths();

                                return new HtmlString(sprintf(
                                    '<div>%s <code>%s</code></div><div>%s <code>%s</code></div>',
                                    e(__('app.scope_catalog.fields.analysis_prompt_system')),
                                    e($paths['system']),
                                    e(__('app.scope_catalog.fields.analysis_prompt_user')),
                                    e($paths['user']),
                                ));
                            })
                            ->visible(fn (?Scope $record): bool => $record !== null)
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->visible(fn (?Scope $record): bool => $record !== null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('app.scope_catalog.fields.code'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('app.scope_catalog.fields.sort_order'))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('app.scope_catalog.fields.is_active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('analysis_prompt_status')
                    ->label(__('app.scope_catalog.fields.analysis_prompt_status'))
                    ->state(fn (Scope $record): string => $record->hasAnalysisPrompt()
                        ? __('app.scope_catalog.prompt_status.ready')
                        : __('app.scope_catalog.prompt_status.missing'))
                    ->badge()
                    ->color(fn (Scope $record): string => $record->hasAnalysisPrompt() ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('company_analysis_prompt_status')
                    ->label(__('app.scope_catalog.fields.company_analysis_prompt_status'))
                    ->state(fn (Scope $record): string => $record->hasCompanyAnalysisPrompt()
                        ? __('app.scope_catalog.prompt_status.ready')
                        : __('app.scope_catalog.prompt_status.missing'))
                    ->badge()
                    ->color(fn (Scope $record): string => $record->hasCompanyAnalysisPrompt() ? 'success' : 'danger')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('features_count')
                    ->label(__('app.scope_catalog.fields.features'))
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('name_en')
                    ->label(__('app.scope_catalog.fields.name_en'))
                    ->state(fn (Scope $record): string => $record->name('en'))
                    ->wrap(),
                Tables\Columns\TextColumn::make('name_es')
                    ->label(__('app.scope_catalog.fields.name_es'))
                    ->state(fn (Scope $record): string => $record->name('es'))
                    ->wrap()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
                    ->iconButton()
                    ->icon('heroicon-m-ellipsis-vertical')
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
        return parent::getEloquentQuery()
            ->with(['translations'])
            ->withCount('features');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScopes::route('/'),
            'create' => Pages\CreateScope::route('/create'),
            'view' => Pages\ViewScope::route('/{record}'),
            'edit' => Pages\EditScope::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function translationFields(): array
    {
        return [
            static::translationGroup('en', __('app.scope_catalog.locales.en')),
            static::translationGroup('es', __('app.scope_catalog.locales.es')),
            static::translationGroup('ca', __('app.scope_catalog.locales.ca')),
        ];
    }

    public static function mutateDataBeforeSave(array $data): array
    {
        return Arr::except($data, ['translations']);
    }

    public static function syncTranslations(Scope $scope, array $translations): void
    {
        foreach (['en', 'es', 'ca'] as $locale) {
            $payload = $translations[$locale] ?? [];

            $scope->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'name' => trim((string) ($payload['name'] ?? '')) ?: $scope->code,
                    'description' => static::nullableString($payload['description'] ?? null),
                ],
            );
        }
    }

    /**
     * @return array<string, array{name: string, description: ?string}>
     */
    public static function extractTranslations(Scope $scope): array
    {
        $scope->loadMissing('translations');

        $translations = [];

        foreach (['en', 'es', 'ca'] as $locale) {
            $translation = $scope->translations->firstWhere('locale', $locale);

            $translations[$locale] = [
                'name' => $translation?->name ?? '',
                'description' => $translation?->description,
            ];
        }

        return $translations;
    }

    private static function translationGroup(string $locale, string $label): Forms\Components\Group
    {
        return Forms\Components\Group::make([
            Forms\Components\Fieldset::make($label)
                ->schema([
                    Forms\Components\TextInput::make("translations.{$locale}.name")
                        ->label(__('app.scope_catalog.fields.name'))
                        ->required($locale === 'en')
                        ->maxLength(255),
                    Forms\Components\Textarea::make("translations.{$locale}.description")
                        ->label(__('app.scope_catalog.fields.description'))
                        ->rows(3),
                ]),
        ]);
    }

    private static function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
