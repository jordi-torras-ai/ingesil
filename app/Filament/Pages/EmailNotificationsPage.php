<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class EmailNotificationsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'email-notifications';

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.email-notifications';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.groups.workspace');
    }

    public static function getNavigationLabel(): string
    {
        return __('app.email_notifications.navigation');
    }

    public function getTitle(): string
    {
        return __('app.email_notifications.title');
    }

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        $this->form->fill([
            'notice_digest_frequency' => $user->notice_digest_frequency,
            'notify_if_pending_tasks' => $user->notify_if_pending_tasks,
            'notify_if_new_relevant_notices' => $user->notify_if_new_relevant_notices,
            'last_notice_digest_sent_at' => $user->last_notice_digest_sent_at,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make(__('app.email_notifications.sections.preferences'))
                    ->description(__('app.email_notifications.sections.preferences_description'))
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
                        Forms\Components\Placeholder::make('last_notice_digest_sent_at')
                            ->label(__('app.users.fields.last_notice_digest_sent_at'))
                            ->content(fn (): string => Filament::auth()->user()?->last_notice_digest_sent_at?->format('Y-m-d H:i:s') ?? '—'),
                    ])
                    ->columns(2),
            ]);
    }

    public function save(): void
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        $data = $this->form->getState();

        $user->forceFill([
            'notice_digest_frequency' => $data['notice_digest_frequency'] ?? User::NOTICE_DIGEST_WEEKLY,
            'notify_if_pending_tasks' => (bool) ($data['notify_if_pending_tasks'] ?? false),
            'notify_if_new_relevant_notices' => (bool) ($data['notify_if_new_relevant_notices'] ?? false),
        ])->save();

        Notification::make()
            ->success()
            ->title(__('app.email_notifications.messages.saved'))
            ->send();

        $this->redirect(static::getUrl());
    }
}
