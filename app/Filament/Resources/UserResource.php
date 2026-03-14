<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\Auth\PasswordResetLinkSender;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 5;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->canManageUsers() ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->isPlatformAdmin()
            ? __('app.navigation.groups.customer_operations')
            : __('app.navigation.groups.workspace');
    }

    public static function getNavigationLabel(): string
    {
        return __('app.users.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('app.users.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.users.model_plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canManageUsers() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canManageUsers() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof User && (auth()->user()?->canManageUser($record) ?? false);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof User && (auth()->user()?->canManageUser($record) ?? false);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof User && (auth()->user()?->canManageUser($record) ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('app.users.sections.account'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('app.users.fields.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label(__('app.users.fields.email'))
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->label(__('app.users.fields.role'))
                            ->options(fn (): array => User::assignableRoleOptionsFor(auth()->user()))
                            ->required()
                            ->native(false)
                            ->in(array_keys(User::assignableRoleOptionsFor(auth()->user()))),
                        Forms\Components\Select::make('locale')
                            ->label(__('app.users.fields.locale'))
                            ->options(User::localeOptions())
                            ->required()
                            ->native(false)
                            ->in(User::supportedLocales()),
                        Forms\Components\Select::make('companies')
                            ->label(__('app.users.fields.companies'))
                            ->relationship(
                                'companies',
                                'name',
                                fn (Builder $query): Builder => static::applyManageableCompaniesScope($query)
                            )
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('password')
                            ->label(__('app.users.fields.password'))
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->maxLength(255)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->visible(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state)),
                    ])
                    ->columns(2),
                Forms\Components\Section::make(__('app.users.sections.notifications'))
                    ->schema([
                        Forms\Components\Select::make('notice_digest_frequency')
                            ->label(__('app.users.fields.notice_digest_frequency'))
                            ->options(User::noticeDigestFrequencyOptions())
                            ->required()
                            ->default(User::NOTICE_DIGEST_WEEKLY)
                            ->native(false),
                        Forms\Components\Toggle::make('notify_if_pending_tasks')
                            ->label(__('app.users.fields.notify_if_pending_tasks'))
                            ->default(true)
                            ->inline(false),
                        Forms\Components\Toggle::make('notify_if_new_relevant_notices')
                            ->label(__('app.users.fields.notify_if_new_relevant_notices'))
                            ->default(true)
                            ->inline(false),
                        Forms\Components\DateTimePicker::make('last_notice_digest_sent_at')
                            ->label(__('app.users.fields.last_notice_digest_sent_at'))
                            ->native(false)
                            ->disabled(),
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
                    ->label(__('app.users.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('app.users.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('app.users.fields.email'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->label(__('app.users.fields.role'))
                    ->formatStateUsing(fn (string $state): string => __('app.roles.' . $state))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('locale')
                    ->label(__('app.users.fields.locale'))
                    ->formatStateUsing(fn (string $state): string => __('app.locales.' . $state))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('companies_count')
                    ->label(__('app.users.fields.companies'))
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                Tables\Columns\TextColumn::make('notice_digest_frequency')
                    ->label(__('app.users.fields.notice_digest_frequency'))
                    ->formatStateUsing(fn (string $state): string => User::noticeDigestFrequencyOptions()[$state] ?? $state)
                    ->badge()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('notify_if_pending_tasks')
                    ->label(__('app.users.fields.notify_if_pending_tasks'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('notify_if_new_relevant_notices')
                    ->label(__('app.users.fields.notify_if_new_relevant_notices'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_notice_digest_sent_at')
                    ->label(__('app.users.fields.last_notice_digest_sent_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('app.users.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('force_reset_password')
                        ->label(__('app.users.actions.force_reset_password'))
                        ->icon('heroicon-o-key')
                        ->visible(fn (User $record): bool => auth()->user()?->canManageUser($record) ?? false)
                        ->requiresConfirmation()
                        ->action(function (User $record, PasswordResetLinkSender $sender): void {
                            $sender->invalidateAndSend($record);

                            Notification::make()
                                ->success()
                                ->title(__('app.users.actions.force_reset_password_success_title'))
                                ->body(__('app.users.actions.force_reset_password_success_body', [
                                    'email' => $record->email,
                                ]))
                                ->send();
                        }),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount('companies');
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isPlatformAdmin()) {
            return $query;
        }

        if (! $user->isCompanyAdmin()) {
            return $query->whereRaw('1 = 0');
        }

        $managedCompanyIds = $user->managedCompanyIds();

        return $query
            ->where('role', '!=', User::ROLE_ADMIN)
            ->whereHas('companies', fn (Builder $companyQuery): Builder => $companyQuery->whereIn('companies.id', $managedCompanyIds));
    }

    public static function sanitizeManagedUserData(array $data): array
    {
        $user = auth()->user();
        if (! $user) {
            return $data;
        }

        if (! $user->isPlatformAdmin()) {
            $allowedRoles = array_keys(User::assignableRoleOptionsFor($user));
            if (isset($data['role']) && ! in_array($data['role'], $allowedRoles, true)) {
                $data['role'] = User::ROLE_REGULAR;
            }
        }

        if ($user->isCompanyAdmin() && array_key_exists('companies', $data)) {
            $allowedCompanyIds = array_map('intval', $user->managedCompanyIds());
            $data['companies'] = collect((array) $data['companies'])
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => in_array($id, $allowedCompanyIds, true))
                ->values()
                ->all();
        }

        return $data;
    }

    private static function applyManageableCompaniesScope(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isPlatformAdmin()) {
            return $query->orderBy('name');
        }

        if (! $user->isCompanyAdmin()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn('companies.id', $user->managedCompanyIds())
            ->orderBy('name');
    }
}
