<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NoticeDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        private readonly array $summary,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable instanceof User
            ? $notifiable->preferredLocaleForNotifications()
            : app()->getLocale();

        return (new MailMessage)
            ->locale($locale)
            ->subject(__('app.notice_digests.email.subject', [
                'pending' => $this->summary['pending_count'],
                'new' => $this->summary['new_relevant_count'],
            ], $locale))
            ->markdown('emails.notice-digest', [
                'summary' => $this->summary,
                'locale' => $locale,
                'notifiable' => $notifiable,
            ]);
    }
}
