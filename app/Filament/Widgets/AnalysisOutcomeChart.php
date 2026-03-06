<?php

namespace App\Filament\Widgets;

use App\Models\NoticeAnalysis;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class AnalysisOutcomeChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected static string $view = 'filament.widgets.dashboard-chart-widget';

    protected int | string | array $columnSpan = 1;

    protected static ?string $maxHeight = '320px';

    public function getHeading(): ?string
    {
        return __('app.dashboard.analysis_outcomes.heading');
    }

    public function getDescription(): ?string
    {
        return __('app.dashboard.analysis_outcomes.description');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $baseQuery = NoticeAnalysis::query()
            ->where('created_at', '>=', now()->subDays(30)->startOfDay());

        $send = (clone $baseQuery)->where('decision', 'send')->count();
        $ignore = (clone $baseQuery)->where('decision', 'ignore')->count();
        $failed = (clone $baseQuery)->where('status', NoticeAnalysis::STATUS_FAILED)->count();
        $queued = (clone $baseQuery)->whereIn('status', [
            NoticeAnalysis::STATUS_QUEUED,
            NoticeAnalysis::STATUS_PROCESSING,
        ])->count();

        return [
            'labels' => [
                __('app.dashboard.analysis_outcomes.labels.send'),
                __('app.dashboard.analysis_outcomes.labels.ignore'),
                __('app.dashboard.analysis_outcomes.labels.failed'),
                __('app.dashboard.analysis_outcomes.labels.queued_processing'),
            ],
            'datasets' => [
                [
                    'data' => [$send, $ignore, $failed, $queued],
                    'backgroundColor' => [
                        '#0f766e',
                        '#94a3b8',
                        '#dc2626',
                        '#f59e0b',
                    ],
                    'borderWidth' => 0,
                ],
            ],
        ];
    }

    protected function getOptions(): array | RawJs | null
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
            'maintainAspectRatio' => false,
            'cutout' => '68%',
        ];
    }
}
