<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DailyJournalResource;
use App\Filament\Resources\NoticeResource;
use App\Filament\Resources\SourceResource;
use App\Models\DailyJournal;
use App\Models\Notice;
use App\Models\Source;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LibraryOverviewStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return __('app.dashboard.library.heading');
    }

    protected function getDescription(): ?string
    {
        return __('app.dashboard.library.description');
    }

    protected function getStats(): array
    {
        $sourcesCount = Source::query()->count();
        $journalsCount = DailyJournal::query()->count();
        $noticesCount = Notice::query()->count();
        $latestIssueDate = DailyJournal::query()->max('issue_date');
        $isAdmin = auth()->user()?->isAdmin() ?? false;

        return [
            Stat::make(__('app.dashboard.library.stats.sources.label'), number_format($sourcesCount))
                ->description(__('app.dashboard.library.stats.sources.description'))
                ->descriptionIcon('heroicon-m-building-library')
                ->color('primary')
                ->chart($this->buildSparkline(Source::query()->count()))
                ->url($isAdmin ? SourceResource::getUrl('index') : null),
            Stat::make(__('app.dashboard.library.stats.daily_journals.label'), number_format($journalsCount))
                ->description($latestIssueDate
                    ? __('app.dashboard.library.stats.daily_journals.latest_issue', ['date' => $latestIssueDate])
                    : __('app.dashboard.library.stats.daily_journals.no_issue'))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('success')
                ->chart($this->lastDaysSeries('daily_journals', 'issue_date'))
                ->url($isAdmin ? DailyJournalResource::getUrl('index') : null),
            Stat::make(__('app.dashboard.library.stats.notices.label'), number_format($noticesCount))
                ->description(__('app.dashboard.library.stats.notices.description'))
                ->descriptionIcon('heroicon-m-document-text')
                ->color('info')
                ->chart($this->lastDaysSeries('notices'))
                ->url($isAdmin ? NoticeResource::getUrl('index') : null),
        ];
    }

    /**
     * @return array<float>
     */
    private function lastDaysSeries(string $table, string $dateColumn = 'created_at', int $days = 7): array
    {
        $rows = \DB::table($table)
            ->selectRaw("DATE({$dateColumn}) as day, COUNT(*) as aggregate")
            ->whereNotNull($dateColumn)
            ->where($dateColumn, '>=', now()->subDays($days - 1)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        $series = [];
        foreach (range($days - 1, 0) as $offset) {
            $day = now()->subDays($offset)->toDateString();
            $series[] = (float) ($rows[$day] ?? 0);
        }

        return $series;
    }

    /**
     * @return array<float>
     */
    private function buildSparkline(int $count): array
    {
        return [
            max(1, $count - 3),
            max(1, $count - 2),
            max(1, $count - 1),
            max(1, $count),
        ];
    }
}
