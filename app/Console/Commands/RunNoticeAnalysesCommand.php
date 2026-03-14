<?php

namespace App\Console\Commands;

use App\Models\Scope;
use App\Models\User;
use App\Services\NoticeAnalysisRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RunNoticeAnalysesCommand extends Command
{
    protected $signature = 'notice-analyses:run
        {--date= : Target issue date (YYYY-MM-DD). Defaults to yesterday in configured timezone}
        {--scopes= : Comma-separated scope codes (defaults to all active scopes with analysis prompts)}';

    protected $description = 'Create and dispatch English notice analysis runs on existing crawled notices, without rerunning crawlers.';

    public function handle(NoticeAnalysisRunner $runner): int
    {
        $timezone = (string) config('app.pipeline.timezone', 'Europe/Madrid');
        $targetDate = $this->resolveTargetDate($timezone);
        $scopes = $this->resolveScopes();
        if ($scopes->isEmpty()) {
            $this->error('No active scopes with analysis prompts are available for this command.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Dispatching English notice analyses for %s, scopes=%s',
            $targetDate->toDateString(),
            $scopes->pluck('code')->implode(', '),
        ));

        foreach ($scopes as $scope) {
            if (! $scope->hasCompanyAnalysisPrompt()) {
                $this->warn(sprintf(
                    'Scope %s has no company-analysis prompt. Stage 1 will run, but automatic company analyses will be skipped.',
                    $scope->code,
                ));
            }

            $run = $runner->dispatchForIssueDate($targetDate->toDateString(), $scope, null, User::LOCALE_EN);

            $this->info(sprintf(
                'Run #%d scope=%s locale=%s date=%s total=%d status=%s',
                $run->id,
                $scope->code,
                User::LOCALE_EN,
                (string) $run->issue_date?->toDateString(),
                (int) $run->total_notices,
                (string) $run->status,
            ));
        }

        $this->info('Notice analysis dispatch completed.');

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
     * @return Collection<int, Scope>
     */
    private function resolveScopes(): Collection
    {
        $scopes = Scope::query()
            ->with('translations')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (Scope $scope): bool => $scope->hasAnalysisPrompt())
            ->values();

        $provided = trim((string) $this->option('scopes'));
        if ($provided === '') {
            return $scopes;
        }

        $requestedCodes = collect(explode(',', $provided))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values();

        $matched = $scopes
            ->filter(fn (Scope $scope): bool => $requestedCodes->contains($scope->code))
            ->values();

        $matchedCodes = $matched->pluck('code');
        $missingCodes = $requestedCodes->reject(fn (string $code): bool => $matchedCodes->contains($code))->values()->all();

        if ($missingCodes !== []) {
            $this->warn('Ignoring unavailable scopes: '.implode(', ', $missingCodes));
        }

        return $matched;
    }
}
