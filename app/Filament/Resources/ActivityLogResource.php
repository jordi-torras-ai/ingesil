<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\Company;
use App\Models\CompanyFeatureAnswer;
use App\Models\CompanyNoticeAnalysis;
use App\Models\CompanyScopeSubscription;
use App\Models\Feature;
use App\Models\Scope;
use App\Models\Source;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 7;

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
        return __('app.activity_log.navigation');
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
            Forms\Components\Section::make(__('app.activity_log.sections.details'))
                ->schema([
                    Forms\Components\TextInput::make('created_at')
                        ->label(__('app.activity_log.fields.created_at'))
                        ->formatStateUsing(fn ($state): string => $state ? Carbon::parse((string) $state)->format('Y-m-d H:i:s') : '—')
                        ->disabled(),
                    Forms\Components\TextInput::make('causer.name')
                        ->label(__('app.activity_log.fields.causer'))
                        ->formatStateUsing(fn (?string $state, Activity $record): string => static::causerLabel($record))
                        ->disabled(),
                    Forms\Components\TextInput::make('event')
                        ->label(__('app.activity_log.fields.event'))
                        ->formatStateUsing(fn (?string $state, Activity $record): string => static::eventLabel($record))
                        ->disabled(),
                    Forms\Components\TextInput::make('subject_type')
                        ->label(__('app.activity_log.fields.subject'))
                        ->formatStateUsing(fn (?string $state, Activity $record): string => static::subjectTypeLabel($record))
                        ->disabled(),
                    Forms\Components\TextInput::make('subject_id')
                        ->label(__('app.activity_log.fields.subject_record'))
                        ->formatStateUsing(fn (?string $state, Activity $record): string => static::subjectLabel($record))
                        ->disabled()
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('properties')
                        ->label(__('app.activity_log.fields.changes'))
                        ->formatStateUsing(fn ($state, Activity $record): string => static::changesSummary($record, PHP_EOL))
                        ->rows(10)
                        ->disabled()
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('app.activity_log.fields.created_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label(__('app.activity_log.fields.causer'))
                    ->state(fn (Activity $record): string => static::causerLabel($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHasMorph(
                        'causer',
                        [User::class],
                        fn (Builder $builder) => $builder
                            ->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                    )),
                Tables\Columns\TextColumn::make('event')
                    ->label(__('app.activity_log.fields.event'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state, Activity $record): string => static::eventLabel($record))
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label(__('app.activity_log.fields.subject'))
                    ->badge()
                    ->state(fn (Activity $record): string => static::subjectTypeLabel($record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('subject_id')
                    ->label(__('app.activity_log.fields.subject_record'))
                    ->state(fn (Activity $record): string => static::subjectLabel($record))
                    ->wrap(),
                Tables\Columns\TextColumn::make('properties')
                    ->label(__('app.activity_log.fields.changes'))
                    ->state(fn (Activity $record): string => static::changesSummary($record))
                    ->limit(120)
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('causer_id')
                    ->label(__('app.activity_log.filters.causer'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $userId): Builder => $query
                            ->where('causer_type', User::class)
                            ->where('causer_id', $userId)
                    )),
                Tables\Filters\SelectFilter::make('event')
                    ->label(__('app.activity_log.filters.event'))
                    ->options([
                        'created' => __('app.activity_log.events.created'),
                        'updated' => __('app.activity_log.events.updated'),
                        'deleted' => __('app.activity_log.events.deleted'),
                    ]),
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label(__('app.activity_log.filters.subject'))
                    ->options(static::subjectTypeOptions()),
                Tables\Filters\Filter::make('date_range')
                    ->label(__('app.activity_log.filters.date_range'))
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('app.activity_log.filters.from'))
                            ->native(false),
                        Forms\Components\DatePicker::make('to')
                            ->label(__('app.activity_log.filters.to'))
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('log_name', 'audit')
            ->with(['causer', 'subject']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function subjectTypeOptions(): array
    {
        return collect([
            User::class,
            Company::class,
            CompanyScopeSubscription::class,
            CompanyFeatureAnswer::class,
            CompanyNoticeAnalysis::class,
            Scope::class,
            Feature::class,
            Source::class,
        ])->mapWithKeys(fn (string $class): array => [$class => Str::headline(class_basename($class))])
            ->all();
    }

    private static function causerLabel(Activity $record): string
    {
        $causer = $record->causer;

        if ($causer instanceof User) {
            return trim(sprintf('%s <%s>', $causer->name, $causer->email));
        }

        return $causer ? Str::headline(class_basename($causer::class)) : __('app.activity_log.values.system');
    }

    private static function eventLabel(Activity $record): string
    {
        return __('app.activity_log.events.'.$record->event) !== 'app.activity_log.events.'.$record->event
            ? __('app.activity_log.events.'.$record->event)
            : Str::headline((string) $record->event);
    }

    private static function subjectTypeLabel(Activity $record): string
    {
        return Str::headline(class_basename((string) $record->subject_type));
    }

    private static function subjectLabel(Activity $record): string
    {
        $subject = $record->subject;

        return match (true) {
            $subject instanceof User => sprintf('%s <%s>', $subject->name, $subject->email),
            $subject instanceof Company => $subject->name,
            $subject instanceof CompanyScopeSubscription => sprintf(
                '%s — %s — %s',
                $subject->company?->name ?? '—',
                $subject->scope?->name() ?? '—',
                $subject->localeLabel()
            ),
            $subject instanceof CompanyFeatureAnswer => sprintf(
                '%s — %s',
                $subject->company?->name ?? '—',
                $subject->feature?->label() ?? '—'
            ),
            $subject instanceof CompanyNoticeAnalysis => Str::limit($subject->noticeAnalysis?->notice?->title ?? '—', 120),
            $subject instanceof Scope => $subject->name(),
            $subject instanceof Feature => $subject->label(),
            $subject instanceof Source => $subject->name,
            $subject !== null => sprintf('%s #%s', Str::headline(class_basename($subject::class)), $subject->getKey()),
            default => sprintf('%s #%s', static::subjectTypeLabel($record), $record->subject_id ?? '—'),
        };
    }

    private static function changesSummary(Activity $record, string $separator = ' | '): string
    {
        $changes = $record->changes();
        $attributes = $changes->get('attributes', []);
        $old = $changes->get('old', []);

        if ($attributes === [] && $old === []) {
            return '—';
        }

        $rows = new Collection();

        foreach ($attributes as $field => $newValue) {
            $rows->push(sprintf(
                '%s: %s → %s',
                Str::headline((string) $field),
                static::formatValue($old[$field] ?? null),
                static::formatValue($newValue)
            ));
        }

        return $rows->implode($separator);
    }

    private static function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => __('app.activity_log.values.empty'),
            $value === true => __('app.common.yes'),
            $value === false => __('app.common.no'),
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            default => (string) $value,
        };
    }
}
