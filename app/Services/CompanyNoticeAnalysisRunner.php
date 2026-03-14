<?php

namespace App\Services;

use App\Jobs\ProcessCompanyNoticeAnalysis;
use App\Models\Company;
use App\Models\CompanyNoticeAnalysis;
use App\Models\CompanyNoticeAnalysisRun;
use App\Models\CompanyScopeSubscription;
use App\Models\NoticeAnalysis;
use App\Models\NoticeAnalysisRun;
use App\Models\Scope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CompanyNoticeAnalysisRunner
{
    public function dispatchForNoticeAnalysisRun(NoticeAnalysisRun $noticeAnalysisRun, bool $strictPrompt = true): int
    {
        $noticeAnalysisRun->loadMissing('scope.translations');

        $scope = $noticeAnalysisRun->scope;
        if (! $scope instanceof Scope) {
            throw new RuntimeException('Notice analysis run has no scope.');
        }

        if (! in_array($noticeAnalysisRun->status, [
            NoticeAnalysisRun::STATUS_COMPLETED,
            NoticeAnalysisRun::STATUS_COMPLETED_WITH_ERRORS,
        ], true)) {
            throw new RuntimeException('Notice analysis run must be completed before company analyses can be dispatched.');
        }

        if (! $scope->hasCompanyAnalysisPrompt()) {
            if ($strictPrompt) {
                throw new RuntimeException('Scope company analysis prompt files are missing.');
            }

            return 0;
        }

        $subscriptions = CompanyScopeSubscription::query()
            ->with(['company', 'scope.translations'])
            ->where('scope_id', $scope->id)
            ->orderBy('company_id')
            ->orderBy('locale')
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->dispatchCompanyRun($noticeAnalysisRun, $subscription);
        }

        $noticeAnalysisRun->forceFill([
            'company_runs_dispatched_at' => Carbon::now(),
        ])->save();

        return $subscriptions->count();
    }

    public function dispatchCompanyRun(NoticeAnalysisRun $noticeAnalysisRun, CompanyScopeSubscription $subscription): CompanyNoticeAnalysisRun
    {
        $noticeAnalysisRun->loadMissing('scope.translations');

        $scope = $noticeAnalysisRun->scope;
        if (! $scope instanceof Scope) {
            throw new RuntimeException('Notice analysis run has no scope.');
        }

        $company = $subscription->company;
        if (! $company instanceof Company) {
            throw new RuntimeException('Scope subscription has no company.');
        }

        if ((int) $subscription->scope_id !== (int) $scope->id) {
            throw new RuntimeException('Scope subscription does not match the notice analysis run scope.');
        }

        $promptPaths = $scope->companyAnalysisPromptRelativePaths();
        $now = Carbon::now();

        $run = DB::transaction(function () use ($noticeAnalysisRun, $subscription, $company, $promptPaths, $now): CompanyNoticeAnalysisRun {
            /** @var CompanyNoticeAnalysisRun $run */
            $run = CompanyNoticeAnalysisRun::query()->firstOrCreate(
                [
                    'notice_analysis_run_id' => $noticeAnalysisRun->id,
                    'company_scope_subscription_id' => $subscription->id,
                ],
                [
                    'company_id' => $company->id,
                    'locale' => $subscription->locale,
                    'status' => CompanyNoticeAnalysisRun::STATUS_QUEUED,
                    'total_notices' => 0,
                    'processed_notices' => 0,
                    'relevant_count' => 0,
                    'not_relevant_count' => 0,
                    'failed_count' => 0,
                ],
            );

            $sendAnalysisIds = NoticeAnalysis::query()
                ->where('notice_analysis_run_id', $noticeAnalysisRun->id)
                ->where('decision', 'send')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (int $id): int => (int) $id)
                ->all();

            $existingAnalysisIds = CompanyNoticeAnalysis::query()
                ->where('company_notice_analysis_run_id', $run->id)
                ->pluck('notice_analysis_id')
                ->map(fn (int $id): int => (int) $id)
                ->all();

            $missingAnalysisIds = array_values(array_diff($sendAnalysisIds, $existingAnalysisIds));

            if ($missingAnalysisIds !== []) {
                $rows = [];
                foreach ($missingAnalysisIds as $noticeAnalysisId) {
                    $rows[] = [
                        'company_notice_analysis_run_id' => $run->id,
                        'notice_analysis_id' => $noticeAnalysisId,
                        'status' => CompanyNoticeAnalysis::STATUS_QUEUED,
                        'is_applicable' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                CompanyNoticeAnalysis::query()->insert($rows);
            }

            CompanyNoticeAnalysis::query()
                ->where('company_notice_analysis_run_id', $run->id)
                ->update([
                    'is_applicable' => false,
                    'updated_at' => $now,
                ]);

            if ($sendAnalysisIds !== []) {
                CompanyNoticeAnalysis::query()
                    ->where('company_notice_analysis_run_id', $run->id)
                    ->whereIn('notice_analysis_id', $sendAnalysisIds)
                    ->update([
                        'status' => CompanyNoticeAnalysis::STATUS_QUEUED,
                        'is_applicable' => true,
                        'decision' => null,
                        'reason' => null,
                        'requirements' => null,
                        'compliance_due_at' => null,
                        'raw_response' => null,
                        'started_at' => null,
                        'processed_at' => null,
                        'model' => null,
                        'error_message' => null,
                        'updated_at' => $now,
                    ]);
            }

            if ($sendAnalysisIds === []) {
                $run->forceFill([
                    'status' => CompanyNoticeAnalysisRun::STATUS_COMPLETED,
                    'total_notices' => 0,
                    'processed_notices' => 0,
                    'relevant_count' => 0,
                    'not_relevant_count' => 0,
                    'failed_count' => 0,
                    'model' => (string) config('services.openai.api_model', 'gpt-5-mini'),
                    'system_prompt_path' => $promptPaths['system'],
                    'user_prompt_path' => $promptPaths['user'],
                    'started_at' => $now,
                    'finished_at' => $now,
                    'last_error' => null,
                ])->save();

                return $run;
            }

            $run->forceFill([
                'company_id' => $company->id,
                'company_scope_subscription_id' => $subscription->id,
                'locale' => $subscription->locale,
                'status' => CompanyNoticeAnalysisRun::STATUS_PROCESSING,
                'model' => (string) config('services.openai.api_model', 'gpt-5-mini'),
                'system_prompt_path' => $promptPaths['system'],
                'user_prompt_path' => $promptPaths['user'],
                'started_at' => $run->started_at ?? $now,
                'finished_at' => null,
                'last_error' => null,
            ])->save();

            $analysisIds = CompanyNoticeAnalysis::query()
                ->where('company_notice_analysis_run_id', $run->id)
                ->where('is_applicable', true)
                ->where('status', CompanyNoticeAnalysis::STATUS_QUEUED)
                ->pluck('id')
                ->map(fn (int $id): int => (int) $id)
                ->all();

            $queue = (string) config('services.openai.company_notice_analysis_queue', 'default');
            foreach ($analysisIds as $analysisId) {
                ProcessCompanyNoticeAnalysis::dispatch($analysisId)->onQueue($queue);
            }

            return $run;
        });

        $run->refreshProgress();

        /** @var CompanyNoticeAnalysisRun $freshRun */
        $freshRun = $run->fresh();

        return $freshRun;
    }
}
