<?php

namespace App\Services;

use App\Filament\Resources\CompanyNoticeAnalysisResource;
use App\Models\CompanyNoticeAnalysis;
use App\Models\CompanyNoticeAnalysisEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class NoticeDigestBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function buildForUser(User $user, ?Carbon $now = null): array
    {
        $now ??= Carbon::now(config('app.notifications.timezone', config('app.pipeline.timezone', 'Europe/Madrid')));
        $companyIds = $user->companies()
            ->orderBy('companies.id')
            ->pluck('companies.id')
            ->map(fn (int $id): int => (int) $id)
            ->all();

        $windowStart = $this->resolveWindowStart($user, $now);

        $baseQuery = CompanyNoticeAnalysis::query()
            ->with([
                'companyNoticeAnalysisRun.company',
                'companyNoticeAnalysisRun.noticeAnalysisRun.scope.translations',
                'noticeAnalysis.notice.dailyJournal',
            ])
            ->where('is_applicable', true)
            ->whereHas('companyNoticeAnalysisRun', fn (Builder $query): Builder => $query->whereIn('company_id', $companyIds));

        $pending = $this->groupAnalyses(
            (clone $baseQuery)
                ->where('decision', CompanyNoticeAnalysis::DECISION_RELEVANT)
                ->whereNull('confirmed_relevant')
                ->orderByDesc('compliance_due_at')
                ->orderByDesc('id')
                ->get(),
            $user->locale,
        );

        $newRelevant = $this->groupAnalyses(
            (clone $baseQuery)
                ->where('decision', CompanyNoticeAnalysis::DECISION_RELEVANT)
                ->where(function (Builder $query) use ($windowStart): void {
                    $query->where('processed_at', '>=', $windowStart)
                        ->orWhere('created_at', '>=', $windowStart);
                })
                ->orderByDesc('processed_at')
                ->orderByDesc('id')
                ->get(),
            $user->locale,
        );

        $completedIds = CompanyNoticeAnalysisEvent::query()
            ->where('event_type', CompanyNoticeAnalysisEvent::TYPE_USER_UPDATED)
            ->where('created_at', '>=', $windowStart)
            ->whereHas('companyNoticeAnalysis.companyNoticeAnalysisRun', fn (Builder $query): Builder => $query->whereIn('company_id', $companyIds))
            ->where(function (Builder $query): void {
                $query
                    ->whereJsonContains('changes->compliance->new', true)
                    ->orWhereJsonContains('changes->confirmed_relevant->new', false);
            })
            ->pluck('company_notice_analysis_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $completed = $this->groupAnalyses(
            CompanyNoticeAnalysis::query()
                ->with([
                    'companyNoticeAnalysisRun.company',
                    'companyNoticeAnalysisRun.noticeAnalysisRun.scope.translations',
                    'noticeAnalysis.notice.dailyJournal',
                ])
                ->whereIn('id', $completedIds)
                ->orderByDesc('updated_at')
                ->get(),
            $user->locale,
        );

        return [
            'window_started_at' => $windowStart,
            'window_ended_at' => $now,
            'pending' => $pending,
            'new_relevant' => $newRelevant,
            'completed' => $completed,
            'pending_count' => $pending['count'],
            'new_relevant_count' => $newRelevant['count'],
            'completed_count' => $completed['count'],
            'should_send' => ($user->notify_if_pending_tasks && $pending['count'] > 0)
                || ($user->notify_if_new_relevant_notices && $newRelevant['count'] > 0),
        ];
    }

    private function resolveWindowStart(User $user, Carbon $now): Carbon
    {
        if ($user->last_notice_digest_sent_at) {
            return $user->last_notice_digest_sent_at->copy();
        }

        return match ($user->notice_digest_frequency) {
            User::NOTICE_DIGEST_DAILY => $now->copy()->startOfDay(),
            User::NOTICE_DIGEST_MONTHLY => $now->copy()->startOfMonth(),
            default => $now->copy()->startOfWeek(Carbon::MONDAY),
        };
    }

    /**
     * @param  Collection<int, CompanyNoticeAnalysis>  $analyses
     * @return array{count:int, companies: array<int, array{name:string, items: array<int, array<string,mixed>>}>}
     */
    private function groupAnalyses(Collection $analyses, ?string $locale): array
    {
        $companies = $analyses
            ->groupBy(fn (CompanyNoticeAnalysis $analysis): int => (int) $analysis->companyNoticeAnalysisRun->company_id)
            ->map(function (Collection $items) use ($locale): array {
                /** @var CompanyNoticeAnalysis $first */
                $first = $items->first();

                return [
                    'name' => $first->companyNoticeAnalysisRun?->company?->name ?? '—',
                    'items' => $items->map(fn (CompanyNoticeAnalysis $analysis): array => [
                        'id' => $analysis->id,
                        'title' => $analysis->noticeAnalysis?->notice?->title ?? '—',
                        'issue_date' => $analysis->noticeAnalysis?->notice?->dailyJournal?->issue_date?->toDateString(),
                        'scope' => $analysis->companyNoticeAnalysisRun?->noticeAnalysisRun?->scope?->name($locale) ?? '—',
                        'compliance_due_at' => $analysis->compliance_due_at?->toDateString(),
                        'url' => CompanyNoticeAnalysisResource::getUrl('edit', ['record' => $analysis]),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'count' => $analyses->count(),
            'companies' => $companies,
        ];
    }
}
