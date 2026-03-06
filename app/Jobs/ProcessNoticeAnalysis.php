<?php

namespace App\Jobs;

use App\Models\NoticeAnalysis;
use App\Models\NoticeAnalysisRun;
use App\Services\OpenAINoticeAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class ProcessNoticeAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600];

    public int $timeout;

    public function __construct(public int $noticeAnalysisId)
    {
        $this->onQueue((string) config('services.openai.notice_analysis_queue', 'default'));
        $this->timeout = (int) config('services.openai.job_timeout', 900);
    }

    public function handle(OpenAINoticeAnalyzer $analyzer): void
    {
        /** @var NoticeAnalysis|null $analysis */
        $analysis = NoticeAnalysis::query()
            ->with(['notice.dailyJournal.source', 'noticeAnalysisRun'])
            ->find($this->noticeAnalysisId);

        if (! $analysis) {
            return;
        }

        if (in_array($analysis->status, [NoticeAnalysis::STATUS_DONE, NoticeAnalysis::STATUS_FAILED], true)) {
            return;
        }

        $analysis->forceFill([
            'status' => NoticeAnalysis::STATUS_PROCESSING,
            'started_at' => Carbon::now(),
            'error_message' => null,
        ])->save();

        $delayMs = max(0, (int) config('services.openai.screening_delay_ms', 0));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        $outputLocale = (string) ($analysis->noticeAnalysisRun?->locale ?? 'en');
        $result = $analyzer->analyze($analysis->notice, $outputLocale);

        $analysis->forceFill([
            'status' => NoticeAnalysis::STATUS_DONE,
            'decision' => $result['decision'] ?? null,
            'reason' => $result['reason'] ?? null,
            'vector' => $result['vector'] ?? null,
            'scope' => $result['scope'] ?? null,
            'title' => $result['title'] ?? null,
            'summary' => $result['summary'] ?? null,
            'repealed_provisions' => $result['repealed_provisions'] ?? null,
            'link' => $result['link'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
            'processed_at' => Carbon::now(),
            'model' => (string) ($result['model'] ?? config('services.openai.api_model', 'gpt-5-mini')),
            'error_message' => null,
        ])->save();

        $analysis->refresh();
        $run = $analysis->noticeAnalysisRun;
        if ($run instanceof NoticeAnalysisRun) {
            $run->refreshProgress();
        }
    }

    public function failed(Throwable $exc): void
    {
        /** @var NoticeAnalysis|null $analysis */
        $analysis = NoticeAnalysis::query()->with('noticeAnalysisRun')->find($this->noticeAnalysisId);
        if (! $analysis) {
            return;
        }

        $analysis->forceFill([
            'status' => NoticeAnalysis::STATUS_FAILED,
            'processed_at' => Carbon::now(),
            'error_message' => Str::limit($exc->getMessage(), 2000),
        ])->save();

        $run = $analysis->noticeAnalysisRun;
        if (! $run) {
            return;
        }

        $run->forceFill([
            'last_error' => Str::limit($exc->getMessage(), 2000),
        ])->save();

        $run->refreshProgress();
    }
}
