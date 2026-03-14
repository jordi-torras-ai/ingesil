<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Models\Company;
use App\Models\CompanyFeatureAnswer;
use App\Models\Feature;
use App\Models\FeatureOption;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FeatureAnswersRelationManager extends RelationManager
{
    protected static string $relationship = 'featureAnswers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('app.company_feature_answers.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        if (! $user || ! $ownerRecord instanceof Company) {
            return false;
        }

        return $user->isPlatformAdmin() || $ownerRecord->users()->whereKey($user->id)->exists();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('feature_id')
                    ->label(__('app.company_feature_answers.fields.feature'))
                    ->options(fn (): array => $this->featureOptions())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required()
                    ->afterStateUpdated(function (Forms\Set $set): void {
                        $set('value_boolean', null);
                        $set('value_text', null);
                        $set('feature_option_id', null);
                    })
                    ->helperText(fn (Forms\Get $get): ?string => $this->featureHelpText($get('feature_id'))),
                Forms\Components\Radio::make('value_boolean')
                    ->label(__('app.company_feature_answers.fields.answer'))
                    ->options([
                        1 => __('app.common.yes'),
                        0 => __('app.common.no'),
                    ])
                    ->visible(fn (Forms\Get $get): bool => $this->selectedFeatureDataType($get('feature_id')) === Feature::DATA_TYPE_BOOLEAN)
                    ->required(fn (Forms\Get $get): bool => $this->selectedFeatureDataType($get('feature_id')) === Feature::DATA_TYPE_BOOLEAN),
                Forms\Components\Select::make('feature_option_id')
                    ->label(__('app.company_feature_answers.fields.answer'))
                    ->options(fn (Forms\Get $get): array => $this->featureChoiceOptions($get('feature_id')))
                    ->searchable()
                    ->preload()
                    ->visible(fn (Forms\Get $get): bool => $this->selectedFeatureDataType($get('feature_id')) === Feature::DATA_TYPE_SINGLE_CHOICE)
                    ->required(fn (Forms\Get $get): bool => $this->selectedFeatureDataType($get('feature_id')) === Feature::DATA_TYPE_SINGLE_CHOICE),
                Forms\Components\Textarea::make('value_text')
                    ->label(__('app.company_feature_answers.fields.answer'))
                    ->rows(4)
                    ->visible(fn (Forms\Get $get): bool => $this->selectedFeatureDataType($get('feature_id')) === Feature::DATA_TYPE_TEXT)
                    ->required(fn (Forms\Get $get): bool => $this->selectedFeatureDataType($get('feature_id')) === Feature::DATA_TYPE_TEXT),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'feature.translations',
                'feature.scope.translations',
                'featureOption.translations',
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('feature.scope.code')
                    ->label(__('app.company_feature_answers.fields.scope'))
                    ->formatStateUsing(fn (CompanyFeatureAnswer $record): string => $record->feature?->scope?->name() ?? '—')
                    ->badge(),
                Tables\Columns\TextColumn::make('feature.code')
                    ->label(__('app.company_feature_answers.fields.feature'))
                    ->formatStateUsing(fn (CompanyFeatureAnswer $record): string => $record->feature?->label() ?? '—')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('feature.translations', function (Builder $builder) use ($search): void {
                        $builder->where('label', 'ilike', "%{$search}%");
                    })),
                Tables\Columns\TextColumn::make('answer')
                    ->label(__('app.company_feature_answers.fields.answer'))
                    ->state(fn (CompanyFeatureAnswer $record): string => $record->answerLabel())
                    ->wrap(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('app.company_feature_answers.fields.updated_at'))
                    ->since()
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->visible(fn (): bool => $this->canManageAnswers() && $this->getOwnerRecord()->scopes()->exists()),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->visible(fn (): bool => $this->canManageAnswers()),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (): bool => $this->canManageAnswers()),
                ])
                    ->iconButton()
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip(__('app.common.actions')),
            ])
            ->emptyStateHeading(__('app.company_feature_answers.empty_state.heading'))
            ->emptyStateDescription(__('app.company_feature_answers.empty_state.description'));
    }

    /**
     * @return array<int, string>
     */
    private function featureOptions(): array
    {
        return Feature::query()
            ->with(['translations', 'scope.translations'])
            ->whereIn('scope_id', $this->getOwnerRecord()->scopes()->pluck('scopes.id'))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (Feature $feature): array => [
                $feature->id => sprintf('%s — %s', $feature->scope->name(), $feature->label()),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function featureChoiceOptions(mixed $featureId): array
    {
        if (! is_numeric($featureId)) {
            return [];
        }

        return FeatureOption::query()
            ->with('translations')
            ->where('feature_id', (int) $featureId)
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (FeatureOption $option): array => [
                $option->id => $option->label(),
            ])
            ->all();
    }

    private function selectedFeatureDataType(mixed $featureId): ?string
    {
        if (! is_numeric($featureId)) {
            return null;
        }

        return Feature::query()->whereKey((int) $featureId)->value('data_type');
    }

    private function featureHelpText(mixed $featureId): ?string
    {
        if (! is_numeric($featureId)) {
            return null;
        }

        return Feature::query()
            ->with('translations')
            ->find((int) $featureId)
            ?->helpText();
    }

    private function canManageAnswers(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isPlatformAdmin()
            || ($user->isCompanyAdmin() && $this->getOwnerRecord()->users()->whereKey($user->id)->exists());
    }
}
