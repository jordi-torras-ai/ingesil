<?php

namespace App\Services;

use App\Jobs\ProcessNoticeAnalysis;
use App\Models\Notice;
use App\Models\NoticeAnalysis;
use App\Models\NoticeAnalysisRun;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NoticeAnalysisRunner
{
    public function createRunForIssueDate(
        string|CarbonInterface $issueDate,
        ?int $requestedByUserId = null,
        ?string $locale = null,
    ): NoticeAnalysisRun
    {
        $normalizedIssueDate = $issueDate instanceof CarbonInterface
            ? $issueDate->copy()->startOfDay()
            : Carbon::parse($issueDate)->startOfDay();
        $normalizedLocale = $this->normalizeLocale($locale);

        return NoticeAnalysisRun::query()->create([
            'status' => NoticeAnalysisRun::STATUS_QUEUED,
            'requested_by_user_id' => $requestedByUserId,
            'issue_date' => $normalizedIssueDate->toDateString(),
            'locale' => $normalizedLocale,
            'total_notices' => 0,
            'processed_notices' => 0,
            'sent_count' => 0,
            'ignored_count' => 0,
            'failed_count' => 0,
            'model' => (string) config('services.openai.api_model', 'gpt-5-mini'),
            'system_prompt_path' => (string) config('services.openai.notice_analysis_system_prompt', 'ai-prompts/notice-analysis-system.md'),
            'user_prompt_path' => (string) config('services.openai.notice_analysis_user_prompt', 'ai-prompts/notice-analysis-user.md'),
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    public function dispatchForIssueDate(
        string|CarbonInterface $issueDate,
        ?int $requestedByUserId = null,
        ?string $locale = null,
    ): NoticeAnalysisRun
    {
        $run = $this->createRunForIssueDate($issueDate, $requestedByUserId, $locale);
        return $this->dispatchRun($run);
    }

    public function dispatchRun(NoticeAnalysisRun $run): NoticeAnalysisRun
    {
        $issueDate = $run->issue_date?->toDateString();
        if (! $issueDate) {
            throw new RuntimeException('Run has no issue date.');
        }

        $noticeIds = Notice::query()
            ->select('notices.id')
            ->join('daily_journals', 'daily_journals.id', '=', 'notices.daily_journal_id')
            ->whereDate('daily_journals.issue_date', $issueDate)
            ->orderBy('notices.id')
            ->pluck('notices.id')
            ->map(fn (int $id): int => (int) $id)
            ->all();

        $total = count($noticeIds);
        $now = Carbon::now();

        $updatedRun = DB::transaction(function () use ($run, $noticeIds, $issueDate, $total, $now): NoticeAnalysisRun {
            /** @var NoticeAnalysisRun $lockedRun */
            $lockedRun = NoticeAnalysisRun::query()->lockForUpdate()->findOrFail($run->id);

            $existingNoticeIds = NoticeAnalysis::query()
                ->where('notice_analysis_run_id', $lockedRun->id)
                ->pluck('notice_id')
                ->map(fn (int $id): int => (int) $id)
                ->all();

            $missingNoticeIds = array_values(array_diff($noticeIds, $existingNoticeIds));
            if ($missingNoticeIds !== []) {
                $rows = [];
                foreach ($missingNoticeIds as $noticeId) {
                    $rows[] = [
                        'notice_analysis_run_id' => $lockedRun->id,
                        'notice_id' => $noticeId,
                        'status' => NoticeAnalysis::STATUS_QUEUED,
                        'model' => (string) config('services.openai.api_model', 'gpt-5-mini'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                NoticeAnalysis::query()->insert($rows);
            }

            if ($noticeIds === []) {
                NoticeAnalysis::query()->where('notice_analysis_run_id', $lockedRun->id)->delete();
            } else {
                NoticeAnalysis::query()
                    ->where('notice_analysis_run_id', $lockedRun->id)
                    ->whereNotIn('notice_id', $noticeIds)
                    ->delete();
            }

            NoticeAnalysis::query()
                ->where('notice_analysis_run_id', $lockedRun->id)
                ->where('status', '!=', NoticeAnalysis::STATUS_DONE)
                ->update([
                    'status' => NoticeAnalysis::STATUS_QUEUED,
                    'decision' => null,
                    'reason' => null,
                    'vector' => null,
                    'scope' => null,
                    'title' => null,
                    'summary' => null,
                    'repealed_provisions' => null,
                    'link' => null,
                    'raw_response' => null,
                    'error_message' => null,
                    'started_at' => null,
                    'processed_at' => null,
                    'updated_at' => $now,
                ]);

            if ($total === 0) {
                $lockedRun->forceFill([
                    'status' => NoticeAnalysisRun::STATUS_COMPLETED,
                    'issue_date' => $issueDate,
                    'started_at' => $now,
                    'finished_at' => $now,
                    'total_notices' => 0,
                    'processed_notices' => 0,
                    'sent_count' => 0,
                    'ignored_count' => 0,
                    'failed_count' => 0,
                    'last_error' => null,
                ])->save();

                return $lockedRun;
            }

            $lockedRun->forceFill([
                'status' => NoticeAnalysisRun::STATUS_PROCESSING,
                'issue_date' => $issueDate,
                'locale' => $this->normalizeLocale((string) $lockedRun->locale),
                'started_at' => $lockedRun->started_at ?? $now,
                'finished_at' => null,
                'total_notices' => $total,
                'last_error' => null,
            ])->save();

            $analysisIds = NoticeAnalysis::query()
                ->where('notice_analysis_run_id', $lockedRun->id)
                ->where('status', NoticeAnalysis::STATUS_QUEUED)
                ->pluck('id')
                ->map(fn (int $id): int => (int) $id)
                ->all();

            $queue = (string) config('services.openai.notice_analysis_queue', 'default');
            foreach ($analysisIds as $analysisId) {
                ProcessNoticeAnalysis::dispatch($analysisId)->onQueue($queue);
            }

            return $lockedRun;
        });

        $updatedRun->refreshProgress();

        /** @var NoticeAnalysisRun $freshRun */
        $freshRun = $updatedRun->fresh();

        return $freshRun;
    }

    private function normalizeLocale(?string $locale): string
    {
        $value = trim(strtolower((string) $locale));
        if ($value === '') {
            return 'en';
        }

        if (! in_array($value, User::supportedLocales(), true)) {
            return 'en';
        }

        return $value;
    }

    /**
     * @param  list<int>  $noticeIds
     */
    public function dispatch(array $noticeIds, ?int $requestedByUserId = null, ?string $issueDate = null): NoticeAnalysisRun
    {
        $uniqueNoticeIds = array_values(array_unique(array_map('intval', $noticeIds)));

        $existingNoticeIds = Notice::query()
            ->whereIn('id', $uniqueNoticeIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int $id): int => (int) $id)
            ->all();

        $total = count($existingNoticeIds);
        $now = Carbon::now();

        return DB::transaction(function () use ($existingNoticeIds, $requestedByUserId, $total, $now, $issueDate): NoticeAnalysisRun {
            $run = NoticeAnalysisRun::query()->create([
                'status' => $total > 0 ? NoticeAnalysisRun::STATUS_PROCESSING : NoticeAnalysisRun::STATUS_COMPLETED,
                'requested_by_user_id' => $requestedByUserId,
                'issue_date' => $issueDate,
                'total_notices' => $total,
                'processed_notices' => 0,
                'sent_count' => 0,
                'ignored_count' => 0,
                'failed_count' => 0,
                'model' => (string) config('services.openai.api_model', 'gpt-5-mini'),
                'system_prompt_path' => (string) config('services.openai.notice_analysis_system_prompt', 'ai-prompts/notice-analysis-system.md'),
                'user_prompt_path' => (string) config('services.openai.notice_analysis_user_prompt', 'ai-prompts/notice-analysis-user.md'),
                'started_at' => $total > 0 ? $now : null,
                'finished_at' => $total > 0 ? null : $now,
            ]);

            if ($existingNoticeIds === []) {
                return $run;
            }

            $rows = [];
            foreach ($existingNoticeIds as $noticeId) {
                $rows[] = [
                    'notice_analysis_run_id' => $run->id,
                    'notice_id' => $noticeId,
                    'status' => NoticeAnalysis::STATUS_QUEUED,
                    'model' => (string) config('services.openai.api_model', 'gpt-5-mini'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            NoticeAnalysis::query()->insert($rows);

            $analysisIds = NoticeAnalysis::query()
                ->where('notice_analysis_run_id', $run->id)
                ->pluck('id')
                ->map(fn (int $id): int => (int) $id)
                ->all();

            $queue = (string) config('services.openai.notice_analysis_queue', 'default');
            foreach ($analysisIds as $analysisId) {
                ProcessNoticeAnalysis::dispatch($analysisId)->onQueue($queue);
            }

            return $run;
        });
    }
}
