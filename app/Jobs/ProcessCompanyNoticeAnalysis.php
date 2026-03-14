<?php

namespace App\Jobs;

use App\Models\CompanyNoticeAnalysis;
use App\Models\CompanyNoticeAnalysisRun;
use App\Services\OpenAICompanyNoticeAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class ProcessCompanyNoticeAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600];

    public int $timeout;

    public function __construct(public int $companyNoticeAnalysisId)
    {
        $this->onQueue((string) config('services.openai.company_notice_analysis_queue', 'default'));
        $this->timeout = (int) config('services.openai.job_timeout', 900);
    }

    public function handle(OpenAICompanyNoticeAnalyzer $analyzer): void
    {
        /** @var CompanyNoticeAnalysis|null $analysis */
        $analysis = CompanyNoticeAnalysis::query()
            ->with([
                'noticeAnalysis.notice.dailyJournal.source',
                'noticeAnalysis.noticeAnalysisRun.scope.translations',
                'companyNoticeAnalysisRun.company.spanishLegalForm',
                'companyNoticeAnalysisRun.company.cnaeCode',
                'companyNoticeAnalysisRun.company.featureAnswers.feature.translations',
                'companyNoticeAnalysisRun.company.featureAnswers.feature.scope.translations',
                'companyNoticeAnalysisRun.company.featureAnswers.feature.options.translations',
                'companyNoticeAnalysisRun.company.featureAnswers.featureOption.translations',
            ])
            ->find($this->companyNoticeAnalysisId);

        if (! $analysis || ! $analysis->is_applicable) {
            return;
        }

        if (in_array($analysis->status, [CompanyNoticeAnalysis::STATUS_DONE, CompanyNoticeAnalysis::STATUS_FAILED], true)) {
            return;
        }

        $run = $analysis->companyNoticeAnalysisRun;
        $company = $run?->company;
        $noticeAnalysis = $analysis->noticeAnalysis;
        $scope = $noticeAnalysis?->noticeAnalysisRun?->scope;

        if (! $run || ! $company || ! $noticeAnalysis || ! $scope) {
            throw new \RuntimeException('Company notice analysis is missing required relations.');
        }

        if ($noticeAnalysis->decision !== 'send') {
            $analysis->forceFill([
                'is_applicable' => false,
            ])->save();

            return;
        }

        $analysis->forceFill([
            'status' => CompanyNoticeAnalysis::STATUS_PROCESSING,
            'started_at' => Carbon::now(),
            'error_message' => null,
        ])->save();

        $delayMs = max(0, (int) config('services.openai.screening_delay_ms', 0));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        $outputLocale = (string) ($run->locale ?: 'en');
        $result = $analyzer->analyze(
            $noticeAnalysis,
            $company,
            $scope,
            $outputLocale,
            $run->system_prompt_path,
            $run->user_prompt_path,
        );

        $analysis->forceFill([
            'status' => CompanyNoticeAnalysis::STATUS_DONE,
            'decision' => $result['decision'] ?? null,
            'reason' => $result['reason'] ?? null,
            'requirements' => $result['requirements'] ?? null,
            'compliance_due_at' => $result['compliance_due_at'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'processed_at' => Carbon::now(),
            'model' => (string) ($result['model'] ?? config('services.openai.api_model', 'gpt-5-mini')),
            'error_message' => null,
        ])->save();

        $analysis->refresh();
        $run->refreshProgress();
    }

    public function failed(Throwable $exc): void
    {
        /** @var CompanyNoticeAnalysis|null $analysis */
        $analysis = CompanyNoticeAnalysis::query()->with('companyNoticeAnalysisRun')->find($this->companyNoticeAnalysisId);
        if (! $analysis) {
            return;
        }

        $analysis->forceFill([
            'status' => CompanyNoticeAnalysis::STATUS_FAILED,
            'processed_at' => Carbon::now(),
            'error_message' => Str::limit($exc->getMessage(), 2000),
        ])->save();

        $run = $analysis->companyNoticeAnalysisRun;
        if (! $run instanceof CompanyNoticeAnalysisRun) {
            return;
        }

        $run->forceFill([
            'last_error' => Str::limit($exc->getMessage(), 2000),
        ])->save();

        $run->refreshProgress();
    }
}
