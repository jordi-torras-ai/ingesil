<?php

namespace App\Console\Commands;

use App\Models\CrawlerRun;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CrawlerRunsFinishCommand extends Command
{
    protected $signature = 'crawler-runs:finish
        {runId : Wrapper run id}
        {exitCode : Process exit code}';

    protected $description = 'Record the completion of a crawler run.';

    public function handle(): int
    {
        $record = CrawlerRun::query()->where('run_id', (string) $this->argument('runId'))->first();

        if (! $record) {
            $this->warn('Crawler run record not found.');

            return self::SUCCESS;
        }

        $exitCode = (int) $this->argument('exitCode');

        $record->update([
            'status' => $exitCode === 0 ? CrawlerRun::STATUS_SUCCEEDED : CrawlerRun::STATUS_FAILED,
            'exit_code' => $exitCode,
            'finished_at' => now(),
            'error_message' => $exitCode === 0 ? null : $this->buildErrorMessage($record),
        ]);

        return self::SUCCESS;
    }

    private function buildErrorMessage(CrawlerRun $record): ?string
    {
        $preview = trim($record->readLogPreview(6000));

        if ($preview === '' || $preview === __('app.crawler_runs.messages.log_not_available')) {
            return 'Crawler failed. Check crawler.log for details.';
        }

        return Str::limit($preview, 4000);
    }
}
