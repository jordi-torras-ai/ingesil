<?php

namespace App\Filament\Resources\CompanyNoticeAnalysisResource\RelationManagers;

use App\Models\CompanyNoticeAnalysisEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = null;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('app.company_notice_analysis_events.fields.created_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_type')
                    ->label(__('app.company_notice_analysis_events.fields.event_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        CompanyNoticeAnalysisEvent::TYPE_AI_PROCESSED => __('app.company_notice_analysis_events.types.ai_processed'),
                        CompanyNoticeAnalysisEvent::TYPE_USER_UPDATED => __('app.company_notice_analysis_events.types.user_updated'),
                        default => Str::headline($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        CompanyNoticeAnalysisEvent::TYPE_AI_PROCESSED => 'info',
                        CompanyNoticeAnalysisEvent::TYPE_USER_UPDATED => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('app.company_notice_analysis_events.fields.user'))
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('changes')
                    ->label(__('app.company_notice_analysis_events.fields.changes'))
                    ->html()
                    ->wrap()
                    ->state(fn (CompanyNoticeAnalysisEvent $record): string => $this->formatChanges($record)),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading(__('app.company_notice_analysis_events.empty_heading'))
            ->emptyStateDescription(__('app.company_notice_analysis_events.empty_description'));
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('app.company_notice_analysis_events.heading');
    }

    private function formatChanges(CompanyNoticeAnalysisEvent $record): string
    {
        $changes = $record->changes ?? [];

        if ($changes === []) {
            return '—';
        }

        $items = [];

        foreach ($changes as $field => $diff) {
            $items[] = sprintf(
                '<div><strong>%s</strong>: %s → %s</div>',
                e($this->fieldLabel($field)),
                e($this->formatValue(Arr::get($diff, 'old'))),
                e($this->formatValue(Arr::get($diff, 'new')))
            );
        }

        return implode('', $items);
    }

    private function fieldLabel(string $field): string
    {
        return __('app.company_notice_analyses.fields.'.$field) !== 'app.company_notice_analyses.fields.'.$field
            ? __('app.company_notice_analyses.fields.'.$field)
            : Str::headline($field);
    }

    private function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => __('app.company_notice_analysis_events.values.empty'),
            $value === true => __('app.common.yes'),
            $value === false => __('app.common.no'),
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            default => (string) $value,
        };
    }
}
