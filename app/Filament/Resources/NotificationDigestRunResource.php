<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationDigestRunResource\Pages;
use App\Models\NotificationDigestRun;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NotificationDigestRunResource extends Resource
{
    protected static ?string $model = NotificationDigestRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 8;

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->isPlatformAdmin();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.groups.customer_operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('app.notice_digests.navigation_runs');
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
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('app.notice_digests.sections.run'))
                ->schema([
                    Forms\Components\TextInput::make('user.name')
                        ->label(__('app.notice_digests.fields.user'))
                        ->formatStateUsing(fn (?string $state, NotificationDigestRun $record): string => $record->user ? sprintf('%s <%s>', $record->user->name, $record->user->email) : '—')
                        ->disabled()
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('frequency')
                        ->label(__('app.notice_digests.fields.frequency'))
                        ->formatStateUsing(fn (?string $state): string => User::noticeDigestFrequencyOptions()[$state ?? ''] ?? (string) $state)
                        ->disabled(),
                    Forms\Components\TextInput::make('locale')
                        ->label(__('app.notice_digests.fields.locale'))
                        ->formatStateUsing(fn (?string $state): string => User::localeOptions()[$state ?? ''] ?? strtoupper((string) $state))
                        ->disabled(),
                    Forms\Components\TextInput::make('status')
                        ->label(__('app.notice_digests.fields.status'))
                        ->disabled(),
                    Forms\Components\DateTimePicker::make('window_started_at')
                        ->label(__('app.notice_digests.fields.window_started_at'))
                        ->native(false)
                        ->disabled(),
                    Forms\Components\DateTimePicker::make('window_ended_at')
                        ->label(__('app.notice_digests.fields.window_ended_at'))
                        ->native(false)
                        ->disabled(),
                    Forms\Components\TextInput::make('pending_tasks_count')
                        ->label(__('app.notice_digests.fields.pending_tasks_count'))
                        ->disabled(),
                    Forms\Components\TextInput::make('new_relevant_count')
                        ->label(__('app.notice_digests.fields.new_relevant_count'))
                        ->disabled(),
                    Forms\Components\TextInput::make('completed_count')
                        ->label(__('app.notice_digests.fields.completed_count'))
                        ->disabled(),
                    Forms\Components\DateTimePicker::make('sent_at')
                        ->label(__('app.notice_digests.fields.sent_at'))
                        ->native(false)
                        ->disabled(),
                    Forms\Components\Textarea::make('error_message')
                        ->label(__('app.notice_digests.fields.error_message'))
                        ->rows(4)
                        ->disabled()
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
                    ->label(__('app.notice_digests.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('app.notice_digests.fields.user'))
                    ->state(fn (NotificationDigestRun $record): string => $record->user ? sprintf('%s <%s>', $record->user->name, $record->user->email) : '—')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('frequency')
                    ->label(__('app.notice_digests.fields.frequency'))
                    ->formatStateUsing(fn (string $state): string => User::noticeDigestFrequencyOptions()[$state] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('app.notice_digests.fields.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        NotificationDigestRun::STATUS_SENT => 'success',
                        NotificationDigestRun::STATUS_SKIPPED => 'gray',
                        NotificationDigestRun::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('pending_tasks_count')
                    ->label(__('app.notice_digests.fields.pending_tasks_count'))
                    ->badge(),
                Tables\Columns\TextColumn::make('new_relevant_count')
                    ->label(__('app.notice_digests.fields.new_relevant_count'))
                    ->badge(),
                Tables\Columns\TextColumn::make('completed_count')
                    ->label(__('app.notice_digests.fields.completed_count'))
                    ->badge(),
                Tables\Columns\TextColumn::make('sent_at')
                    ->label(__('app.notice_digests.fields.sent_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label(__('app.notice_digests.filters.user'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('frequency')
                    ->label(__('app.notice_digests.filters.frequency'))
                    ->options(User::noticeDigestFrequencyOptions()),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('app.notice_digests.filters.status'))
                    ->options([
                        NotificationDigestRun::STATUS_QUEUED => __('app.notice_digests.statuses.queued'),
                        NotificationDigestRun::STATUS_SENT => __('app.notice_digests.statuses.sent'),
                        NotificationDigestRun::STATUS_SKIPPED => __('app.notice_digests.statuses.skipped'),
                        NotificationDigestRun::STATUS_FAILED => __('app.notice_digests.statuses.failed'),
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationDigestRuns::route('/'),
            'view' => Pages\ViewNotificationDigestRun::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }
}
