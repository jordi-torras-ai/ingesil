<?php

namespace App\Console\Commands;

use App\Models\NotificationDigestRun;
use App\Models\User;
use App\Notifications\NoticeDigestNotification;
use App\Services\NoticeDigestBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendNoticeDigestsCommand extends Command
{
    protected $signature = 'notice-digests:send
        {--user= : Restrict to one user id}
        {--force : Ignore schedule windows and process matching users now}
        {--pretend : Build digests without sending emails}';

    protected $description = 'Send localized notice digest emails to users based on their notification preferences.';

    public function handle(NoticeDigestBuilder $builder): int
    {
        $timezone = (string) config('app.notifications.timezone', config('app.pipeline.timezone', 'Europe/Madrid'));
        $now = Carbon::now($timezone);

        $users = User::query()
            ->where('notice_digest_frequency', '!=', User::NOTICE_DIGEST_NEVER)
            ->whereHas('companies')
            ->when(
                filled($this->option('user')),
                fn ($query) => $query->whereKey((int) $this->option('user'))
            )
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->option('force') || $this->isDue($user, $now))
            ->values();

        $this->info(sprintf('Processing %d user(s) for notice digests.', $users->count()));

        foreach ($users as $user) {
            $summary = $builder->buildForUser($user, $now->copy());

            if (! $summary['should_send']) {
                $this->line(sprintf('Skipping user #%d (%s): nothing to report.', $user->id, $user->email));

                if ($this->option('pretend')) {
                    continue;
                }

                NotificationDigestRun::query()->create([
                    'user_id' => $user->id,
                    'frequency' => $user->notice_digest_frequency,
                    'locale' => $user->preferredLocaleForNotifications(),
                    'window_started_at' => $summary['window_started_at'],
                    'window_ended_at' => $summary['window_ended_at'],
                    'pending_tasks_count' => $summary['pending_count'],
                    'new_relevant_count' => $summary['new_relevant_count'],
                    'completed_count' => $summary['completed_count'],
                    'status' => NotificationDigestRun::STATUS_SKIPPED,
                ]);

                continue;
            }

            try {
                if ($this->option('pretend')) {
                    $this->info(sprintf(
                        'Pretend digest for user #%d (%s): pending=%d new=%d completed=%d',
                        $user->id,
                        $user->email,
                        $summary['pending_count'],
                        $summary['new_relevant_count'],
                        $summary['completed_count'],
                    ));

                    continue;
                }

                $user->notify((new NoticeDigestNotification($summary))->locale($user->preferredLocaleForNotifications()));

                NotificationDigestRun::query()->create([
                    'user_id' => $user->id,
                    'frequency' => $user->notice_digest_frequency,
                    'locale' => $user->preferredLocaleForNotifications(),
                    'window_started_at' => $summary['window_started_at'],
                    'window_ended_at' => $summary['window_ended_at'],
                    'pending_tasks_count' => $summary['pending_count'],
                    'new_relevant_count' => $summary['new_relevant_count'],
                    'completed_count' => $summary['completed_count'],
                    'status' => NotificationDigestRun::STATUS_SENT,
                    'sent_at' => $now,
                ]);

                $user->forceFill([
                    'last_notice_digest_sent_at' => $now,
                ])->save();

                $this->info(sprintf(
                    'Sent digest to user #%d (%s): pending=%d new=%d completed=%d',
                    $user->id,
                    $user->email,
                    $summary['pending_count'],
                    $summary['new_relevant_count'],
                    $summary['completed_count'],
                ));
            } catch (\Throwable $exception) {
                NotificationDigestRun::query()->create([
                    'user_id' => $user->id,
                    'frequency' => $user->notice_digest_frequency,
                    'locale' => $user->preferredLocaleForNotifications(),
                    'window_started_at' => $summary['window_started_at'],
                    'window_ended_at' => $summary['window_ended_at'],
                    'pending_tasks_count' => $summary['pending_count'],
                    'new_relevant_count' => $summary['new_relevant_count'],
                    'completed_count' => $summary['completed_count'],
                    'status' => NotificationDigestRun::STATUS_FAILED,
                    'error_message' => $exception->getMessage(),
                ]);

                report($exception);
                $this->error(sprintf('Failed for user #%d (%s): %s', $user->id, $user->email, $exception->getMessage()));
            }
        }

        return self::SUCCESS;
    }

    private function isDue(User $user, Carbon $now): bool
    {
        return match ($user->notice_digest_frequency) {
            User::NOTICE_DIGEST_DAILY => ! $user->last_notice_digest_sent_at || $user->last_notice_digest_sent_at->lt($now->copy()->startOfDay()),
            User::NOTICE_DIGEST_WEEKLY => $now->isMonday() && (! $user->last_notice_digest_sent_at || $user->last_notice_digest_sent_at->lt($now->copy()->startOfWeek(Carbon::MONDAY))),
            User::NOTICE_DIGEST_MONTHLY => $now->day === 1 && (! $user->last_notice_digest_sent_at || $user->last_notice_digest_sent_at->lt($now->copy()->startOfMonth())),
            default => false,
        };
    }
}
