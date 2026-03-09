<?php

namespace App\Console\Commands;

use App\Models\CrawlerRun;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CrawlerRunsStartCommand extends Command
{
    protected $signature = 'crawler-runs:start
        {slug : Source slug}
        {runId : Wrapper run id}
        {runDirectory : Relative or absolute run directory}
        {logPath : Relative or absolute crawler log path}
        {--issue-date= : Issue date (YYYY-MM-DD)}
        {--mode=headless : Crawler mode}
        {--triggered-by=manual : Trigger origin}';

    protected $description = 'Record the start of a crawler run.';

    public function handle(): int
    {
        $slug = trim((string) $this->argument('slug'));
        $issueDate = trim((string) $this->option('issue-date'));

        $record = CrawlerRun::query()->updateOrCreate(
            ['run_id' => (string) $this->argument('runId')],
            [
                'source_id' => Source::query()->where('slug', $slug)->value('id'),
                'source_slug' => $slug,
                'issue_date' => $issueDate !== '' ? $issueDate : null,
                'mode' => trim((string) $this->option('mode')) ?: 'headless',
                'triggered_by' => trim((string) $this->option('triggered-by')) ?: 'manual',
                'status' => CrawlerRun::STATUS_RUNNING,
                'exit_code' => null,
                'run_directory' => (string) $this->argument('runDirectory'),
                'log_path' => (string) $this->argument('logPath'),
                'error_message' => null,
                'started_at' => Carbon::now(),
                'finished_at' => null,
            ],
        );

        $this->line((string) $record->id);

        return self::SUCCESS;
    }
}
