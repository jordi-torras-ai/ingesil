<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class DailyPublicationActivityChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static string $view = 'filament.widgets.dashboard-chart-widget';

    protected int | string | array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    protected static ?string $maxHeight = '320px';

    public function getHeading(): ?string
    {
        return __('app.dashboard.daily_activity.heading');
    }

    public function getDescription(): ?string
    {
        return __('app.dashboard.daily_activity.description');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $labels = [];
        $noticeData = [];
        $journalData = [];

        $noticeRows = \DB::table('notices')
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        $journalRows = \DB::table('daily_journals')
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        foreach (range(13, 0) as $offset) {
            $day = now()->subDays($offset);
            $key = $day->toDateString();
            $labels[] = $day->format('M j');
            $noticeData[] = (int) ($noticeRows[$key] ?? 0);
            $journalData[] = (int) ($journalRows[$key] ?? 0);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => __('app.dashboard.daily_activity.datasets.notices'),
                    'data' => $noticeData,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.12)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => __('app.dashboard.daily_activity.datasets.daily_journals'),
                    'data' => $journalData,
                    'borderColor' => '#0f766e',
                    'backgroundColor' => 'rgba(15, 118, 110, 0.08)',
                    'fill' => true,
                    'tension' => 0.35,
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
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
