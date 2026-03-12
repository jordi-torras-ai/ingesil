<?php

namespace App\Services;

use App\Jobs\ProcessNoticeAnalysis;
use App\Models\Notice;
use App\Models\NoticeAnalysis;
use App\Models\NoticeAnalysisRun;
use App\Models\Scope;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NoticeAnalysisRunner
{
    public function createRunForIssueDate(
        string|CarbonInterface $issueDate,
        Scope|int|string $scope,
        ?int $requestedByUserId = null,
        ?string $locale = null,
    ): NoticeAnalysisRun
    {
        $normalizedIssueDate = $issueDate instanceof CarbonInterface
            ? $issueDate->copy()->startOfDay()
            : Carbon::parse($issueDate)->startOfDay();
        $normalizedLocale = $this->normalizeLocale($locale);
        $resolvedScope = $this->resolveScope($scope);
        $promptPaths = $resolvedScope->analysisPromptRelativePaths();

        return NoticeAnalysisRun::query()->create([
            'status' => NoticeAnalysisRun::STATUS_QUEUED,
            'requested_by_user_id' => $requestedByUserId,
            'scope_id' => $resolvedScope->id,
            'issue_date' => $normalizedIssueDate->toDateString(),
            'locale' => $normalizedLocale,
            'total_notices' => 0,
            'processed_notices' => 0,
            'sent_count' => 0,
            'ignored_count' => 0,
            'failed_count' => 0,
            'model' => (string) config('services.openai.api_model', 'gpt-5-mini'),
            'system_prompt_path' => $promptPaths['system'],
            'user_prompt_path' => $promptPaths['user'],
            'started_at' => null,
            'finished_at' => null,
            'company_runs_dispatched_at' => null,
        ]);
    }

    public function dispatchForIssueDate(
        string|CarbonInterface $issueDate,
        Scope|int|string $scope,
        ?int $requestedByUserId = null,
        ?string $locale = null,
    ): NoticeAnalysisRun
    {
        $run = $this->createRunForIssueDate($issueDate, $scope, $requestedByUserId, $locale);
        return $this->dispatchRun($run);
    }

    public function dispatchRun(NoticeAnalysisRun $run): NoticeAnalysisRun
    {
        $run->loadMissing('scope.translations');

        $issueDate = $run->issue_date?->toDateString();
        if (! $issueDate) {
            throw new RuntimeException('Run has no issue date.');
        }

        $scope = $run->scope;
        if (! $scope instanceof Scope) {
            throw new RuntimeException('Run has no analysis scope.');
        }

        if (! $scope->is_active) {
            throw new RuntimeException('Run scope is inactive.');
        }

        if (! $scope->hasAnalysisPrompt()) {
            throw new RuntimeException('Run scope prompt files are missing.');
        }

        $promptPaths = $scope->analysisPromptRelativePaths();

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
                    'jurisdiction' => null,
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
                    'company_runs_dispatched_at' => null,
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
                'company_runs_dispatched_at' => null,
                'total_notices' => $total,
                'system_prompt_path' => $promptPaths['system'],
                'user_prompt_path' => $promptPaths['user'],
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
    public function dispatch(
        array $noticeIds,
        Scope|int|string $scope,
        ?int $requestedByUserId = null,
        ?string $issueDate = null,
        ?string $locale = null,
    ): NoticeAnalysisRun
    {
        $uniqueNoticeIds = array_values(array_unique(array_map('intval', $noticeIds)));
        $resolvedScope = $this->resolveScope($scope);
        $promptPaths = $resolvedScope->analysisPromptRelativePaths();
        $normalizedLocale = $this->normalizeLocale($locale);

        $existingNoticeIds = Notice::query()
            ->whereIn('id', $uniqueNoticeIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int $id): int => (int) $id)
            ->all();

        $total = count($existingNoticeIds);
        $now = Carbon::now();

        return DB::transaction(function () use (
            $existingNoticeIds,
            $requestedByUserId,
            $resolvedScope,
            $promptPaths,
            $normalizedLocale,
            $total,
            $now,
            $issueDate,
        ): NoticeAnalysisRun {
            $run = NoticeAnalysisRun::query()->create([
                'status' => $total > 0 ? NoticeAnalysisRun::STATUS_PROCESSING : NoticeAnalysisRun::STATUS_COMPLETED,
                'requested_by_user_id' => $requestedByUserId,
                'scope_id' => $resolvedScope->id,
                'issue_date' => $issueDate,
                'locale' => $normalizedLocale,
                'total_notices' => $total,
                'processed_notices' => 0,
                'sent_count' => 0,
                'ignored_count' => 0,
                'failed_count' => 0,
                'model' => (string) config('services.openai.api_model', 'gpt-5-mini'),
                'system_prompt_path' => $promptPaths['system'],
                'user_prompt_path' => $promptPaths['user'],
                'started_at' => $total > 0 ? $now : null,
                'finished_at' => $total > 0 ? null : $now,
                'company_runs_dispatched_at' => null,
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

    private function resolveScope(Scope|int|string $scope): Scope
    {
        $resolvedScope = match (true) {
            $scope instanceof Scope => $scope->loadMissing('translations'),
            is_int($scope) || ctype_digit((string) $scope) => Scope::query()
                ->with('translations')
                ->find((int) $scope),
            default => Scope::query()
                ->with('translations')
                ->where('code', trim((string) $scope))
                ->first(),
        };

        if (! $resolvedScope instanceof Scope) {
            throw new RuntimeException('Analysis scope not found.');
        }

        if (! $resolvedScope->is_active) {
            throw new RuntimeException('Analysis scope is inactive.');
        }

        if (! $resolvedScope->hasAnalysisPrompt()) {
            throw new RuntimeException('Analysis scope prompt files are missing.');
        }

        return $resolvedScope;
    }
}
