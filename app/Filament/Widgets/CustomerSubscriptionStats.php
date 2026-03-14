<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CompanyResource;
use App\Models\CompanyScopeSubscription;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CustomerSubscriptionStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ! $user->isPlatformAdmin() && $user->companies()->exists());
    }

    protected function getHeading(): ?string
    {
        return __('app.dashboard.customer_subscriptions.heading');
    }

    protected function getDescription(): ?string
    {
        return __('app.dashboard.customer_subscriptions.description');
    }

    protected function getStats(): array
    {
        $companyIds = $this->companyIds();

        $companiesCount = count($companyIds);
        $subscriptionQuery = CompanyScopeSubscription::query()->whereIn('company_id', $companyIds);
        $subscriptionsCount = (clone $subscriptionQuery)->count();
        $scopesCount = (clone $subscriptionQuery)->distinct('scope_id')->count('scope_id');
        $languageCount = (clone $subscriptionQuery)->distinct('locale')->count('locale');

        return [
            Stat::make(__('app.dashboard.customer_subscriptions.stats.companies.label'), number_format($companiesCount))
                ->description(__('app.dashboard.customer_subscriptions.stats.companies.description'))
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary')
                ->url(CompanyResource::getUrl('index')),
            Stat::make(__('app.dashboard.customer_subscriptions.stats.subscriptions.label'), number_format($subscriptionsCount))
                ->description(__('app.dashboard.customer_subscriptions.stats.subscriptions.description'))
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->color('success')
                ->chart($this->sparkline($subscriptionsCount)),
            Stat::make(__('app.dashboard.customer_subscriptions.stats.scopes.label'), number_format($scopesCount))
                ->description(__('app.dashboard.customer_subscriptions.stats.scopes.description'))
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('info')
                ->chart($this->sparkline($scopesCount)),
            Stat::make(__('app.dashboard.customer_subscriptions.stats.languages.label'), number_format($languageCount))
                ->description(__('app.dashboard.customer_subscriptions.stats.languages.description'))
                ->descriptionIcon('heroicon-m-language')
                ->color('warning')
                ->chart($this->sparkline($languageCount)),
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
