<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\NoticeAnalysisResource;
use App\Filament\Resources\NoticeAnalysisRunResource;
use App\Models\NoticeAnalysis;
use App\Models\NoticeAnalysisRun;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AnalysisHealthStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return __('app.dashboard.analysis_health.heading');
    }

    protected function getDescription(): ?string
    {
        return __('app.dashboard.analysis_health.description');
    }

    protected function getStats(): array
    {
        $processingRuns = NoticeAnalysisRun::query()
            ->where('status', NoticeAnalysisRun::STATUS_PROCESSING)
            ->count();
        $completedToday = NoticeAnalysisRun::query()
            ->whereIn('status', [
                NoticeAnalysisRun::STATUS_COMPLETED,
                NoticeAnalysisRun::STATUS_COMPLETED_WITH_ERRORS,
            ])
            ->whereDate('finished_at', now()->toDateString())
            ->count();
        $sentLast30Days = NoticeAnalysis::query()
            ->where('decision', 'send')
            ->where('processed_at', '>=', now()->subDays(30)->startOfDay())
            ->count();
        $failedLast30Days = NoticeAnalysis::query()
            ->where('status', NoticeAnalysis::STATUS_FAILED)
            ->where('created_at', '>=', now()->subDays(30)->startOfDay())
            ->count();
        $queuedAnalyses = NoticeAnalysis::query()
            ->where('status', NoticeAnalysis::STATUS_QUEUED)
            ->count();
        $isAdmin = auth()->user()?->isAdmin() ?? false;

        return [
            Stat::make(__('app.dashboard.analysis_health.stats.runs_in_progress.label'), number_format($processingRuns))
                ->description(__('app.dashboard.analysis_health.stats.runs_in_progress.description'))
                ->descriptionIcon('heroicon-m-play')
                ->color($processingRuns > 0 ? 'warning' : 'success')
                ->chart($this->lastDaysRunSeries(7, NoticeAnalysisRun::STATUS_PROCESSING))
                ->url($isAdmin ? NoticeAnalysisRunResource::getUrl('index', [
                    'tableFilters' => ['status' => ['value' => NoticeAnalysisRun::STATUS_PROCESSING]],
                ]) : null),
            Stat::make(__('app.dashboard.analysis_health.stats.completed_today.label'), number_format($completedToday))
                ->description(__('app.dashboard.analysis_health.stats.completed_today.description'))
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success')
                ->chart($this->lastDaysCompletedSeries())
                ->url($isAdmin ? NoticeAnalysisRunResource::getUrl('index') : null),
            Stat::make(__('app.dashboard.analysis_health.stats.send_decisions.label'), number_format($sentLast30Days))
                ->description(__('app.dashboard.analysis_health.stats.send_decisions.description'))
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('primary')
                ->chart($this->lastDaysAnalysisDecisionSeries('send'))
                ->url($isAdmin ? NoticeAnalysisResource::getUrl('index', [
                    'tableFilters' => ['decision' => ['value' => 'send']],
                ]) : null),
            Stat::make(__('app.dashboard.analysis_health.stats.failed_analyses.label'), number_format($failedLast30Days))
                ->description($queuedAnalyses > 0
                    ? __('app.dashboard.analysis_health.stats.failed_analyses.queued', ['count' => $queuedAnalyses])
                    : __('app.dashboard.analysis_health.stats.failed_analyses.no_backlog'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failedLast30Days > 0 ? 'danger' : 'success')
                ->chart($this->lastDaysFailureSeries())
                ->url($isAdmin ? NoticeAnalysisResource::getUrl('index', [
                    'tableFilters' => ['status' => ['value' => NoticeAnalysis::STATUS_FAILED]],
                ]) : null),
        ];
    }

    /**
     * @return array<float>
     */
    private function lastDaysRunSeries(int $days, string $status): array
    {
        $rows = NoticeAnalysisRun::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->where('status', $status)
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        $series = [];
        foreach (range($days - 1, 0) as $offset) {
            $series[] = (float) ($rows[now()->subDays($offset)->toDateString()] ?? 0);
        }

        return $series;
    }

    /**
     * @return array<float>
     */
    private function lastDaysCompletedSeries(int $days = 7): array
    {
        $rows = NoticeAnalysisRun::query()
            ->selectRaw('DATE(finished_at) as day, COUNT(*) as aggregate')
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        $series = [];
        foreach (range($days - 1, 0) as $offset) {
            $series[] = (float) ($rows[now()->subDays($offset)->toDateString()] ?? 0);
        }

        return $series;
    }

    /**
     * @return array<float>
     */
    private function lastDaysAnalysisDecisionSeries(string $decision, int $days = 30): array
    {
        $rows = NoticeAnalysis::query()
            ->selectRaw('DATE(processed_at) as day, COUNT(*) as aggregate')
            ->where('decision', $decision)
            ->whereNotNull('processed_at')
            ->where('processed_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        $series = [];
        foreach (range($days - 1, 0) as $offset) {
            $series[] = (float) ($rows[now()->subDays($offset)->toDateString()] ?? 0);
        }

        return $series;
    }

    /**
     * @return array<float>
     */
    private function lastDaysFailureSeries(int $days = 30): array
    {
        $rows = NoticeAnalysis::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->where('status', NoticeAnalysis::STATUS_FAILED)
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        $series = [];
        foreach (range($days - 1, 0) as $offset) {
            $series[] = (float) ($rows[now()->subDays($offset)->toDateString()] ?? 0);
        }

        return $series;
    }
}
