<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FeatureResource\Pages;
use App\Models\Feature;
use App\Models\FeatureOption;
use App\Models\Scope;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class FeatureResource extends Resource
{
    protected static ?string $model = Feature::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.groups.catalog');
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
        return __('app.feature_catalog.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.feature_catalog.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.feature_catalog.model_plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('app.feature_catalog.sections.general'))
                    ->schema([
                        Forms\Components\Select::make('scope_id')
                            ->label(__('app.feature_catalog.fields.scope'))
                            ->relationship('scope', 'code', fn (Builder $query): Builder => $query->with('translations')->orderBy('sort_order'))
                            ->getOptionLabelFromRecordUsing(fn (Scope $record): string => $record->name())
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('code')
                            ->label(__('app.feature_catalog.fields.code'))
                            ->required()
                            ->alphaDash()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('data_type')
                            ->label(__('app.feature_catalog.fields.data_type'))
                            ->options(Feature::dataTypeOptions())
                            ->required()
                            ->native(false)
                            ->live(),
                        Forms\Components\TextInput::make('sort_order')
                            ->label(__('app.feature_catalog.fields.sort_order'))
                            ->numeric()
                            ->required()
                            ->default(0),
                        Forms\Components\Toggle::make('is_active')
                            ->label(__('app.feature_catalog.fields.is_active'))
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.feature_catalog.sections.translations'))
                    ->schema(static::translationFields()),
                Forms\Components\Section::make(__('app.feature_catalog.sections.options'))
                    ->schema([
                        Forms\Components\Repeater::make('options_payload')
                            ->label(__('app.feature_catalog.fields.options'))
                            ->schema([
                                Forms\Components\TextInput::make('code')
                                    ->label(__('app.feature_catalog.fields.option_code'))
                                    ->required()
                                    ->alphaDash()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('sort_order')
                                    ->label(__('app.feature_catalog.fields.sort_order'))
                                    ->numeric()
                                    ->default(0)
                                    ->required(),
                                static::optionTranslationFieldset('en', __('app.feature_catalog.locales.en')),
                                static::optionTranslationFieldset('es', __('app.feature_catalog.locales.es')),
                                static::optionTranslationFieldset('ca', __('app.feature_catalog.locales.ca')),
                            ])
                            ->columns(2)
                            ->default([])
                            ->visible(fn (Forms\Get $get): bool => $get('data_type') === Feature::DATA_TYPE_SINGLE_CHOICE)
                            ->collapsible(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('scope.code')
                    ->label(__('app.feature_catalog.fields.scope'))
                    ->formatStateUsing(fn (Feature $record): string => $record->scope?->name() ?? '—')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('code')
                    ->label(__('app.feature_catalog.fields.code'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('label_en')
                    ->label(__('app.feature_catalog.fields.label_en'))
                    ->state(fn (Feature $record): string => $record->label('en'))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('translations', function (Builder $builder) use ($search): void {
                        $builder->where('label', 'ilike', "%{$search}%");
                    })),
                Tables\Columns\TextColumn::make('data_type')
                    ->label(__('app.feature_catalog.fields.data_type'))
                    ->formatStateUsing(fn (string $state): string => Feature::dataTypeOptions()[$state] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('options_count')
                    ->label(__('app.feature_catalog.fields.options'))
                    ->badge()
                    ->color('info'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('app.feature_catalog.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('scope_id')
                    ->label(__('app.feature_catalog.fields.scope'))
                    ->relationship('scope', 'code'),
                Tables\Filters\SelectFilter::make('data_type')
                    ->label(__('app.feature_catalog.fields.data_type'))
                    ->options(Feature::dataTypeOptions()),
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
            ->with(['scope.translations', 'translations'])
            ->withCount('options');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeatures::route('/'),
            'create' => Pages\CreateFeature::route('/create'),
            'view' => Pages\ViewFeature::route('/{record}'),
            'edit' => Pages\EditFeature::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function translationFields(): array
    {
        return [
            static::translationGroup('en', __('app.feature_catalog.locales.en')),
            static::translationGroup('es', __('app.feature_catalog.locales.es')),
            static::translationGroup('ca', __('app.feature_catalog.locales.ca')),
        ];
    }

    public static function mutateDataBeforeSave(array $data): array
    {
        return Arr::except($data, ['translations', 'options_payload']);
    }

    public static function syncTranslations(Feature $feature, array $translations): void
    {
        foreach (['en', 'es', 'ca'] as $locale) {
            $payload = $translations[$locale] ?? [];

            $feature->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'label' => trim((string) ($payload['label'] ?? '')) ?: $feature->code,
                    'help_text' => static::nullableString($payload['help_text'] ?? null),
                ],
            );
        }
    }

    /**
     * @return array<string, array{label: string, help_text: ?string}>
     */
    public static function extractTranslations(Feature $feature): array
    {
        $feature->loadMissing('translations');

        $translations = [];

        foreach (['en', 'es', 'ca'] as $locale) {
            $translation = $feature->translations->firstWhere('locale', $locale);

            $translations[$locale] = [
                'label' => $translation?->label ?? '',
                'help_text' => $translation?->help_text,
            ];
        }

        return $translations;
    }

    /**
     * @param  array<int, array<string, mixed>>  $optionsPayload
     */
    public static function syncOptions(Feature $feature, array $optionsPayload): void
    {
        $idsToKeep = [];

        foreach ($optionsPayload as $index => $payload) {
            $code = trim((string) ($payload['code'] ?? ''));

            if ($code === '') {
                continue;
            }

            /** @var FeatureOption $option */
            $option = $feature->options()->updateOrCreate(
                ['code' => $code],
                ['sort_order' => (int) ($payload['sort_order'] ?? $index)]
            );

            $idsToKeep[] = $option->id;

            foreach (['en', 'es', 'ca'] as $locale) {
                $translation = $payload['translations'][$locale] ?? [];

                $option->translations()->updateOrCreate(
                    ['locale' => $locale],
                    ['label' => trim((string) ($translation['label'] ?? '')) ?: $code],
                );
            }
        }

        $feature->options()
            ->whereNotIn('id', $idsToKeep === [] ? [0] : $idsToKeep)
            ->get()
            ->each
            ->delete();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function extractOptions(Feature $feature): array
    {
        $feature->loadMissing('options.translations');

        return $feature->options
            ->sortBy('sort_order')
            ->values()
            ->map(function (FeatureOption $option): array {
                $translations = [];

                foreach (['en', 'es', 'ca'] as $locale) {
                    $translation = $option->translations->firstWhere('locale', $locale);
                    $translations[$locale] = [
                        'label' => $translation?->label ?? '',
                    ];
                }

                return [
                    'code' => $option->code,
                    'sort_order' => $option->sort_order,
                    'translations' => $translations,
                ];
            })
            ->all();
    }

    private static function translationGroup(string $locale, string $label): Forms\Components\Group
    {
        return Forms\Components\Group::make([
            Forms\Components\Fieldset::make($label)
                ->schema([
                    Forms\Components\TextInput::make("translations.{$locale}.label")
                        ->label(__('app.feature_catalog.fields.label'))
                        ->required($locale === 'en')
                        ->maxLength(255),
                    Forms\Components\Textarea::make("translations.{$locale}.help_text")
                        ->label(__('app.feature_catalog.fields.help_text'))
                        ->rows(3),
                ]),
        ]);
    }

    private static function optionTranslationFieldset(string $locale, string $label): Forms\Components\Fieldset
    {
        return Forms\Components\Fieldset::make($label)
            ->schema([
                Forms\Components\TextInput::make("translations.{$locale}.label")
                    ->label(__('app.feature_catalog.fields.option_label'))
                    ->required($locale === 'en')
                    ->maxLength(255),
            ]);
    }

    private static function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
