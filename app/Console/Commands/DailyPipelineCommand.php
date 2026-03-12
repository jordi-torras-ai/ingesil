<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\Scope;
use App\Models\User;
use App\Services\NoticeAnalysisRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class DailyPipelineCommand extends Command
{
    protected $signature = 'pipeline:daily-notices
        {--date= : Target issue date (YYYY-MM-DD). Defaults to yesterday in configured timezone}
        {--locales= : Comma-separated locales for analysis runs (defaults to all supported locales)}
        {--headless : Force crawlers to run in headless mode}
        {--continue-on-crawler-error : Continue pipeline even if one crawler fails}';

    protected $description = 'Run all crawlers for one issue date, then create and dispatch notice analysis runs per scope and locale.';

    public function handle(NoticeAnalysisRunner $runner): int
    {
        $timezone = (string) config('app.pipeline.timezone', 'Europe/Madrid');
        $targetDate = $this->resolveTargetDate($timezone);
        $headless = (bool) $this->option('headless');
        $continueOnCrawlerError = (bool) $this->option('continue-on-crawler-error');
        $crawlerTimeout = (int) config('app.pipeline.crawler_command_timeout_seconds', 7200);

        $this->info(sprintf('Daily pipeline target date: %s (%s)', $targetDate->toDateString(), $timezone));

        $sourceSlugs = Source::query()
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->orderBy('id')
            ->pluck('slug')
            ->map(fn (string $slug): string => trim($slug))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($sourceSlugs === []) {
            $this->error('No sources found with slug. Aborting.');
            return self::FAILURE;
        }

        $this->line('Running crawlers: '.implode(', ', $sourceSlugs));

        $failedCrawlers = [];
        foreach ($sourceSlugs as $slug) {
            $result = $this->runCrawlerForDate($slug, $targetDate->toDateString(), $headless, $crawlerTimeout);
            if ($result === 0) {
                continue;
            }

            $failedCrawlers[] = $slug;

            if (! $continueOnCrawlerError) {
                $this->error("Crawler failed for source '{$slug}'. Aborting pipeline.");
                return self::FAILURE;
            }
        }

        if ($failedCrawlers !== []) {
            $this->warn('Crawler failures (continuing by option): '.implode(', ', $failedCrawlers));
        }

        $scopes = Scope::query()
            ->with('translations')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (Scope $scope): bool => $scope->hasAnalysisPrompt())
            ->values();

        if ($scopes->isEmpty()) {
            $this->error('No active scopes with prompt files are available.');
            return self::FAILURE;
        }

        $locales = $this->resolveLocales();
        if ($locales === []) {
            $this->error('No valid locales were provided.');
            return self::FAILURE;
        }

        $this->line('Creating and dispatching analysis runs for scopes/locales.');

        foreach ($scopes as $scope) {
            foreach ($locales as $locale) {
                $run = $runner->createRunForIssueDate($targetDate->toDateString(), $scope, null, $locale);
                $run = $runner->dispatchRun($run);

                $this->info(sprintf(
                    'Run #%d scope=%s locale=%s date=%s total=%d status=%s',
                    $run->id,
                    $scope->code,
                    $locale,
                    (string) $run->issue_date?->toDateString(),
                    (int) $run->total_notices,
                    (string) $run->status
                ));
            }
        }

        $this->info('Daily pipeline finished successfully.');

        return self::SUCCESS;
    }

    private function resolveTargetDate(string $timezone): Carbon
    {
        $date = trim((string) $this->option('date'));
        if ($date !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();
            } catch (\Throwable) {
                throw new \RuntimeException("Invalid --date value '{$date}'. Expected YYYY-MM-DD.");
            }
        }

        return Carbon::now($timezone)->subDay()->startOfDay();
    }

    /**
     * @return list<string>
     */
    private function resolveLocales(): array
    {
        $provided = trim((string) $this->option('locales'));
        if ($provided === '') {
            return User::supportedLocales();
        }

        $requested = collect(explode(',', $provided))
            ->map(fn (string $value): string => Str::lower(trim($value)))
            ->filter()
            ->unique()
            ->values();

        $supported = collect(User::supportedLocales());
        $invalid = $requested->diff($supported)->values()->all();

        if ($invalid !== []) {
            $this->warn('Ignoring unsupported locales: '.implode(', ', $invalid));
        }

        return $requested->intersect($supported)->values()->all();
    }

    private function runCrawlerForDate(string $slug, string $issueDate, bool $headless, int $timeoutSeconds): int
    {
        $python = base_path('.venv/bin/python');
        $runner = base_path('python/run_crawler.py');

        if (! is_file($python)) {
            throw new \RuntimeException("Python binary not found: {$python}");
        }
        if (! is_file($runner)) {
            throw new \RuntimeException("Crawler runner not found: {$runner}");
        }

        $command = [$python, $runner, $slug, '--day', $issueDate, '--triggered-by=pipeline'];
        if ($headless) {
            $command[] = '--headless';
        }

        $this->line('');
        $this->line(">>> crawler {$slug} ({$issueDate})");

        $process = new Process($command, base_path());
        $process->setTimeout(max(60, $timeoutSeconds));
        $process->run(function (string $type, string $buffer): void {
            if ($type === Process::ERR) {
                $this->output->write("<fg=red>{$buffer}</>");
                return;
            }

            $this->output->write($buffer);
        });

        if ($process->isSuccessful()) {
            $this->info("Crawler {$slug} finished.");
            return self::SUCCESS;
        }

        $this->error("Crawler {$slug} failed (exit {$process->getExitCode()}).");
        return self::FAILURE;
    }
}
