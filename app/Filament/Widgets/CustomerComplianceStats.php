<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CompanyNoticeAnalysisResource;
use App\Models\CompanyNoticeAnalysis;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class CustomerComplianceStats extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ! $user->isPlatformAdmin() && $user->companies()->exists());
    }

    protected function getHeading(): ?string
    {
        return __('app.dashboard.customer_compliance.heading');
    }

    protected function getDescription(): ?string
    {
        return __('app.dashboard.customer_compliance.description');
    }

    protected function getStats(): array
    {
        $companyIds = $this->companyIds();
        $baseQuery = CompanyNoticeAnalysis::query()
            ->whereHas('companyNoticeAnalysisRun', fn (Builder $query): Builder => $query->whereIn('company_id', $companyIds))
            ->where('is_applicable', true);

        $relevantCount = (clone $baseQuery)
            ->where('decision', CompanyNoticeAnalysis::DECISION_RELEVANT)
            ->count();

        $pendingReviewCount = (clone $baseQuery)
            ->where('decision', CompanyNoticeAnalysis::DECISION_RELEVANT)
            ->whereNull('confirmed_relevant')
            ->count();

        $dueSoonCount = (clone $baseQuery)
            ->where('decision', CompanyNoticeAnalysis::DECISION_RELEVANT)
            ->whereNotNull('compliance_due_at')
            ->whereDate('compliance_due_at', '>=', now()->toDateString())
            ->whereDate('compliance_due_at', '<=', now()->addDays(30)->toDateString())
            ->where(function (Builder $query): void {
                $query->whereNull('compliance')->orWhere('compliance', false);
            })
            ->count();

        $compliantCount = (clone $baseQuery)
            ->where('compliance', true)
            ->count();

        return [
            Stat::make(__('app.dashboard.customer_compliance.stats.relevant.label'), number_format($relevantCount))
                ->description(__('app.dashboard.customer_compliance.stats.relevant.description'))
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('primary')
                ->url(CompanyNoticeAnalysisResource::getUrl('index', [
                    'tableFilters' => [
                        'decision' => ['value' => CompanyNoticeAnalysis::DECISION_RELEVANT],
                    ],
                ])),
            Stat::make(__('app.dashboard.customer_compliance.stats.pending_review.label'), number_format($pendingReviewCount))
                ->description(__('app.dashboard.customer_compliance.stats.pending_review.description'))
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color($pendingReviewCount > 0 ? 'warning' : 'success')
                ->chart($this->sparkline($pendingReviewCount))
                ->url(CompanyNoticeAnalysisResource::pendingReviewUrl()),
            Stat::make(__('app.dashboard.customer_compliance.stats.due_soon.label'), number_format($dueSoonCount))
                ->description(__('app.dashboard.customer_compliance.stats.due_soon.description'))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($dueSoonCount > 0 ? 'danger' : 'success')
                ->chart($this->sparkline($dueSoonCount)),
            Stat::make(__('app.dashboard.customer_compliance.stats.compliant.label'), number_format($compliantCount))
                ->description(__('app.dashboard.customer_compliance.stats.compliant.description'))
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success')
                ->chart($this->sparkline($compliantCount)),
        ];
    }

    /**
     * @return list<int>
     */
    private function companyIds(): array
    {
        return auth()->user()?->companies()
            ->orderBy('companies.id')
            ->pluck('companies.id')
            ->map(fn (int $id): int => (int) $id)
            ->all() ?? [];
    }

    /**
     * @return array<float>
     */
    private function sparkline(int $value): array
    {
        return [
            (float) max(0, $value - 2),
            (float) max(0, $value - 1),
            (float) $value,
            (float) $value,
        ];
    }
}
